<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use altay\network\nethernet\CallbackSignalSink;
use altay\network\nethernet\NetherNetTransport;
use altay\network\nethernet\ServerData;
use altay\network\nethernet\Signal;
use altay\network\nethernet\types\SignalErrorCode;
use altay\network\transport\Transport;
use altay\network\transport\TransportListener;
use altay\network\transport\TransportSession;
use PHPUnit\Framework\TestCase;

/**
 * Covers what happens to an offer that carries no 'a=identity' attribute, which is the difference
 * between a server that binds its connections to the account behind them and one that does not.
 */
final class IdentityPolicyTest extends TestCase{

	private const PORT = 17561;
	private const NETWORK = 333;

	private ?NetherNetTransport $transport = null;

	protected function tearDown() : void{
		$this->transport?->shutdown();
	}

	private function unsignedOffer() : string{
		return "v=0\r\n" .
			"o=- 1 2 IN IP4 127.0.0.1\r\n" .
			"s=-\r\nt=0 0\r\n" .
			"a=ice-ufrag:abcd\r\na=ice-pwd:0123456789abcdef0123\r\n" .
			"a=fingerprint:sha-256 " . strtoupper(implode(":", str_split(hash("sha256", "cert"), 2))) . "\r\n" .
			"a=setup:actpass\r\n" .
			"m=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\n" .
			"a=sctp-port:5000\r\n";
	}

	/**
	 * @param Signal[] $signals
	 */
	private function accept(bool $requireIdentity, ?bool $override, array &$signals) : void{
		$this->transport = new NetherNetTransport(
			new SilentLogger(),
			self::NETWORK,
			new ServerData("Altay", levelName: "World"),
			"127.0.0.1",
			self::PORT,
			requireIdentity: $requireIdentity
		);
		$this->transport->start(new IgnoringListener());

		$sink = new CallbackSignalSink(function(Signal $signal) use (&$signals) : void{
			$signals[] = $signal;
		});
		$this->transport->acceptOffer(new Signal(Signal::TYPE_OFFER, "42", $this->unsignedOffer()), 999, "127.0.0.1", 19132, $sink, $override);
	}

	public function testUnsignedOfferIsRejectedWhenIdentityIsRequired() : void{
		$signals = [];
		$this->accept(true, null, $signals);

		self::assertCount(1, $signals);
		self::assertSame(Signal::TYPE_ERROR, $signals[0]->type);
		self::assertSame((string) SignalErrorCode::IDENTITY_VERIFICATION_FAILED->value, $signals[0]->data);
	}

	public function testUnsignedOfferIsAcceptedWhenIdentityIsNotRequired() : void{
		$signals = [];
		$this->accept(false, null, $signals);

		foreach($signals as $signal){
			self::assertNotSame(Signal::TYPE_ERROR, $signal->type, "the offer was turned away: " . $signal->data);
		}
	}

	public function testTheSignallingChannelCanHoldItsPeersToADifferentPolicy() : void{
		//this is how offers over the signalling endpoint are held to a stricter rule than the ones
		//broadcast on the local network, where clients do not sign
		$signals = [];
		$this->accept(false, true, $signals);

		self::assertCount(1, $signals);
		self::assertSame(Signal::TYPE_ERROR, $signals[0]->type);
		self::assertSame((string) SignalErrorCode::IDENTITY_VERIFICATION_FAILED->value, $signals[0]->data);
	}
}

final class SilentLogger implements \Logger{
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

final class IgnoringListener implements TransportListener{
	public function onSessionOpen(Transport $transport, TransportSession $session) : void{}

	public function onSessionClose(Transport $transport, TransportSession $session, string $reason) : void{}

	public function onPacketReceive(Transport $transport, TransportSession $session, string $payload) : void{}

	public function onPacketAck(Transport $transport, TransportSession $session, int $receiptId) : void{}

	public function onPingUpdate(Transport $transport, TransportSession $session, int $pingMS) : void{}

	public function onRawPacketReceive(Transport $transport, string $address, int $port, string $payload) : void{}

	public function onBandwidthUpdate(Transport $transport, int $bytesSentDiff, int $bytesReceivedDiff) : void{}
}
