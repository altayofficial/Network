<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use altay\network\nethernet\NetherNetTransport;
use altay\network\nethernet\ServerData;
use altay\network\transport\Transport;
use altay\network\transport\TransportException;
use altay\network\transport\TransportListener;
use altay\network\transport\TransportSession;
use PHPUnit\Framework\TestCase;
use Webrtc\STUN\UdpMux;

 /*
 * @group integration
 */
final class DialRoundTripTest extends TestCase{

	use RequiresPeerConnection;

	private const LISTEN_PORT = 17551;
	private const DIAL_PORT = 17552;
	private const SECOND_DIAL_PORT = 17553;
	private const SHARED_MEDIA_PORT = 17554;
	private const LISTEN_NETWORK = 111;
	private const DIAL_NETWORK = 222;
	private const TIMEOUT = 30;

	private ?NetherNetTransport $listening = null;
	private ?NetherNetTransport $dialling = null;
	private ?NetherNetTransport $secondDialling = null;

	protected function setUp() : void{
		$this->requirePeerConnection();
	}

	protected function tearDown() : void{
		$this->secondDialling?->shutdown();
		$this->dialling?->shutdown();
		$this->listening?->shutdown();
	}

	public function testDialledConnectionCarriesPackets() : void{
		$logger = new DiscardingLogger();

		$listeningEvents = new RecordingListener();
		$this->listening = new NetherNetTransport($logger, self::LISTEN_NETWORK, new ServerData("Altay", levelName: "World"), "127.0.0.1", self::LISTEN_PORT);
		$this->listening->start($listeningEvents);

		$diallingEvents = new RecordingListener();
		$this->dialling = new NetherNetTransport($logger, self::DIAL_NETWORK, new ServerData("Client", levelName: "-"), "127.0.0.1", self::DIAL_PORT);
		$this->dialling->start($diallingEvents);

		//discovery is not what is under test here, so the address is seeded directly
		$this->dialling->getAddressBook()->remember(self::LISTEN_NETWORK, "127.0.0.1", self::LISTEN_PORT, time());

		$session = $this->dialling->dial(self::LISTEN_NETWORK);

		$small = "hello from the dialler";
		//larger than one segment, so it exercises the split and the reassembly on the other side
		$large = str_repeat("A", 300000);

		$deadline = microtime(true) + self::TIMEOUT;
		$sent = false;
		while(microtime(true) < $deadline){
			$this->listening->tick();
			$this->dialling->tick();

			if(!$sent && $diallingEvents->opened && $listeningEvents->opened){
				$session->sendPacket($small);
				$session->sendPacket($large);
				$sent = true;
			}
			if($sent && count($listeningEvents->packets) >= 2){
				break;
			}
			usleep(20000);
		}

		self::assertTrue($diallingEvents->opened, "the dialling side never reported the session open");
		self::assertTrue($listeningEvents->opened, "the listening side never reported the session open");
		self::assertSame([$small, $large], $listeningEvents->packets);
		self::assertSame($session->getId(), $listeningEvents->sessionId);
	}

	public function testDiallersShareTheListenersMediaPort() : void{
		if(!class_exists(UdpMux::class)){
			self::markTestSkipped("the webrtc library has no shared port support");
		}
		$logger = new DiscardingLogger();

		$listeningEvents = new RecordingListener();
		$this->listening = new NetherNetTransport($logger, self::LISTEN_NETWORK, new ServerData("Altay", levelName: "World"), "127.0.0.1", self::LISTEN_PORT);
		$this->listening->setUdpPort(self::SHARED_MEDIA_PORT);
		$this->listening->start($listeningEvents);

		$sessions = [];
		$events = [];
		foreach([[self::DIAL_NETWORK, self::DIAL_PORT], [self::DIAL_NETWORK + 1, self::SECOND_DIAL_PORT]] as $index => [$networkId, $port]){
			$events[$index] = new RecordingListener();
			$transport = new NetherNetTransport($logger, $networkId, new ServerData("Client", levelName: "-"), "127.0.0.1", $port);
			$transport->start($events[$index]);
			$transport->getAddressBook()->remember(self::LISTEN_NETWORK, "127.0.0.1", self::LISTEN_PORT, time());
			$sessions[$index] = $transport->dial(self::LISTEN_NETWORK);
			if($index === 0){
				$this->dialling = $transport;
			}else{
				$this->secondDialling = $transport;
			}
		}

		$deadline = microtime(true) + self::TIMEOUT;
		$sent = false;
		while(microtime(true) < $deadline){
			$this->listening->tick();
			$this->dialling?->tick();
			$this->secondDialling?->tick();

			if(!$sent && $events[0]->opened && $events[1]->opened){
				$sessions[0]->sendPacket("first");
				$sessions[1]->sendPacket("second");
				$sent = true;
			}
			if($sent && count($listeningEvents->packets) >= 2){
				break;
			}
			usleep(20000);
		}

		self::assertTrue($events[0]->opened && $events[1]->opened, "a dialler never reported its session open");
		self::assertEqualsCanonicalizing(["first", "second"], $listeningEvents->packets);
		//both reached the listener on the one port, which is where ICE says their media flows
		self::assertSame(self::SHARED_MEDIA_PORT, $sessions[0]->getPort());
		self::assertSame(self::SHARED_MEDIA_PORT, $sessions[1]->getPort());
	}

