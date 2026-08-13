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
	private bool $openNotified = false;
	private string $receiveBuffer = "";
	private int $pendingSegments = 0;
	private ?string $authenticatedPublicKey = null;
	private ?RTCDataChannel $reliableChannel = null;
	private ?RTCDataChannel $unreliableChannel = null;

	private int $bytesSent = 0;
	private int $bytesReceived = 0;
	private int $createdAt;

	/** @var \Closure(string) : void */
	private \Closure $packetHandler;
	/** @var \Closure(string) : void */
	private \Closure $closeHandler;
	/** @var \Closure(int) : void */
	private \Closure $ackHandler;

	public function __construct(
		private RTCPeerConnection $connection,
		private int $connectionId,
		private string $address,
		private int $port,
		\Closure $packetHandler,
		\Closure $closeHandler,
		\Closure $ackHandler
	){
		$this->packetHandler = $packetHandler;
		$this->closeHandler = $closeHandler;
		$this->ackHandler = $ackHandler;
		$this->createdAt = time();
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
		$srtt = SctpStatsReader::smoothedRoundTripTime($this->reliableChannel);
		if($srtt === null){
			return -1;
		}
		//SRTT is a full round trip in seconds, ping is conventionally one-way in milliseconds. weird right?
		return (int) round($srtt * 1000 / 2);
	}

	public function isConnected() : bool{
		return $this->connected;
	}

	public function collectBandwidthDelta() : array{
		$delta = [$this->bytesSent, $this->bytesReceived];
		$this->bytesSent = 0;
		$this->bytesReceived = 0;
		return $delta;
	}

	/**
	 * Binds an inbound data channel to the session. Returns false when the peer opened a channel
	 * the protocol does not define, or one it has already opened.
	 */
	public function bindChannel(RTCDataChannel $channel) : bool{
		if($channel->getLabel() === self::RELIABLE_CHANNEL){
			if($this->reliableChannel !== null){
				return false;
			}
			$this->reliableChannel = $channel;
			$channel->on("message", function(string $data) : void{
				$this->handleMessage($data);
			});
			$channel->on("close", function() : void{
				$this->onClosed();
			});
			return true;
		}
		if($channel->getLabel() === self::UNRELIABLE_CHANNEL){
			if($this->unreliableChannel !== null){
				return false;
			}
			$this->unreliableChannel = $channel;
			return true;
		}
		return false;
	}

	/**
	 * A session is only usable once the peer has opened both of its data channels - vanilla always
	 * opens the pair, and reporting the session before the unreliable one exists would hand out a
	 * half-negotiated connection.
	 */
	public function isReady() : bool{
		return self::isChannelOpen($this->reliableChannel) && self::isChannelOpen($this->unreliableChannel);
	}

	private static function isChannelOpen(?RTCDataChannel $channel) : bool{
		return $channel !== null && $channel->getReadyState() === State::Open;
	}

	public function getCreatedAt() : int{
		return $this->createdAt;
	}

	public function isOpenNotified() : bool{
		return $this->openNotified;
	}

	public function setAuthenticatedPublicKey(?string $publicKeyBase64) : void{
		$this->authenticatedPublicKey = $publicKeyBase64;
	}

	public function getAuthenticatedPublicKey() : ?string{
		return $this->authenticatedPublicKey;
	}

	public function markOpenNotified() : bool{
		if($this->openNotified){
			return false;
		}
		return $this->openNotified = true;
	}

	public function getReliableChannel() : ?RTCDataChannel{
		return $this->reliableChannel;
	}

	private function handleMessage(string $data) : void{
		if(!$this->connected){
			return;
		}
		$this->bytesReceived += strlen($data);
		if(strlen($data) < 2){
			//a segment always carries the counter byte plus at least one payload byte
			$this->closeWithError("received a message without a payload");
			return;
		}

		//each segment is prefixed with the number of segments still to come, counting down to zero.
		//an unexpected counter means the message can never be completed, so the peer is dropped instead
		//of buffering data indefinitely
		$remaining = ord($data[0]);
		if($this->pendingSegments > 0 && $this->pendingSegments - 1 !== $remaining){
			$this->closeWithError("expected segment counter " . ($this->pendingSegments - 1) . ", got $remaining");
			return;
		}
		$this->pendingSegments = $remaining;
		$this->receiveBuffer .= substr($data, 1);
		if($remaining > 0){
			return;
		}

		$packet = $this->receiveBuffer;
		$this->receiveBuffer = "";
		($this->packetHandler)($packet);
	}

	private function closeWithError(string $reason) : void{
		$this->disconnect();
		($this->closeHandler)($reason);
	}

	public function sendPacket(string $payload, bool $immediate = false, ?int $receiptId = null) : void{
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
			$segment = chr($remaining) . substr($payload, $offset, self::MAX_MESSAGE_SIZE);
			$this->reliableChannel->send($segment);
			$this->bytesSent += strlen($segment);
			$remaining--;
		}
		if($receiptId !== null){
			($this->ackHandler)($receiptId);
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
			$this->closeWithError("data channel closed by the remote peer");
		}
	}
}
