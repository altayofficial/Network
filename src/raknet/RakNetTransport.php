<?php

declare(strict_types=1);

namespace altay\network\raknet;

use altay\network\transport\Transport;
use altay\network\transport\TransportException;
use altay\network\transport\TransportListener;
use altay\network\raknet\generic\SocketException;
use altay\network\raknet\server\Server;
use altay\network\raknet\server\ServerSocket;
use altay\network\raknet\server\SimpleProtocolAcceptor;
use altay\network\raknet\utils\ExceptionTraceCleaner;
use altay\network\raknet\utils\InternetAddress;

final class RakNetTransport implements Transport{

	public const BEDROCK_RAKNET_PROTOCOL_VERSION = 11;

	private ?Server $server = null;
	private ?RakNetEventListener $eventListener = null;

	public function __construct(
		private \Logger $logger,
		private string $bindAddress = "0.0.0.0",
		private int $port = 19132,
		private bool $ipv6 = false,
		private int $maxMtuSize = 1492,
		private int $protocolVersion = self::BEDROCK_RAKNET_PROTOCOL_VERSION
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
			mt_rand(0, PHP_INT_MAX),
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
}
