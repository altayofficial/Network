<?php

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

	}

	public function onBandwidthStatsUpdate(int $bytesSentDiff, int $bytesReceivedDiff) : void{

	}

	public function onPingMeasure(int $sessionId, int $pingMS) : void{
		$this->sessions[$sessionId]?->updatePing($pingMS);
	}
}
