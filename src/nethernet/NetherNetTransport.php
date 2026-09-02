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

use altay\network\nethernet\discovery\AddressBook;
use altay\network\nethernet\endpoint\EndpointClient;
use altay\network\nethernet\endpoint\EndpointHandler;
use altay\network\nethernet\endpoint\PlaintextSignallingServer;
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
use altay\network\transport\AddressBlockingTransport;
use altay\network\transport\NameableTransport;
use altay\network\transport\TransportException;
use altay\network\transport\TransportListener;
use altay\network\utils\Uint64;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Socket\SocketServer;
use function React\Promise\set_rejection_handler;
use Webrtc\DataChannel\RTCDataChannel;
use Webrtc\DataChannel\RTCDataChannelParameters;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\SDP\RTCSessionDescription;
use Webrtc\Webrtc\Enum\ConnectionState;
use Webrtc\Webrtc\RTCPeerConnection;

final class NetherNetTransport implements NameableTransport, AddressBlockingTransport{

	public const DISCOVERY_PORT = 7551;

	private const PENDING_NEGOTIATION_TIMEOUT = 15;
	private const MAINTENANCE_INTERVAL = 1;
	private const SIGNAL_SOCKET_BUFFER = 4 * 1024 * 1024;
	private const SIGNAL_RETRANSMIT_INTERVAL = 2;

	/**
	 * Negotiating a connection costs a DTLS handshake and an ICE agent, and nothing about an offer is
	 * authenticated, so both the total and the per-peer rate have to be capped or an anonymous peer
	 * can keep the transport thread busy enough to starve the players already on the server.
	 */
	private const MAX_PENDING_NEGOTIATIONS = 64;
	private const MAX_OFFERS_PER_ADDRESS = 8;
	private const OFFER_RATE_WINDOW = 10;
	private const MAX_REMOTE_CANDIDATES = 32;

	private const ENDPOINT_MAX_CONCURRENT_REQUESTS = 64;

	private ?\Socket $socket = null;
	private ?SocketServer $endpointSocket = null;
	private ?TransportListener $listener = null;
	private ?ServerIdentity $identity = null;
	private bool $running = false;
	private ?DtlsCertificateFiles $certificateFiles = null;
	private ?string $discoveryResponse = null;
	/** @var array<string, int> address => unix time the block expires */
	private array $blockedAddresses = [];
	/** @var array<string, array{count: int, since: int}> address => offers seen in the current window */
	private array $offerRates = [];

	/** @var array<string, array{connection: RTCPeerConnection, networkId: int, address: string, port: int, publicKey: ?string, createdAt: int, outgoing: bool, sink: SignalSink, offer: ?string, answer: ?string, lastSignalAt: float}> */
	private array $pending = [];
	/** @var NetherNetSession[] */
	private array $sessions = [];

	private AddressBook $addressBook;
	private int $lastMaintenance = 0;

	public function __construct(
		private \Logger $logger,
		private int $networkId,
		private ServerData $serverData,
		private string $bindAddress = "0.0.0.0",
		private int $port = self::DISCOVERY_PORT,
		private bool $requireIdentity = false,
		private ?Credentials $credentials = null,
		private ?string $endpointAddress = null,
		private ?string $identityKeyPath = null
	){
		$this->addressBook = new AddressBook();
	}

	public function setCredentials(?Credentials $credentials) : void{
		$this->credentials = $credentials;
	}

	public function getName() : string{
		return "nethernet";
	}

