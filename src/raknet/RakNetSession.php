<?php

declare(strict_types=1);

namespace altay\network\raknet;

use altay\network\transport\TransportSession;
use altay\network\raknet\protocol\EncapsulatedPacket;
use altay\network\raknet\protocol\PacketReliability;
use altay\network\raknet\server\ServerInterface;

final class RakNetSession implements TransportSession{

	private int $ping = -1;
	private bool $connected = true;

	public function __construct(
		private ServerInterface $server,
		private int $sessionId,
		private string $address,
		private int $port,
		private int $clientId
	){}

	public function getId() : int{
		return $this->sessionId;
	}

	public function getAddress() : string{
		return $this->address;
	}

	public function getPort() : int{
		return $this->port;
	}

	public function getClientId() : int{
		return $this->clientId;
	}

	public function getPing() : int{
		return $this->ping;
	}

	public function getAuthenticatedPublicKey() : ?string{
		return null;
	}

	public function updatePing(int $ping) : void{
		$this->ping = $ping;
	}

	public function isConnected() : bool{
		return $this->connected;
	}

	public function markDisconnected() : void{
		$this->connected = false;
	}

	public function sendPacket(string $payload, bool $immediate = false, ?int $receiptId = null) : void{
		if(!$this->connected){
			return;
		}
		$encapsulated = new EncapsulatedPacket();
		$encapsulated->reliability = PacketReliability::RELIABLE_ORDERED;
		$encapsulated->orderChannel = 0;
		$encapsulated->buffer = $payload;
		$encapsulated->identifierACK = $receiptId;
		$this->server->sendEncapsulated($this->sessionId, $encapsulated, $immediate);
	}

	public function disconnect() : void{
		if($this->connected){
			$this->connected = false;
			$this->server->closeSession($this->sessionId);
		}
	}
}
