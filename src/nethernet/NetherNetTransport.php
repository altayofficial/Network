<?php

/*
 *
 *      _    _ _
 *     / \  | | |_ __ _ _   _
 *    / _ \ | | __/ _` | | | |
 *   / ___ \| | || (_| | |_| |
 *  /_/   \_\_|\__\__,_|\__, |
 *                       |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Original work by the PocketMine Team.
 * https://www.pocketmine.net/
 *
 * @author Altay Team
 * @link https://github.com/altayofficial
 */

declare(strict_types=1);

namespace altay\network\nethernet;

use altay\network\nethernet\discovery\DiscoveryCodec;
use altay\network\nethernet\discovery\DiscoveryMessagePacket;
use altay\network\nethernet\discovery\DiscoveryRequestPacket;
use altay\network\nethernet\discovery\DiscoveryResponsePacket;
use altay\network\nethernet\auth\ClientIdentityAssertion;
use altay\network\nethernet\auth\IdentityException;
use altay\network\nethernet\auth\ServerIdentity;
use altay\network\nethernet\sdp\AnswerRewriter;
use altay\network\nethernet\sdp\IceCandidate;
use altay\network\nethernet\sdp\SessionDescription;
use altay\network\nethernet\types\SignalErrorCode;
use altay\network\transport\NameableTransport;
use altay\network\transport\TransportException;
use altay\network\transport\TransportListener;
use altay\network\utils\Uint64;
use React\EventLoop\Loop;
use function React\Promise\set_rejection_handler;
use Webrtc\DataChannel\RTCDataChannel;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\SDP\RTCSessionDescription;
use Webrtc\Webrtc\Enum\ConnectionState;
use Webrtc\Webrtc\RTCPeerConnection;

final class NetherNetTransport implements NameableTransport{

	public const DISCOVERY_PORT = 7551;

	private const PENDING_NEGOTIATION_TIMEOUT = 15;
	private const MAINTENANCE_INTERVAL = 1;

	private ?\Socket $socket = null;
	private ?TransportListener $listener = null;
	private ?ServerIdentity $identity = null;

	/** @var array<string, array{connection: RTCPeerConnection, networkId: int, address: string, port: int, publicKey: ?string, createdAt: int}> */
	private array $pending = [];
	/** @var NetherNetSession[] */
	private array $sessions = [];

	private int $lastMaintenance = 0;

	public function __construct(
		private \Logger $logger,
		private int $networkId,
		private ServerData $serverData,
		private string $bindAddress = "0.0.0.0",
		private int $port = self::DISCOVERY_PORT,
		private bool $requireIdentity = false
	){}

	public function getName() : string{
		return "nethernet";
	}

	public function getNetworkId() : int{
		return $this->networkId;
	}

	public function getServerData() : ServerData{
		return $this->serverData;
	}

	public function getServerId() : ?int{
		//NetherNet identifies servers by their network ID, it has no separate RakNet-style server GUID
		return null;
	}

	public function setName(string $name) : void{
		$parts = explode(";", $name);
		if(count($parts) < 9 || $parts[0] !== "MCPE"){
			$this->serverData->serverName = $name;
			return;
		}
		$this->serverData->serverName = $parts[1];
		$this->serverData->levelName = $parts[7];
		$this->serverData->playerCount = (int) $parts[4];
		$this->serverData->maxPlayerCount = (int) $parts[5];
		$this->serverData->gameType = match($parts[8]){
			"Creative" => ServerData::GAME_TYPE_CREATIVE,
			"Adventure" => ServerData::GAME_TYPE_ADVENTURE,
			default => ServerData::GAME_TYPE_SURVIVAL
		};
	}