	public function testATakenMediaPortIsOnlyFatalWhenItWasRequired() : void{
		if(!class_exists(UdpMux::class)){
			self::markTestSkipped("the webrtc library has no shared port support");
		}
		$squatter = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		self::assertNotFalse($squatter);
		self::assertTrue(socket_bind($squatter, "127.0.0.1", self::SHARED_MEDIA_PORT));
		try{
			$this->listening = new NetherNetTransport(new DiscardingLogger(), self::LISTEN_NETWORK, new ServerData("Altay", levelName: "World"), "127.0.0.1", self::LISTEN_PORT);
			$this->listening->setUdpPort(self::SHARED_MEDIA_PORT, required: false);
			$this->listening->start(new RecordingListener());
			self::assertTrue($this->listening->isRunning());
			$this->listening->shutdown();

			$this->listening = new NetherNetTransport(new DiscardingLogger(), self::LISTEN_NETWORK, new ServerData("Altay", levelName: "World"), "127.0.0.1", self::LISTEN_PORT);
			$this->listening->setUdpPort(self::SHARED_MEDIA_PORT);
			$this->expectException(TransportException::class);
			$this->listening->start(new RecordingListener());
		}finally{
			socket_close($squatter);
		}
	}

	public function testUnclaimedDatagramsOnTheMediaPortReachTheListener() : void{
		if(!class_exists(UdpMux::class) || !method_exists(UdpMux::class, "onUnrouted")){
			self::markTestSkipped("the webrtc library cannot hand out unrouted datagrams");
		}
		$events = new RecordingListener();
		$this->listening = new NetherNetTransport(new DiscardingLogger(), self::LISTEN_NETWORK, new ServerData("Altay", levelName: "World"), "127.0.0.1", self::LISTEN_PORT);
		$this->listening->setUdpPort(self::SHARED_MEDIA_PORT);
		$this->listening->addRawPacketFilter('/^\xfe\xfd/');
		$this->listening->start($events);

		$client = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		self::assertNotFalse($client);
		socket_bind($client, "127.0.0.1", 0);
		socket_set_nonblock($client);
		socket_sendto($client, "not a query", 11, 0, "127.0.0.1", self::SHARED_MEDIA_PORT);
		socket_sendto($client, "\xfe\xfdquery", 7, 0, "127.0.0.1", self::SHARED_MEDIA_PORT);

		$reply = "";
		$deadline = microtime(true) + 5;
		while(microtime(true) < $deadline){
			$this->listening->tick();
			if($events->raw !== [] && $reply === ""){
				[$address, $port] = $events->raw[0];
				$this->listening->sendRaw($address, $port, "pong");
			}
			$buffer = "";
			$from = "";
			$fromPort = 0;
			if(@socket_recvfrom($client, $buffer, 65535, 0, $from, $fromPort) > 0){
				$reply = $buffer;
				break;
			}
			usleep(10000);
		}
		socket_close($client);

		self::assertCount(1, $events->raw, "only the datagram matching a filter should get through");
		self::assertSame("127.0.0.1", $events->raw[0][0]);
		self::assertSame("\xfe\xfdquery", $events->raw[0][2]);
		self::assertSame("pong", $reply);
	}
}

final class DiscardingLogger implements \Logger{
	public function emergency($message){}
	public function alert($message){}
	public function critical($message){}
	public function error($message){}
	public function warning($message){}
	public function notice($message){}
	public function info($message){}
	public function debug($message){}
	public function log($level, $message){}
	public function logException(\Throwable $e, $trace = null){}
}

final class RecordingListener implements TransportListener{

	public bool $opened = false;
	public ?int $sessionId = null;
	/** @var string[] */
	public array $packets = [];
	/** @var list<array{string, int, string}> */
	public array $raw = [];

	public function onSessionOpen(Transport $transport, TransportSession $session) : void{
		$this->opened = true;
		$this->sessionId = $session->getId();
	}

	public function onSessionClose(Transport $transport, TransportSession $session, string $reason) : void{}

	public function onPacketReceive(Transport $transport, TransportSession $session, string $payload) : void{
		$this->packets[] = $payload;
	}

	public function onPacketAck(Transport $transport, TransportSession $session, int $receiptId) : void{}
	public function onPingUpdate(Transport $transport, TransportSession $session, int $pingMS) : void{}
	public function onRawPacketReceive(Transport $transport, string $address, int $port, string $payload) : void{
		$this->raw[] = [$address, $port, $payload];
	}

	public function onBandwidthUpdate(Transport $transport, int $bytesSentDiff, int $bytesReceivedDiff) : void{}
}
