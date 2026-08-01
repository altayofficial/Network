<?php

declare(strict_types=1);

namespace altay\network\tests\ipc;

use altay\network\ipc\InterThreadChannelReader;
use altay\network\ipc\InterThreadChannelWriter;
use altay\network\ipc\MainToTransportThreadMessageReceiver;
use altay\network\ipc\MainToTransportThreadMessageSender;
use altay\network\ipc\TransportToMainThreadEventHandler;
use altay\network\ipc\TransportToMainThreadMessageReceiver;
use altay\network\ipc\TransportToMainThreadMessageSender;
use altay\network\transport\Transport;
use altay\network\transport\TransportListener;
use altay\network\transport\TransportSession;
use PHPUnit\Framework\TestCase;

final class IpcRoundTripTest extends TestCase{

	public function testEventsRoundTrip() : void{
		$channel = new class implements InterThreadChannelReader, InterThreadChannelWriter{
			/** @var string[] */
			private array $queue = [];
			public function write(string $str) : void{ $this->queue[] = $str; }
			public function read() : ?string{ return array_shift($this->queue); }
		};

		$sender = new TransportToMainThreadMessageSender($channel);
		$transport = $this->stubTransport();
		$session = $this->stubSession(0x1122334455667788, "10.0.0.5", 5000, "PUBKEYDATA");

		$sender->onSessionOpen($transport, $session);
		$sender->onPacketReceive($transport, $session, "hello");
		$sender->onPacketAck($transport, $session, 99);
		$sender->onPingUpdate($transport, $session, 42);
		$sender->onBandwidthUpdate($transport, 1000, 2000);
		$sender->onRawPacketReceive($transport, "1.2.3.4", 19132, "raw");
		$sender->onSessionClose($transport, $session, "timeout");

		$events = [];
		$handler = new class($events) implements TransportToMainThreadEventHandler{
			/** @param mixed[] $events */
			public function __construct(private array &$events){}
			public function handleSessionOpen(int $id, string $a, int $p, ?string $k) : void{ $this->events[] = ["open", $id, $a, $p, $k]; }
			public function handleSessionClose(int $id, string $r) : void{ $this->events[] = ["close", $id, $r]; }
			public function handlePacketReceive(int $id, string $pl) : void{ $this->events[] = ["packet", $id, $pl]; }
			public function handlePacketAck(int $id, int $r) : void{ $this->events[] = ["ack", $id, $r]; }
			public function handlePingUpdate(int $id, int $ms) : void{ $this->events[] = ["ping", $id, $ms]; }
			public function handleRawPacketReceive(string $a, int $p, string $pl) : void{ $this->events[] = ["raw", $a, $p, $pl]; }
			public function handleBandwidthUpdate(int $s, int $r) : void{ $this->events[] = ["bw", $s, $r]; }
		};

		$receiver = new TransportToMainThreadMessageReceiver($channel);
		while($receiver->handle($handler));

		$id = 0x1122334455667788;
		self::assertSame([
			["open", $id, "10.0.0.5", 5000, "PUBKEYDATA"],
			["packet", $id, "hello"],
			["ack", $id, 99],
			["ping", $id, 42],
			["bw", 1000, 2000],
			["raw", "1.2.3.4", 19132, "raw"],
			["close", $id, "timeout"],
		], $events);
	}

	public function testCommandsRoundTrip() : void{
		$channel = new class implements InterThreadChannelReader, InterThreadChannelWriter{
			/** @var string[] */
			private array $queue = [];
			public function write(string $str) : void{ $this->queue[] = $str; }
			public function read() : ?string{ return array_shift($this->queue); }
		};

		$sender = new MainToTransportThreadMessageSender($channel);
		$id = 0x1122334455667788;
		$sender->sendPacket($id, "payload", true, 55);
		$sender->sendPacket($id, "plain", false, null);
		$sender->closeSession($id);
		$sender->shutdown();

		$sent = [];
		$disconnected = 0;
		$session = new class($sent) implements TransportSession{
			/** @param mixed[] $sent */
			public function __construct(private array &$sent){}
			public bool $disconnected = false;
			public function getId() : int{ return 0x1122334455667788; }
			public function getAddress() : string{ return ""; }
			public function getPort() : int{ return 0; }
			public function getPing() : int{ return -1; }
			public function getAuthenticatedPublicKey() : ?string{ return null; }
			public function isConnected() : bool{ return true; }
			public function sendPacket(string $p, bool $i = false, ?int $r = null) : void{ $this->sent[] = [$p, $i, $r]; }
			public function disconnect() : void{ $this->disconnected = true; }
		};
		$transport = $this->stubTransport($session);

		$receiver = new MainToTransportThreadMessageReceiver($channel);
		while($receiver->handle($transport));

		self::assertSame([["payload", true, 55], ["plain", false, null]], $sent);
		self::assertTrue($session->disconnected);
		self::assertTrue($receiver->isShutdownRequested());
	}

	private function stubTransport(?TransportSession $session = null) : Transport{
		return new class($session) implements Transport{
			public function __construct(private ?TransportSession $session){}
			public function getName() : string{ return "stub"; }
			public function start(TransportListener $l) : void{}
			public function tick() : void{}
			public function getSession(int $id) : ?TransportSession{ return $this->session; }
			public function isSelfPacing() : bool{ return false; }
			public function isRunning() : bool{ return true; }
			public function shutdown() : void{}
		};
	}

	private function stubSession(int $id, string $address, int $port, ?string $publicKey) : TransportSession{
		return new class($id, $address, $port, $publicKey) implements TransportSession{
			public function __construct(private int $id, private string $address, private int $port, private ?string $publicKey){}
			public function getId() : int{ return $this->id; }
			public function getAddress() : string{ return $this->address; }
			public function getPort() : int{ return $this->port; }
			public function getPing() : int{ return -1; }
			public function getAuthenticatedPublicKey() : ?string{ return $this->publicKey; }
			public function isConnected() : bool{ return true; }
			public function sendPacket(string $p, bool $i = false, ?int $r = null) : void{}
			public function disconnect() : void{}
		};
	}
}