	public function getAddressBook() : AddressBook{
		return $this->addressBook;
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
		$this->discoveryResponse = null;
		$parts = explode(";", $name);
		if(count($parts) < 9 || $parts[0] !== "MCPE"){
			$this->serverData->serverName = $name;
			return;
		}
		$this->serverData->serverName = $parts[1];
		$this->serverData->protocol = (int) $parts[2];
		$this->serverData->gameVersion = $parts[3];
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
		if($this->running){
			throw new TransportException("NetherNet transport is already running");
		}
		$this->socket = $this->createDiscoverySocket();
		if($this->socket === null && $this->endpointAddress === null){
			throw new TransportException("NetherNet transport has no way to receive signalling: discovery is unavailable and no endpoint is configured");
		}
		$this->listener = $listener;
		$this->identity = $this->makeIdentity();
		try{
			$this->certificateFiles = DtlsCertificateFiles::generate();
		}catch(\RuntimeException $e){
			//a per-connection key pair still works, it is just much more expensive to hand out
			$this->logger->warning("Failed to prepare a shared DTLS certificate: " . $e->getMessage());
		}
		$this->running = true;

		set_rejection_handler(function(\Throwable $reason) : void{
			$this->logger->debug("Ignoring unhandled WebRTC promise rejection: " . $reason->getMessage());
		});

		if($this->socket !== null){
			$this->logger->info("NetherNet transport listening for discovery on $this->bindAddress:$this->port");
		}

		if($this->endpointAddress !== null){
			$this->startEndpoint($this->endpointAddress);
		}
	}