	public function start(TransportListener $listener) : void{
		if($this->socket !== null){
			throw new TransportException("NetherNet transport is already running");
		}
		$socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if($socket === false){
			throw new TransportException("Failed to create discovery socket: " . socket_strerror(socket_last_error()));
		}
		@socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
		@socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
		if(!@socket_bind($socket, $this->bindAddress, $this->port)){
			$error = socket_strerror(socket_last_error($socket));
			socket_close($socket);
			throw new TransportException("Failed to bind discovery socket to $this->bindAddress:$this->port: $error");
		}
		socket_set_nonblock($socket);
		$this->socket = $socket;
		$this->listener = $listener;
		$this->identity = ServerIdentity::generate();

		set_rejection_handler(function(\Throwable $reason) : void{
			$this->logger->debug("Ignoring unhandled WebRTC promise rejection: " . $reason->getMessage());
		});

		$this->logger->info("NetherNet transport listening for discovery on $this->bindAddress:$this->port (network ID $this->networkId)");
	}

	public function tick() : void{
		if($this->socket === null){
			return;
		}
		while(true){
			$buffer = "";
			$address = "";
			$port = 0;
			$received = @socket_recvfrom($this->socket, $buffer, 65535, 0, $address, $port);
			if($received === false || $received <= 0){
				break;
			}
			$this->handleDatagram($buffer, $address, $port);
		}

		Loop::futureTick(static function() : void{
			Loop::stop();
		});
		try{
			Loop::run();
		}catch(\Throwable $e){
			//an unhandled promise rejection from a dying peer connection must not kill the whole transport
			$this->logger->error("Error while running WebRTC event loop: " . $e->getMessage());
		}

		$now = time();
		if($now - $this->lastMaintenance >= self::MAINTENANCE_INTERVAL){
			$this->lastMaintenance = $now;
			$this->expireStalePending($now);
			$this->expireUnreadySessions($now);
			$this->reportBandwidth();
		}
	}

	private function expireStalePending(int $now) : void{
		foreach($this->pending as $connectionId => $entry){
			if($now - $entry["createdAt"] >= self::PENDING_NEGOTIATION_TIMEOUT){
				$this->logger->debug("Dropping stale pending negotiation $connectionId from " . $entry["address"] . ":" . $entry["port"]);
				$this->dropConnection($connectionId, "negotiation timed out", SignalErrorCode::NEGOTIATION_TIMEOUT_WAITING_FOR_ACCEPT);
			}
		}
	}

	/**
	 * A session leaves the pending list as soon as its first data channel arrives, so it is no
	 * longer covered by expireStalePending(). A peer that opens one channel and then stops would
	 * otherwise occupy a session slot forever, since the session never becomes ready.
	 */
	private function expireUnreadySessions(int $now) : void{
		foreach($this->sessions as $sessionId => $session){
			if(!$session->isOpenNotified() && $now - $session->getCreatedAt() >= self::PENDING_NEGOTIATION_TIMEOUT){
				$this->logger->debug("Dropping session $sessionId, its data channels did not open in time");
				$this->closeSession($sessionId, "data channels did not open in time");
			}
		}
	}

	private function reportBandwidth() : void{
		$sent = 0;
		$received = 0;
		foreach($this->sessions as $session){
			[$sessionSent, $sessionReceived] = $session->collectBandwidthDelta();
			$sent += $sessionSent;
			$received += $sessionReceived;
		}
		if(($sent !== 0 || $received !== 0) && $this->listener !== null){
			$this->listener->onBandwidthUpdate($this, $sent, $received);
		}
	}

	public function isSelfPacing() : bool{
		//the UDP socket is non-blocking and the React loop returns immediately, so the driving loop must pace this
		return false;
	}

	public function isRunning() : bool{
		return $this->socket !== null;
	}

	public function shutdown() : void{
		foreach($this->sessions as $session){
			$session->disconnect();
		}
		$this->sessions = [];
		foreach($this->pending as $entry){
			try{
				$entry["connection"]->close();
			}catch(\Throwable){

			}
		}
		$this->pending = [];

		//flush the teardown ticks the closes above scheduled (SCTP stream resets, DTLS close);
		//stray rejections during this are swallowed by the rejection handler set in start()
		Loop::futureTick(static function() : void{
			Loop::stop();
		});
		try{
			Loop::run();
		}catch(\Throwable $e){
			$this->logger->debug("Error while draining WebRTC loop on shutdown: " . $e->getMessage());
		}

		if($this->socket !== null){
			socket_close($this->socket);
			$this->socket = null;
		}
		set_rejection_handler(null);
		$this->listener = null;
	}

