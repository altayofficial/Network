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

use altay\network\transport\AddressBlockingTransport;
use altay\network\transport\NameableTransport;
use altay\network\transport\RawPacketTransport;
use altay\network\transport\TransportException;
use altay\network\transport\TransportListener;
use altay\network\transport\TunableTransport;
use altay\network\raknet\generic\SocketException;
use altay\network\raknet\server\Server;
use altay\network\raknet\server\ServerSocket;
use altay\network\raknet\server\SimpleProtocolAcceptor;
use altay\network\raknet\utils\ExceptionTraceCleaner;
use altay\network\raknet\utils\InternetAddress;

final class RakNetTransport implements NameableTransport, RawPacketTransport, AddressBlockingTransport, TunableTransport{

	public const BEDROCK_RAKNET_PROTOCOL_VERSION = 11;

	private ?Server $server = null;
	private ?RakNetEventListener $eventListener = null;

	public function __construct(
		private \Logger $logger,
		private string $bindAddress = "0.0.0.0",
		private int $port = 19132,
		private bool $ipv6 = false,
		private int $maxMtuSize = 1492,
		private int $protocolVersion = self::BEDROCK_RAKNET_PROTOCOL_VERSION,
		private ?int $serverId = null
	){}

	public function getName() : string{
		return "raknet";
	}

	public function start(TransportListener $listener) : void{
		if($this->server !== null){
			throw new TransportException("RakNet transport is already running");
		}
		$this->eventListener = new RakNetEventListener($this, $listener);
		try{
			$socket = new ServerSocket(new InternetAddress($this->bindAddress, $this->port, $this->ipv6 ? 6 : 4));
		}catch(SocketException $e){
			$this->eventListener = null;
			throw new TransportException("Failed to start RakNet transport: " . $e->getMessage(), 0, $e);
		}
		$this->server = new Server(
			$this->serverId ?? mt_rand(0, PHP_INT_MAX),
			$this->logger,
			$socket,
			$this->maxMtuSize,
			new SimpleProtocolAcceptor($this->protocolVersion),
			new NullServerEventSource(),
			$this->eventListener,
			new ExceptionTraceCleaner(dirname(__DIR__, 2))
		);
		$this->eventListener->setServer($this->server);
	}

	public function tick() : void{
		$this->server?->tickProcessor();
	}

	public function isSelfPacing() : bool{
		return true;
	}

	public function isRunning() : bool{
		return $this->server !== null;
	}

	public function shutdown() : void{
		if($this->server !== null){
			$this->server->waitShutdown();
			$this->server = null;
			$this->eventListener = null;
		}
	}

	public function getSession(int $sessionId) : ?RakNetSession{
		return $this->eventListener?->getSession($sessionId);
	}

	public function getServerId() : ?int{
		return $this->server?->getID();
	}

	public function setName(string $name) : void{
		$this->server?->setName($name);
	}

	public function sendRaw(string $address, int $port, string $payload) : void{
		$this->server?->sendRaw($address, $port, $payload);
	}

	public function blockAddress(string $address, int $timeout = 300) : void{
		$this->server?->blockAddress($address, $timeout);
	}

	public function unblockAddress(string $address) : void{
		$this->server?->unblockAddress($address);
	}

	public function setPortCheck(bool $value) : void{
		$this->server?->setPortCheck($value);
	}

	public function setPacketsPerTickLimit(int $limit) : void{
		$this->server?->setPacketsPerTickLimit($limit);
	}

	public function addRawPacketFilter(string $regex) : void{
		$this->server?->addRawPacketFilter($regex);
	}
}
