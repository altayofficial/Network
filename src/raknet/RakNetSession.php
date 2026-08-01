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