	public function getSession(int $connectionId) : ?NetherNetSession{
		return $this->sessions[$connectionId] ?? null;
	}

	private function handleDatagram(string $buffer, string $address, int $port) : void{
		$result = DiscoveryCodec::unmarshal($buffer);
		if($result === null){
			$this->logger->debug("Ignoring invalid discovery datagram from $address:$port (" . strlen($buffer) . " bytes)");
			return;
		}
		[$packet, $senderId] = $result;
		if($senderId === $this->networkId){
			return;
		}

		if($packet instanceof DiscoveryRequestPacket){
			$response = DiscoveryCodec::marshal(new DiscoveryResponsePacket($this->serverData->encode()), $this->networkId);
			$this->sendDatagram($response, $address, $port);
		}elseif($packet instanceof DiscoveryMessagePacket){
			if($packet->recipientId !== $this->networkId){
				$this->logger->debug("Ignoring discovery message from $address:$port intended for network " . $packet->recipientId);
				return;
			}
			if($packet->data === "" || $packet->data === "Ping"){
				//keep-alive sent by clients scanning the network, it carries no signal
				return;
			}
			$signal = Signal::fromString($packet->data);
			if($signal === null){
				$this->logger->debug("Invalid signal from $address:$port: " . substr($packet->data, 0, 64));
				return;
			}
			$this->logger->debug("Signal " . $signal->type . " from $address:$port (connection " . $signal->connectionId . ")");
			$this->handleSignal($signal, $senderId, $address, $port);
		}
	}

	private function handleSignal(Signal $signal, int $senderNetworkId, string $address, int $port) : void{
		switch($signal->type){
			case Signal::TYPE_OFFER:
				$this->handleOffer($signal, $senderNetworkId, $address, $port);
				break;
			case Signal::TYPE_CANDIDATE:
				$this->handleCandidate($signal);
				break;
			case Signal::TYPE_ERROR:
				$code = ctype_digit($signal->data) ? SignalErrorCode::tryFrom((int) $signal->data) : null;
				$this->dropConnection($signal->connectionId, "remote error: " . ($code !== null ? $code->name : $signal->data));
				break;
		}
	}

