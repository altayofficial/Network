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

use altay\network\transport\TransportListener;
use altay\network\raknet\generic\DisconnectReason;
use altay\network\raknet\server\ServerEventListener;
use altay\network\raknet\server\ServerInterface;

final class RakNetEventListener implements ServerEventListener{

	/** @var RakNetSession[] */
	private array $sessions = [];
	private ?ServerInterface $server = null;

	public function __construct(
		private RakNetTransport $transport,
		private TransportListener $listener
	){}

	public function setServer(ServerInterface $server) : void{
		$this->server = $server;
	}

	public function getSession(int $sessionId) : ?RakNetSession{
		return $this->sessions[$sessionId] ?? null;
	}

	public function onClientConnect(int $sessionId, string $address, int $port, int $clientID) : void{
		if($this->server === null){
			return;
		}
		$session = new RakNetSession($this->server, $sessionId, $address, $port, $clientID);
		$this->sessions[$sessionId] = $session;
		$this->listener->onSessionOpen($this->transport, $session);
	}

	public function onClientDisconnect(int $sessionId, int $reason) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session === null){
			return;
		}
		unset($this->sessions[$sessionId]);
		$session->markDisconnected();
		$this->listener->onSessionClose($this->transport, $session, DisconnectReason::toString($reason));
	}

	public function onPacketReceive(int $sessionId, string $packet) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session !== null){
			$this->listener->onPacketReceive($this->transport, $session, $packet);
		}
	}

	public function onRawPacketReceive(string $address, int $port, string $payload) : void{
		$this->listener->onRawPacketReceive($this->transport, $address, $port, $payload);
	}

	public function onPacketAck(int $sessionId, int $identifierACK) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session !== null){
			$this->listener->onPacketAck($this->transport, $session, $identifierACK);
		}
	}

	public function onBandwidthStatsUpdate(int $bytesSentDiff, int $bytesReceivedDiff) : void{
		$this->listener->onBandwidthUpdate($this->transport, $bytesSentDiff, $bytesReceivedDiff);
	}

	public function onPingMeasure(int $sessionId, int $pingMS) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session !== null){
			$session->updatePing($pingMS);
			$this->listener->onPingUpdate($this->transport, $session, $pingMS);
		}
	}
}