	/**
	 * Returns null if the discovery port is unusable. That only costs the server its LAN
	 * advertisement, so the transport carries on with whatever signalling is left.
	 *
	 * @throws TransportException
	 */
	private function createDiscoverySocket() : ?\Socket{
		$socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if($socket === false){
			throw new TransportException("Failed to create discovery socket: " . socket_strerror(socket_last_error()));
		}
		@socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
		@socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
		//an SDP offer is several kilobytes, so the default receive buffer only holds a couple of
		//dozen of them. A join burst overflows it, and since signalling has no retransmission of its
		//own every dropped offer stranded a connection until it timed out
		@socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, self::SIGNAL_SOCKET_BUFFER);
		@socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, self::SIGNAL_SOCKET_BUFFER);
		if(!@socket_bind($socket, $this->bindAddress, $this->port)){
			$error = socket_strerror(socket_last_error($socket));
			socket_close($socket);
			$this->logger->warning("Failed to bind NetherNet discovery socket to $this->bindAddress:$this->port: $error");
			$this->logger->warning("The server won't show up on the Friends tab. Free port $this->port and restart to fix it.");
			return null;
		}
		socket_set_nonblock($socket);
		return $socket;
	}

	private function makeIdentity() : ServerIdentity{
		if($this->identityKeyPath !== null){
			try{
				return ServerIdentity::loadOrCreate($this->identityKeyPath);
			}catch(\RuntimeException $e){
				$this->logger->warning("Failed to use the NetherNet identity key at $this->identityKeyPath: " . $e->getMessage());
				$this->logger->warning("Falling back to a temporary identity, which changes on every restart.");
			}
		}
		return ServerIdentity::generate();
	}

	/**
	 * @throws TransportException
	 */
	private function startEndpoint(string $address) : void{
		try{
			$socket = new SocketServer($address);
		}catch(\RuntimeException | \InvalidArgumentException $e){
			throw new TransportException("Failed to bind endpoint signalling socket to $address: " . $e->getMessage(), 0, $e);
		}
		//the body limit the handler enforces has to be applied while buffering as well, or React
		//would happily hold post_max_size worth of offer per request before the handler ever runs
		$server = new HttpServer(
			new LimitConcurrentRequestsMiddleware(self::ENDPOINT_MAX_CONCURRENT_REQUESTS),
			new RequestBodyBufferMiddleware(EndpointHandler::MAX_BODY_LENGTH),
			new EndpointHandler($this, $this->logger)
		);
		$server->on("error", function(\Throwable $e) : void{
			$this->logger->debug("Endpoint signalling error: " . $e->getMessage());
		});
		$server->listen(new PlaintextSignallingServer($socket));
		$this->endpointSocket = $socket;
	}

	public function tick() : void{
		if(!$this->running){
			return;
		}
		while($this->socket !== null){
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
			$this->retransmitPendingSignals();
			$this->expireStalePending($now);
			$this->expireUnreadySessions($now);
			$this->expireRateLimits($now);
			$this->addressBook->expire($now);
			$this->reportBandwidth();
		}
	}

	/**
	 * NetherNet signalling is a bare datagram exchange, so a dropped offer or answer used to strand
	 * the negotiation until it timed out. Repeating the last thing sent, until the negotiation moves
	 * on or expires, is enough to recover: a repeated offer is answered from cache, and a repeated
	 * answer is applied to a connection that is still waiting for one.
	 */
	private function retransmitPendingSignals() : void{
		$now = microtime(true);
		foreach($this->pending as $connectionId => $entry){
			if($now - $entry["lastSignalAt"] < self::SIGNAL_RETRANSMIT_INTERVAL){
				continue;
			}
			$connectionId = (string) $connectionId;
			if($entry["outgoing"]){
				if($entry["offer"] === null){
					continue;
				}
				$this->logger->debug("Repeating offer for connection $connectionId, no answer yet");
				$entry["sink"]->write(new Signal(Signal::TYPE_OFFER, $connectionId, $entry["offer"]));
			}else{
				if($entry["answer"] === null){
					continue;
				}
				$this->logger->debug("Repeating answer for connection $connectionId, the peer has not connected yet");
				$entry["sink"]->write(new Signal(Signal::TYPE_ANSWER, $connectionId, $entry["answer"]));
			}
			$this->pending[$connectionId]["lastSignalAt"] = $now;
		}
	}

	private function expireStalePending(int $now) : void{
		foreach($this->pending as $connectionId => $entry){
			if($now - $entry["createdAt"] >= self::PENDING_NEGOTIATION_TIMEOUT){
				$connectionId = (string) $connectionId;
				$this->logger->debug("Dropping stale pending negotiation $connectionId from " . $entry["address"] . ":" . $entry["port"]);
				$this->dropConnection($connectionId, "negotiation timed out", SignalErrorCode::NEGOTIATION_TIMEOUT_WAITING_FOR_ACCEPT);
			}
		}
	}

	/**
	 * Source addresses are trivially spoofed on the discovery socket, so the bookkeeping they create
	 * has to be thrown away as soon as it stops meaning anything - otherwise it is a memory leak with
	 * a remote trigger.
	 */
	private function expireRateLimits(int $now) : void{
		foreach($this->offerRates as $address => $entry){
			if($now - $entry["since"] >= self::OFFER_RATE_WINDOW){
				unset($this->offerRates[$address]);
			}
		}
		foreach($this->blockedAddresses as $address => $until){
			if($until <= $now){
				unset($this->blockedAddresses[$address]);
			}
		}
	}

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
		return $this->running;
	}

	public function shutdown() : void{
		if($this->endpointSocket !== null){
			$this->endpointSocket->close();
			$this->endpointSocket = null;
		}
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
		$this->certificateFiles?->delete();
		$this->certificateFiles = null;
		set_rejection_handler(null);
		$this->listener = null;
		$this->running = false;
	}

	public function getSession(int $connectionId) : ?NetherNetSession{
		return $this->sessions[$connectionId] ?? null;
	}

	public function blockAddress(string $address, int $timeout = 300) : void{
		if($address === ""){
			return;
		}
		$until = $timeout < 0 ? PHP_INT_MAX : time() + $timeout;
		if(($this->blockedAddresses[$address] ?? 0) >= $until){
			return;
		}
		$this->blockedAddresses[$address] = $until;
		$this->logger->notice("Blocked $address" . ($timeout < 0 ? " forever" : " for $timeout seconds"));

		foreach($this->pending as $connectionId => $entry){
			if($entry["address"] === $address){
				$this->dropConnection((string) $connectionId, "address blocked");
			}
		}
		foreach($this->sessions as $sessionId => $session){
			if($session->getAddress() === $address){
				$this->closeSession($sessionId, "address blocked");
			}
		}
	}

	public function unblockAddress(string $address) : void{
		unset($this->blockedAddresses[$address]);
		$this->logger->debug("Unblocked $address");
	}

	public function isBlocked(string $address) : bool{
		$until = $this->blockedAddresses[$address] ?? null;
		if($until === null){
			return false;
		}
		if($until > time()){
			return true;
		}
		unset($this->blockedAddresses[$address]);
		return false;
	}

	/**
	 * Offers are unauthenticated and each one costs a peer connection, so a peer that asks for more
	 * than its share within the window is turned away until the window rolls over.
	 */
	private function withinOfferRate(string $address) : bool{
		if($address === ""){
			return true;
		}
		$now = time();
		$entry = $this->offerRates[$address] ?? null;
		if($entry === null || $now - $entry["since"] >= self::OFFER_RATE_WINDOW){
			$this->offerRates[$address] = ["count" => 1, "since" => $now];
			return true;
		}
		if($entry["count"] >= self::MAX_OFFERS_PER_ADDRESS){
			return false;
		}
		$this->offerRates[$address]["count"]++;
		return true;
	}

	private function handleDatagram(string $buffer, string $address, int $port) : void{
		if($this->isBlocked($address)){
			return;
		}
		$result = DiscoveryCodec::unmarshal($buffer);
		if($result === null){
			$hexPrefix = bin2hex(substr($buffer, 0, 16));
			$this->logger->debug("Ignoring invalid discovery datagram from $address:$port (" . strlen($buffer) . " bytes, hex prefix $hexPrefix)");
			return;
		}
		[$packet, $senderId] = $result;
		if($senderId === $this->networkId){
			return;
		}
		$this->addressBook->remember($senderId, $address, $port, time());

		if($packet instanceof DiscoveryRequestPacket){
			//every client on the network asks for this every couple of seconds, and the answer only
			//changes when the server data does, so it is encoded once and kept
			$this->discoveryResponse ??= DiscoveryCodec::marshal(new DiscoveryResponsePacket($this->serverData->encode()), $this->networkId);
			$this->sendDatagram($this->discoveryResponse, $address, $port);
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
		//an offer opens a connection, everything else acts on one that already exists - and since
		//signalling datagrams carry no authentication at all, anyone who has seen a connection ID on
		//the wire could otherwise tear down or redirect somebody else's connection
		if($signal->type !== Signal::TYPE_OFFER && !$this->signalCameFromPeer($signal->connectionId, $address, $port)){
			$this->logger->debug("Ignoring " . $signal->type . " for connection $signal->connectionId from unrelated address $address:$port");
			return;
		}

		switch($signal->type){
			case Signal::TYPE_OFFER:
				$this->handleOffer($signal, $senderNetworkId, $address, $port);
				break;
			case Signal::TYPE_ANSWER:
				$this->handleAnswer($signal);
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

	/**
	 * Whether a signalling datagram came from the peer the connection belongs to. Connections
	 * negotiated over the HTTP endpoint have no signalling address, so nothing on the discovery
	 * socket may speak for them.
	 */
	private function signalCameFromPeer(string $connectionId, string $address, int $port) : bool{
		$entry = $this->pending[$connectionId] ?? null;
		if($entry !== null){
			return $entry["address"] === $address && $entry["port"] === $port;
		}
		try{
			$sessionId = Uint64::toSignedInt($connectionId);
		}catch(\InvalidArgumentException){
			return false;
		}
		$session = $this->sessions[$sessionId] ?? null;
		return $session !== null && $session->getAddress() === $address && $session->getPort() === $port;
	}

	private function handleOffer(Signal $signal, int $senderNetworkId, string $address, int $port) : void{
		$this->acceptOffer($signal, $senderNetworkId, $address, $port, new DatagramSignalSink(
			fn(Signal $out, int $networkId, string $host, int $peerPort) => $this->sendSignal($out, $networkId, $host, $peerPort),
			$senderNetworkId,
			$address,
			$port
		));
	}

	public function acceptOffer(Signal $signal, int $senderNetworkId, string $address, int $port, SignalSink $sink) : void{
		$connectionId = $signal->connectionId;
		try{
			$sessionId = Uint64::toSignedInt($connectionId);
		}catch(\InvalidArgumentException){
			return;
		}
		$existing = $this->pending[$connectionId] ?? null;
		if($existing !== null){
			//the peer only repeats an offer when it did not get the answer, so send that answer again
			//rather than dropping the retry on the floor
			if($existing["answer"] !== null){
				$this->logger->debug("Repeating answer for connection $connectionId, the peer re-sent its offer");
				$existing["sink"]->write(new Signal(Signal::TYPE_ANSWER, $connectionId, $existing["answer"]));
			}
			return;
		}
		if(isset($this->sessions[$sessionId])){
			return;
		}
		if($this->isBlocked($address)){
			return;
		}
		if(count($this->pending) >= self::MAX_PENDING_NEGOTIATIONS){
			$this->logger->debug("Rejecting connection $connectionId from $address:$port: " . count($this->pending) . " negotiations already in flight");
			$sink->write(self::errorSignal($connectionId, SignalErrorCode::FAILED_TO_CREATE_PEER_CONNECTION));
			return;
		}
		if(!$this->withinOfferRate($address)){
			$this->logger->debug("Rejecting connection $connectionId from $address:$port: too many offers in the last " . self::OFFER_RATE_WINDOW . " seconds");
			$sink->write(self::errorSignal($connectionId, SignalErrorCode::FAILED_TO_CREATE_PEER_CONNECTION));
			return;
		}

		try{
			$assertion = ClientIdentityAssertion::fromSdp($signal->data);
			$assertion?->verify($signal->data);
		}catch(IdentityException $e){
			$this->logger->info("Rejecting connection $connectionId from $address:$port: invalid identity assertion: " . $e->getMessage());
			$sink->write(self::errorSignal($connectionId, SignalErrorCode::IDENTITY_VERIFICATION_FAILED));
			return;
		}
		if($assertion === null && $this->requireIdentity){
			$this->logger->info("Rejecting connection $connectionId from $address:$port: identity assertion required but not provided");
			$sink->write(self::errorSignal($connectionId, SignalErrorCode::IDENTITY_VERIFICATION_FAILED));
			return;
		}

		try{
			$connection = $this->createPeerConnection();
		}catch(\Throwable $e){
			$this->logger->error("Failed to create peer connection: " . $e->getMessage());
			$sink->write(self::errorSignal($connectionId, SignalErrorCode::FAILED_TO_CREATE_PEER_CONNECTION));
			return;
		}
		$this->logger->debug("Incoming NetherNet connection $connectionId from $address:$port (network ID $senderNetworkId)");
		$this->pending[$connectionId] = [
			"connection" => $connection,
			"networkId" => $senderNetworkId,
			"address" => $address,
			"port" => $port,
			"publicKey" => $assertion?->getPublicKeyBase64(),
			"createdAt" => time(),
			"outgoing" => false,
			"sink" => $sink,
			"offer" => null,
			"answer" => null,
			"lastSignalAt" => microtime(true)
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
				$this->addOfferedCandidates($connection, $offer, $connectionId);
			})
			->then(fn() => $connection->createAnswer())
			->then(fn(RTCSessionDescription $answer) => $connection->setLocalDescription($answer))
			->then(function() use ($connection, $connectionId, $sink) : void{
				$local = $connection->getLocalDescription();
				if($local === null){
					$this->dropConnection($connectionId, "no local description", SignalErrorCode::FAILED_TO_SET_LOCAL_DESCRIPTION);
					return;
				}
				$sdp = AnswerRewriter::conform($local->getSdp());
				$this->logger->debug("Sending answer for connection $connectionId");
				$answer = $this->withIdentityAttribute($sdp);
				//kept so a repeated offer can be answered without renegotiating from scratch
				if(isset($this->pending[$connectionId])){
					$this->pending[$connectionId]["answer"] = $answer;
					$this->pending[$connectionId]["lastSignalAt"] = microtime(true);
				}
				$sink->write(new Signal(Signal::TYPE_ANSWER, $connectionId, $answer));
				$this->trickleCandidates($sdp, $connectionId, $sink);
			})
			->catch(function(\Throwable $e) use ($connectionId) : void{
				$this->logger->error("NetherNet negotiation failed for connection $connectionId: " . $e->getMessage());
				$this->dropConnection($connectionId, "negotiation failed", SignalErrorCode::FAILED_TO_CREATE_ANSWER);
			});
	}

	private function addOfferedCandidates(RTCPeerConnection $connection, string $sdp, string $connectionId) : void{
		$added = 0;
		foreach(IceCandidate::parseAll($sdp) as $candidate){
			if($added >= self::MAX_REMOTE_CANDIDATES){
				$this->logger->debug("Ignoring the remaining candidates of connection $connectionId, it offered more than " . self::MAX_REMOTE_CANDIDATES);
				break;
			}
			if($this->addCandidate($connection, $candidate, $connectionId)){
				$added++;
			}
		}
	}

	private function connectionFor(string $connectionId) : ?RTCPeerConnection{
		$entry = $this->pending[$connectionId] ?? null;
		if($entry !== null){
			return $entry["connection"];
		}
		try{
			$sessionId = Uint64::toSignedInt($connectionId);
		}catch(\InvalidArgumentException){
			return null;
		}
		return ($this->sessions[$sessionId] ?? null)?->getConnection();
	}

	private function handleCandidate(Signal $signal) : void{
		$connection = $this->connectionFor($signal->connectionId);
		if($connection === null){
			return;
		}
		$candidate = IceCandidate::parse($signal->data);
		if($candidate === null){
			$this->logger->debug("Ignoring malformed ICE candidate for connection $signal->connectionId");
			return;
		}
		$this->addCandidate($connection, $candidate, $signal->connectionId);
	}

	private function addCandidate(RTCPeerConnection $connection, IceCandidate $candidate, string $connectionId) : bool{
		//a candidate names a host this server is about to send STUN checks to, and the peer that
		//offered it is not authenticated, so it may not point at the loopback or link-local ranges
		if(!$candidate->hasConnectableAddress()){
			$this->logger->debug("Ignoring candidate with unusable address " . $candidate->address . " for connection $connectionId");
			return false;
		}
		try{
			$parsed = RTCIceCandidate::parseSDP($candidate->toSdpValue());
			//a candidate signalled on its own carries no media section, but the library insists on
			//knowing which one it belongs to. NetherNet only ever negotiates the one, mid 0
			$parsed->setSdpMid(0);
			$parsed->setSdpMLineIndex(0);
			$connection->addIceCandidate($parsed);
			return true;
		}catch(\Throwable $e){
			$this->logger->debug("Ignoring invalid ICE candidate for connection $connectionId: " . $e->getMessage());
			return false;
		}
	}

	private function openSession(RTCPeerConnection $connection, string $connectionId, int $sessionId, string $address, int $port) : NetherNetSession{
		$session = $this->sessions[$sessionId] ?? null;
		if($session !== null){
			return $session;
		}

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
		//an outgoing connection has its session before it has an answer, and the pending entry is
		//what routes that answer back to the right peer connection - it is dropped in handleAnswer()
		if(($this->pending[$connectionId]["outgoing"] ?? false) === false){
			unset($this->pending[$connectionId]);
		}
		return $session;
	}

	private function handleDataChannel(RTCPeerConnection $connection, RTCDataChannel $channel, string $connectionId, int $sessionId, string $address, int $port) : void{
		$session = $this->openSession($connection, $connectionId, $sessionId, $address, $port);
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
				$session->flushPendingPackets();
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
				$entry["sink"]->write(self::errorSignal($connectionId, $code));
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

	/**
	 * @throws TransportException
	 */
	public function dial(int $networkId) : NetherNetSession{
		$target = $this->addressBook->lookup($networkId);
		if($target === null){
			throw new TransportException("Network $networkId has not been discovered");
		}
		[$address, $port] = $target;

		return $this->startDial($networkId, $address, $port, fn() => new DatagramSignalSink(
			fn(Signal $out, int $recipient, string $host, int $peerPort) => $this->sendSignal($out, $recipient, $host, $peerPort),
			$networkId,
			$address,
			$port
		));
	}

	/**
	 * @throws TransportException
	 */
	public function dialEndpoint(string $baseUrl, ?EndpointClient $client = null) : NetherNetSession{
		$client ??= new EndpointClient($this->networkId);

		return $this->startDial($this->networkId, "", 0, fn(string $connectionId) => new CallbackSignalSink(
			function(Signal $signal) use ($client, $baseUrl, $connectionId) : void{
				if($signal->type !== Signal::TYPE_OFFER){
					//candidates already travel inside the offer, and there is nowhere to send an error
					return;
				}
				$client->offer($baseUrl, $signal->data)->then(
					function(string $answer) use ($connectionId) : void{
						$this->handleAnswer(new Signal(Signal::TYPE_ANSWER, $connectionId, $answer));
					},
					function(\Throwable $e) use ($connectionId) : void{
						$this->logger->error("Endpoint dial failed for connection $connectionId: " . $e->getMessage());
						$this->dropConnection($connectionId, "endpoint rejected the offer");
					}
				);
			}
		));
	}

	/**
	 * @param \Closure(string) : SignalSink $makeSink
	 * @throws TransportException
	 */
	private function startDial(int $networkId, string $address, int $port, \Closure $makeSink) : NetherNetSession{
		if(!$this->running){
			throw new TransportException("NetherNet transport is not running");
		}

		//the dialling side owns the connection ID, and the session ID is derived from it exactly as
		//it is for an inbound connection so both directions agree on how sessions are keyed
		$connectionId = (string) random_int(0, PHP_INT_MAX);
		$sessionId = Uint64::toSignedInt($connectionId);

		try{
			$connection = $this->createPeerConnection();
		}catch(\Throwable $e){
			throw new TransportException("Failed to create peer connection: " . $e->getMessage(), 0, $e);
		}

		$sink = $makeSink($connectionId);
		$this->pending[$connectionId] = [
			"connection" => $connection,
			"networkId" => $networkId,
			"address" => $address,
			"port" => $port,
			"publicKey" => null,
			"createdAt" => time(),
			"outgoing" => true,
			"sink" => $sink,
			"offer" => null,
			"answer" => null,
			"lastSignalAt" => microtime(true)
		];
		$connection->on("connectionstatechange", function() use ($connection, $connectionId) : void{
			$state = $connection->getConnectionState();
			if($state === ConnectionState::failed || $state === ConnectionState::closed){
				$this->dropConnection($connectionId, "peer connection " . $state->name);
			}
		});

		$session = $this->openSession($connection, $connectionId, $sessionId, $address, $port);
		//the remote peer never opens channels on a connection it did not dial, so both are created here
		foreach([
			new RTCDataChannelParameters(label: NetherNetSession::RELIABLE_CHANNEL, ordered: true),
			new RTCDataChannelParameters(label: NetherNetSession::UNRELIABLE_CHANNEL, maxRetransmits: 0)
		] as $parameters){
			$channel = $connection->createDataChannel($parameters);
			$session->bindChannel($channel);
			$channel->on("open", function() use ($sessionId) : void{
				$session = $this->sessions[$sessionId] ?? null;
				if($session !== null && $session->isReady() && $session->markOpenNotified()){
					$this->listener?->onSessionOpen($this, $session);
					$session->flushPendingPackets();
				}
			});
		}

		$connection->createOffer()
			->then(fn(RTCSessionDescription $offer) => $connection->setLocalDescription($offer))
			->then(function() use ($connection, $connectionId, $networkId, $sink) : void{
				$local = $connection->getLocalDescription();
				if($local === null){
					$this->dropConnection($connectionId, "no local description", SignalErrorCode::FAILED_TO_SET_LOCAL_DESCRIPTION);
					return;
				}
				$sdp = AnswerRewriter::conform($local->getSdp());
				$this->logger->debug("Sending offer for connection $connectionId to network $networkId");
				$offer = $this->withIdentityAttribute($sdp);
				//kept so the offer can be repeated if no answer comes back
				if(isset($this->pending[$connectionId])){
					$this->pending[$connectionId]["offer"] = $offer;
					$this->pending[$connectionId]["lastSignalAt"] = microtime(true);
				}
				$sink->write(new Signal(Signal::TYPE_OFFER, $connectionId, $offer));
				$this->trickleCandidates($sdp, $connectionId, $sink);
			})
			->catch(function(\Throwable $e) use ($connectionId) : void{
				$this->logger->error("NetherNet dial failed for connection $connectionId: " . $e->getMessage());
				$this->dropConnection($connectionId, "dial failed", SignalErrorCode::FAILED_TO_CREATE_OFFER);
			});

		return $session;
	}

	private function handleAnswer(Signal $signal) : void{
		$entry = $this->pending[$signal->connectionId] ?? null;
		if($entry === null || !$entry["outgoing"]){
			$this->logger->debug("Ignoring unsolicited answer for connection $signal->connectionId");
			return;
		}

		try{
			$assertion = ClientIdentityAssertion::fromSdp($signal->data);
			$assertion?->verify($signal->data);
		}catch(IdentityException $e){
			$this->logger->info("Rejecting answer for connection $signal->connectionId: invalid identity assertion: " . $e->getMessage());
			$this->dropConnection($signal->connectionId, "invalid server identity", SignalErrorCode::IDENTITY_VERIFICATION_FAILED);
			return;
		}
		if($assertion === null && $this->requireIdentity){
			$this->logger->info("Rejecting answer for connection $signal->connectionId: identity assertion required but not provided");
			$this->dropConnection($signal->connectionId, "missing server identity", SignalErrorCode::IDENTITY_VERIFICATION_FAILED);
			return;
		}
		($this->sessions[Uint64::toSignedInt($signal->connectionId)] ?? null)?->setAuthenticatedPublicKey($assertion?->getPublicKeyBase64());

		$connection = $entry["connection"];
		$answer = $signal->data;
		//the negotiation is over; from here the session's own readiness deadline applies
		unset($this->pending[$signal->connectionId]);
		$connection->setRemoteDescription(new RTCSessionDescription($answer, "answer"))
			->then(function() use ($connection, $answer, $signal) : void{
				$this->addOfferedCandidates($connection, $answer, $signal->connectionId);
			})
			->catch(function(\Throwable $e) use ($signal) : void{
				$this->logger->error("Failed to apply answer for connection $signal->connectionId: " . $e->getMessage());
				$this->dropConnection($signal->connectionId, "invalid answer", SignalErrorCode::FAILED_TO_SET_REMOTE_DESCRIPTION);
			});
	}

	private function createPeerConnection() : RTCPeerConnection{
		if($this->credentials === null || $this->credentials->isExpired()){
			if($this->credentials !== null){
				$this->logger->debug("Discarding expired NetherNet credentials");
				$this->credentials = null;
			}
			//without an explicit list the library falls back to a public STUN server, which means an
			//internet round trip per connection just to learn a reflexive candidate that a LAN peer
			//can never use. Signalling here is local, so host candidates are all that is wanted.
			return new RTCPeerConnection($this->withSharedCertificate(["iceServers" => []]));
		}
		return new RTCPeerConnection($this->withSharedCertificate($this->credentials->toPeerConnectionConfiguration()));
	}

	/**
	 * @param mixed[] $configuration
	 * @return mixed[]
	 */
	private function withSharedCertificate(array $configuration) : array{
		if($this->certificateFiles !== null){
			$configuration["certificatePath"] = $this->certificateFiles->certificatePath;
			$configuration["privateKeyPath"] = $this->certificateFiles->privateKeyPath;
		}
		return $configuration;
	}

	public function signalNetwork(Signal $signal, int $recipientNetworkId) : bool{
		$target = $this->addressBook->lookup($recipientNetworkId);
		if($target === null){
			return false;
		}
		[$address, $port] = $target;
		$this->sendSignal($signal, $recipientNetworkId, $address, $port);
		return true;
	}

	private static function errorSignal(string $connectionId, SignalErrorCode $code) : Signal{
		return new Signal(Signal::TYPE_ERROR, $connectionId, (string) $code->value);
	}

	private function sendSignal(Signal $signal, int $recipientNetworkId, string $address, int $port) : void{
		$datagram = DiscoveryCodec::marshal(new DiscoveryMessagePacket($recipientNetworkId, (string) $signal), $this->networkId);
		$this->sendDatagram($datagram, $address, $port);
	}

	private function sendDatagram(string $datagram, string $address, int $port) : void{
		if($this->socket !== null){
			$sent = @socket_sendto($this->socket, $datagram, strlen($datagram), 0, $address, $port);
			if($sent !== strlen($datagram)){
				//a short or failed write loses a whole signal, and the peer has no way to notice
				$this->logger->debug("Failed to send " . strlen($datagram) . " byte signalling datagram to $address:$port: " . socket_strerror(socket_last_error($this->socket)));
			}
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

	private function trickleCandidates(string $sdp, string $connectionId, SignalSink $sink) : void{
		$ufrag = SessionDescription::attribute($sdp, "ice-ufrag");
		if($ufrag === null){
			$this->logger->debug("Local description for connection $connectionId has no ice-ufrag, not signalling candidates");
			return;
		}
		foreach(IceCandidate::parseAll($sdp) as $networkId => $candidate){
			$sink->write(new Signal(Signal::TYPE_CANDIDATE, $connectionId, $candidate->format($networkId, $ufrag)));
		}
	}
}