	private function handleOffer(Signal $signal, int $senderNetworkId, string $address, int $port) : void{
		$connectionId = $signal->connectionId;
		try{
			$sessionId = Uint64::toSignedInt($connectionId);
		}catch(\InvalidArgumentException){
			return;
		}
		if(isset($this->pending[$connectionId]) || isset($this->sessions[$sessionId])){
			return;
		}

		try{
			$assertion = ClientIdentityAssertion::fromSdp($signal->data);
			$assertion?->verify($signal->data);
		}catch(IdentityException $e){
			$this->logger->info("Rejecting connection $connectionId from $address:$port: invalid identity assertion: " . $e->getMessage());
			$this->sendError($connectionId, $senderNetworkId, $address, $port, SignalErrorCode::IDENTITY_VERIFICATION_FAILED);
			return;
		}
		if($assertion === null && $this->requireIdentity){
			$this->logger->info("Rejecting connection $connectionId from $address:$port: identity assertion required but not provided");
			$this->sendError($connectionId, $senderNetworkId, $address, $port, SignalErrorCode::IDENTITY_VERIFICATION_FAILED);
			return;
		}

		try{
			$connection = new RTCPeerConnection();
		}catch(\Throwable $e){
			$this->logger->error("Failed to create peer connection: " . $e->getMessage());
			$this->sendError($connectionId, $senderNetworkId, $address, $port, SignalErrorCode::FAILED_TO_CREATE_PEER_CONNECTION);
			return;
		}
		$this->logger->debug("Incoming NetherNet connection $connectionId from $address:$port (network ID $senderNetworkId)");
		$this->pending[$connectionId] = [
			"connection" => $connection,
			"networkId" => $senderNetworkId,
			"address" => $address,
			"port" => $port,
			"publicKey" => $assertion?->getPublicKeyBase64(),
			"createdAt" => time()
		];

		$connection->on("datachannel", function(RTCDataChannel $channel) use ($connectionId, $sessionId, $address, $port, $connection) : void{
			$this->handleDataChannel($connection, $channel, $connectionId, $sessionId, $address, $port);
		});

		//the aggregate connection state covers both the ICE and the DTLS transports. Without this a
		//peer that goes away mid-handshake, or whose ICE later fails, would sit around until the
		//pending negotiation times out - and an established session would never be torn down at all
		$connection->on("connectionstatechange", function() use ($connection, $connectionId) : void{
			$state = $connection->getConnectionState();
			if($state === ConnectionState::failed || $state === ConnectionState::closed){
				$this->dropConnection($connectionId, "peer connection " . $state->name);
			}
		});

		$offer = $signal->data;
		$connection->setRemoteDescription(new RTCSessionDescription($offer, "offer"))
			->then(function() use ($connection, $offer, $connectionId) : void{
				$this->addSessionLevelCandidates($connection, $offer, $connectionId);
			})
			->then(fn() => $connection->createAnswer())
			->then(fn(RTCSessionDescription $answer) => $connection->setLocalDescription($answer))
			->then(function() use ($connection, $connectionId, $senderNetworkId, $address, $port) : void{
				$local = $connection->getLocalDescription();
				if($local === null){
					$this->dropConnection($connectionId, "no local description", SignalErrorCode::FAILED_TO_SET_LOCAL_DESCRIPTION);
					return;
				}
				$sdp = AnswerRewriter::conform($local->getSdp());
				$this->logger->debug("Sending answer for connection $connectionId");
				$this->sendSignal(new Signal(Signal::TYPE_ANSWER, $connectionId, $this->withIdentityAttribute($sdp)), $senderNetworkId, $address, $port);
				$this->trickleCandidates($sdp, $connectionId, $senderNetworkId, $address, $port);
			})
			->catch(function(\Throwable $e) use ($connectionId) : void{
				$this->logger->error("NetherNet negotiation failed for connection $connectionId: " . $e->getMessage());
				$this->dropConnection($connectionId, "negotiation failed", SignalErrorCode::FAILED_TO_CREATE_ANSWER);
			});
	}

	/**
	 * Adds the candidates a peer placed above the first media description.
	 *
	 * setRemoteDescription() only picks up the ones attached to the media description, which is
	 * where they normally live. A peer that negotiates without trickle ICE may put them at the
	 * session level instead, and those would otherwise be dropped.
	 */
	private function addSessionLevelCandidates(RTCPeerConnection $connection, string $sdp, string $connectionId) : void{
		foreach(IceCandidate::parseAll(SessionDescription::sessionSection($sdp)) as $candidate){
			$this->addCandidate($connection, $candidate, $connectionId);
		}
	}

	private function handleCandidate(Signal $signal) : void{
		$entry = $this->pending[$signal->connectionId] ?? null;
		if($entry === null){
			return;
		}
		$candidate = IceCandidate::parse($signal->data);
		if($candidate === null){
			$this->logger->debug("Ignoring malformed ICE candidate for connection $signal->connectionId");
			return;
		}
		$this->addCandidate($entry["connection"], $candidate, $signal->connectionId);
	}

	private function addCandidate(RTCPeerConnection $connection, IceCandidate $candidate, string $connectionId) : void{
		try{
			$connection->addIceCandidate(RTCIceCandidate::parseSDP($candidate->toSdpValue()));
		}catch(\Throwable $e){
			$this->logger->debug("Ignoring invalid ICE candidate for connection $connectionId: " . $e->getMessage());
		}
	}

