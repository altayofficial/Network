<?php

declare(strict_types=1);

namespace altay\network\nethernet;

use altay\network\nethernet\discovery\DiscoveryCodec;
use altay\network\nethernet\discovery\DiscoveryMessagePacket;
use altay\network\nethernet\discovery\DiscoveryRequestPacket;
use altay\network\nethernet\discovery\DiscoveryResponsePacket;
use altay\network\transport\Transport;
use altay\network\transport\TransportException;
use altay\network\transport\TransportListener;
use React\EventLoop\Loop;
use Webrtc\DataChannel\RTCDataChannel;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\SDP\RTCSessionDescription;
use Webrtc\Webrtc\RTCPeerConnection;

final class NetherNetTransport implements Transport{

	public const DISCOVERY_PORT = 7551;

	private ?\Socket $socket = null;
	private ?TransportListener $listener = null;

	/** @var array<int, array{connection: RTCPeerConnection, networkId: int, address: string, port: int}> */
	private array $pending = [];
	/** @var NetherNetSession[] */
	private array $sessions = [];

	public function __construct(
		private \Logger $logger,
		private int $networkId,
		private ServerData $serverData,
		private string $bindAddress = "0.0.0.0",
		private int $port = self::DISCOVERY_PORT
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
		Loop::run();
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
		if($this->socket !== null){
			socket_close($this->socket);
			$this->socket = null;
		}
		$this->listener = null;
	}

	public function getSession(int $connectionId) : ?NetherNetSession{
		return $this->sessions[$connectionId] ?? null;
	}

	private function handleDatagram(string $buffer, string $address, int $port) : void{
		$result = DiscoveryCodec::unmarshal($buffer);
		if($result === null){
			return;
		}
		[$packet, $senderId] = $result;
		if($senderId === $this->networkId){
			return;
		}

		if($packet instanceof DiscoveryRequestPacket){
			$response = DiscoveryCodec::marshal(new DiscoveryResponsePacket($this->serverData->encode()), $this->networkId);
			$this->sendDatagram($response, $address, $port);
		}elseif($packet instanceof DiscoveryMessagePacket && $packet->recipientId === $this->networkId){
			$signal = Signal::fromString($packet->data);
			if($signal !== null){
				$this->handleSignal($signal, $senderId, $address, $port);
			}
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
				$this->dropConnection($signal->connectionId, "remote error: " . $signal->data);
				break;
		}
	}

	private function handleOffer(Signal $signal, int $senderNetworkId, string $address, int $port) : void{
		$connectionId = $signal->connectionId;
		if(isset($this->pending[$connectionId]) || isset($this->sessions[$connectionId])){
			return;
		}

		try{
			$connection = new RTCPeerConnection();
		}catch(\Throwable $e){
			$this->logger->error("Failed to create peer connection: " . $e->getMessage());
			return;
		}
		$this->pending[$connectionId] = [
			"connection" => $connection,
			"networkId" => $senderNetworkId,
			"address" => $address,
			"port" => $port
		];

		$connection->on("datachannel", function(RTCDataChannel $channel) use ($connectionId, $address, $port, $connection) : void{
			$this->handleDataChannel($connection, $channel, $connectionId, $address, $port);
		});

		$connection->setRemoteDescription(new RTCSessionDescription($signal->data, "offer"))
			->then(fn() => $connection->createAnswer())
			->then(fn(RTCSessionDescription $answer) => $connection->setLocalDescription($answer))
			->then(function() use ($connection, $connectionId, $senderNetworkId, $address, $port) : void{
				$local = $connection->getLocalDescription();
				if($local === null){
					$this->dropConnection($connectionId, "no local description");
					return;
				}
				$this->sendSignal(new Signal(Signal::TYPE_ANSWER, $connectionId, $local->getSdp()), $senderNetworkId, $address, $port);
				foreach($this->extractCandidates($local->getSdp()) as $candidate){
					$this->sendSignal(new Signal(Signal::TYPE_CANDIDATE, $connectionId, $candidate), $senderNetworkId, $address, $port);
				}
			})
			->catch(function(\Throwable $e) use ($connectionId) : void{
				$this->logger->error("NetherNet negotiation failed for connection $connectionId: " . $e->getMessage());
				$this->dropConnection($connectionId, "negotiation failed");
			});
	}

	private function handleCandidate(Signal $signal) : void{
		$entry = $this->pending[$signal->connectionId] ?? null;
		if($entry === null){
			return;
		}
		try{
			$entry["connection"]->addIceCandidate(RTCIceCandidate::parseSDP($signal->data));
		}catch(\Throwable $e){
			$this->logger->debug("Ignoring invalid ICE candidate for connection $signal->connectionId: " . $e->getMessage());
		}
	}

	private function handleDataChannel(RTCPeerConnection $connection, RTCDataChannel $channel, int $connectionId, string $address, int $port) : void{
		$session = $this->sessions[$connectionId] ?? null;
		if($session === null){
			$session = new NetherNetSession(
				$connection,
				$connectionId,
				$address,
				$port,
				function(string $payload) use ($connectionId) : void{
					$session = $this->sessions[$connectionId] ?? null;
					if($session !== null){
						$this->listener?->onPacketReceive($this, $session, $payload);
					}
				},
				function() use ($connectionId) : void{
					$this->closeSession($connectionId, "channel closed");
				}
			);
			$this->sessions[$connectionId] = $session;
			unset($this->pending[$connectionId]);
		}
		$session->bindChannel($channel);

		if($channel->getLabel() === NetherNetSession::RELIABLE_CHANNEL){
			$channel->on("open", function() use ($connectionId) : void{
				$session = $this->sessions[$connectionId] ?? null;
				if($session !== null){
					$this->listener?->onSessionOpen($this, $session);
				}
			});
		}
	}

	private function closeSession(int $connectionId, string $reason) : void{
		$session = $this->sessions[$connectionId] ?? null;
		if($session !== null){
			unset($this->sessions[$connectionId]);
			$session->disconnect();
			$this->listener?->onSessionClose($this, $session, $reason);
		}
	}

	private function dropConnection(int $connectionId, string $reason) : void{
		$entry = $this->pending[$connectionId] ?? null;
		if($entry !== null){
			unset($this->pending[$connectionId]);
			try{
				$entry["connection"]->close();
			}catch(\Throwable){

			}
		}
		$this->closeSession($connectionId, $reason);
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

	/**
	 * @return string[]
	 */
	private function extractCandidates(string $sdp) : array{
		$candidates = [];
		foreach(explode("\n", $sdp) as $line){
			$line = trim($line);
			if(str_starts_with($line, "a=candidate:")){
				$candidates[] = substr($line, 2);
			}
		}
		return $candidates;
	}
}
