<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use altay\network\nethernet\NetherNetTransport;
use altay\network\nethernet\ServerData;
use altay\network\transport\Transport;
use altay\network\transport\TransportListener;
use altay\network\transport\TransportSession;
use PHPUnit\Framework\TestCase;

 /*
 * @group integration
 * @requires extension ffi
 */
final class DialRoundTripTest extends TestCase{

	private const LISTEN_PORT = 17551;
	private const DIAL_PORT = 17552;
	private const LISTEN_NETWORK = 111;
	private const DIAL_NETWORK = 222;
	private const TIMEOUT = 30;

	private ?NetherNetTransport $listening = null;
	private ?NetherNetTransport $dialling = null;



	protected function tearDown() : void{
		$this->dialling?->shutdown();
		$this->listening?->shutdown();
	}

	public function testDialledConnectionCarriesPackets() : void{
		$logger = new DiscardingLogger();

		$listeningEvents = new RecordingListener();
		$this->listening = new NetherNetTransport($logger, self::LISTEN_NETWORK, new ServerData("Altay", "World"), "127.0.0.1", self::LISTEN_PORT);
		$this->listening->start($listeningEvents);

		$diallingEvents = new RecordingListener();
		$this->dialling = new NetherNetTransport($logger, self::DIAL_NETWORK, new ServerData("Client", "-"), "127.0.0.1", self::DIAL_PORT);
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
	public function onRawPacketReceive(Transport $transport, string $address, int $port, string $payload) : void{}
	public function onBandwidthUpdate(Transport $transport, int $bytesSentDiff, int $bytesReceivedDiff) : void{}
}