	private function handleDataChannel(RTCPeerConnection $connection, RTCDataChannel $channel, string $connectionId, int $sessionId, string $address, int $port) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session === null){
			$session = new NetherNetSession(
				$connection,
				$sessionId,
				$address,
				$port,
				function(string $payload) use ($sessionId) : void{
					$session = $this->sessions[$sessionId] ?? null;
					if($session !== null){
						$this->listener?->onPacketReceive($this, $session, $payload);
					}
				},
				function(string $reason) use ($sessionId) : void{
					$this->closeSession($sessionId, $reason);
				},
				function(int $receiptId) use ($sessionId) : void{
					$session = $this->sessions[$sessionId] ?? null;
					if($session !== null){
						$this->listener?->onPacketAck($this, $session, $receiptId);
					}
				}
			);
			$session->setAuthenticatedPublicKey($this->pending[$connectionId]["publicKey"] ?? null);
			$this->sessions[$sessionId] = $session;
			unset($this->pending[$connectionId]);
		}
		$this->logger->debug("Data channel \"" . $channel->getLabel() . "\" received for connection $connectionId");
		if(!$session->bindChannel($channel)){
			$this->logger->debug("Rejecting unexpected data channel \"" . $channel->getLabel() . "\" for connection $connectionId");
			$this->dropConnection($connectionId, "unexpected data channel", SignalErrorCode::DATA_CHANNEL_CLOSED);
			return;
		}

		$notifyOpen = function() use ($sessionId) : void{
			$session = $this->sessions[$sessionId] ?? null;
			if($session !== null && $session->isReady() && $session->markOpenNotified()){
				$this->listener?->onSessionOpen($this, $session);
			}
		};
		//both channels have to be open before the session is usable, and either of them may already
		//be open by the time its datachannel event fires
		if($session->isReady()){
			$notifyOpen();
		}else{
			$channel->on("open", $notifyOpen);
		}
	}

	private function closeSession(int $sessionId, string $reason) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session !== null){
			unset($this->sessions[$sessionId]);
			$session->disconnect();
			$this->listener?->onSessionClose($this, $session, $reason);
		}
	}

	private function dropConnection(string $connectionId, string $reason, ?SignalErrorCode $code = null) : void{
		$entry = $this->pending[$connectionId] ?? null;
		if($entry !== null){
			unset($this->pending[$connectionId]);
			if($code !== null){
				$this->sendError($connectionId, $entry["networkId"], $entry["address"], $entry["port"], $code);
			}
			try{
				$entry["connection"]->close();
			}catch(\Throwable){

			}
		}
		try{
			$this->closeSession(Uint64::toSignedInt($connectionId), $reason);
		}catch(\InvalidArgumentException){

		}
	}

	private function sendError(string $connectionId, int $recipientNetworkId, string $address, int $port, SignalErrorCode $code) : void{
		$this->sendSignal(new Signal(Signal::TYPE_ERROR, $connectionId, (string) $code->value), $recipientNetworkId, $address, $port);
	}

	private function sendSignal(Signal $signal, int $recipientNetworkId, string $address, int $port) : void{
		$datagram = DiscoveryCodec::marshal(new DiscoveryMessagePacket($recipientNetworkId, (string) $signal), $this->networkId);
		$this->sendDatagram($datagram, $address, $port);
	}

	private function sendDatagram(string $datagram, string $address, int $port) : void{
		if($this->socket !== null){
			@socket_sendto($this->socket, $datagram, strlen($datagram), 0, $address, $port);
		}
	}

	private function withIdentityAttribute(string $sdp) : string{
		$attribute = $this->identity?->createIdentityAttribute($sdp);
		if($attribute === null){
			return $sdp;
		}
		$position = strpos($sdp, "m=");
		if($position === false){
			return $sdp;
		}
		return substr($sdp, 0, $position) . "a=identity:$attribute\r\n" . substr($sdp, $position);
	}

	/**
	 * Signals each locally gathered candidate separately.
	 *
	 * The candidates cannot be copied straight out of the local description - vanilla only accepts
	 * the form the C++ WebRTC implementation produces, which carries a 'ufrag' and a per-candidate
	 * 'network-id' that an SDP attribute does not have.
	 */
	private function trickleCandidates(string $sdp, string $connectionId, int $recipientNetworkId, string $address, int $port) : void{
		$ufrag = SessionDescription::attribute($sdp, "ice-ufrag");
		if($ufrag === null){
			$this->logger->debug("Local description for connection $connectionId has no ice-ufrag, not signalling candidates");
			return;
		}
		foreach(IceCandidate::parseAll($sdp) as $networkId => $candidate){
			$this->sendSignal(new Signal(Signal::TYPE_CANDIDATE, $connectionId, $candidate->format($networkId, $ufrag)), $recipientNetworkId, $address, $port);
		}
	}
}
