<?php

declare(strict_types=1);

namespace altay\network\nethernet;

use altay\network\transport\TransportSession;
use Webrtc\DataChannel\Enum\State;
use Webrtc\DataChannel\RTCDataChannel;
use Webrtc\Webrtc\RTCPeerConnection;

final class NetherNetSession implements TransportSession{

	public const RELIABLE_CHANNEL = "ReliableDataChannel";
	public const UNRELIABLE_CHANNEL = "UnreliableDataChannel";

	private const MAX_MESSAGE_SIZE = 262143;
	private const MAX_SEGMENTS = 256;

	private bool $connected = true;
	private string $receiveBuffer = "";
	private ?RTCDataChannel $reliableChannel = null;
	private ?RTCDataChannel $unreliableChannel = null;

	/** @var \Closure(string) : void */
	private \Closure $packetHandler;
	/** @var \Closure() : void */
	private \Closure $closeHandler;

	public function __construct(
		private RTCPeerConnection $connection,
		private int $connectionId,
		private string $address,
		private int $port,
		\Closure $packetHandler,
		\Closure $closeHandler
	){
		$this->packetHandler = $packetHandler;
		$this->closeHandler = $closeHandler;
	}

	public function getId() : int{
		return $this->connectionId;
	}

	public function getAddress() : string{
		return $this->address;
	}

	public function getPort() : int{
		return $this->port;
	}

	public function getPing() : int{
		return -1;
	}

	public function isConnected() : bool{
		return $this->connected;
	}

	public function bindChannel(RTCDataChannel $channel) : void{
		if($channel->getLabel() === self::RELIABLE_CHANNEL){
			$this->reliableChannel = $channel;
			$channel->on("message", function(string $data) : void{
				$this->handleMessage($data);
			});
			$channel->on("close", function() : void{
				$this->onClosed();
			});
		}elseif($channel->getLabel() === self::UNRELIABLE_CHANNEL){
			$this->unreliableChannel = $channel;
		}
	}

	public function isReady() : bool{
		return $this->reliableChannel !== null && $this->reliableChannel->getReadyState() === State::Open;
	}

	public function getReliableChannel() : ?RTCDataChannel{
		return $this->reliableChannel;
	}

	private function handleMessage(string $data) : void{
		if($data === "" || !$this->connected){
			return;
		}
		$remaining = ord($data[0]);
		$this->receiveBuffer .= substr($data, 1);
		if($remaining === 0){
			$packet = $this->receiveBuffer;
			$this->receiveBuffer = "";
			($this->packetHandler)($packet);
		}
	}

	public function sendPacket(string $payload, bool $immediate = false) : void{
		if(!$this->connected || $this->reliableChannel === null){
			return;
		}
		$length = strlen($payload);
		$segments = max(1, intdiv($length + self::MAX_MESSAGE_SIZE - 1, self::MAX_MESSAGE_SIZE));
		if($segments > self::MAX_SEGMENTS){
			throw new \InvalidArgumentException("Payload of $length bytes requires $segments segments (max " . self::MAX_SEGMENTS . ")");
		}
		$remaining = $segments - 1;
		for($offset = 0; $offset === 0 || $offset < $length; $offset += self::MAX_MESSAGE_SIZE){
			$this->reliableChannel->send(chr($remaining) . substr($payload, $offset, self::MAX_MESSAGE_SIZE));
			$remaining--;
		}
	}

	public function disconnect() : void{
		if($this->connected){
			$this->connected = false;
			try{
				$this->reliableChannel?->close();
				$this->unreliableChannel?->close();
				$this->connection->close();
			}catch(\Throwable){

			}
		}
	}

	private function onClosed() : void{
		if($this->connected){
			$this->disconnect();
			($this->closeHandler)();
		}
	}
}
