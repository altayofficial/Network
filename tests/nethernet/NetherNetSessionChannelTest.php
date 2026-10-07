<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use altay\network\nethernet\NetherNetSession;
use PHPUnit\Framework\TestCase;
use Webrtc\DataChannel\RTCDataChannel;
use Webrtc\Webrtc\RTCPeerConnection;

final class NetherNetSessionChannelTest extends TestCase{

	private function session() : NetherNetSession{
		return new NetherNetSession($this->createStub(RTCPeerConnection::class), 1, "127.0.0.1", 0, static function() : void{}, static function() : void{}, static function() : void{});
	}

	private function channel(string $label, bool $ordered = true, ?int $maxRetransmits = null, ?int $maxPacketLifeTime = null, string $protocol = "") : RTCDataChannel{
		$channel = $this->createStub(RTCDataChannel::class);
		$channel->method("getLabel")->willReturn($label);
		$channel->method("isOrdered")->willReturn($ordered);
		$channel->method("getMaxRetransmits")->willReturn($maxRetransmits);
		$channel->method("getMaxPacketLifeTime")->willReturn($maxPacketLifeTime);
		$channel->method("getProtocol")->willReturn($protocol);
		return $channel;
	}

	public function testBindsChannelsThatDeliverTheWayTheirNamesSay() : void{
		$session = $this->session();

		self::assertTrue($session->bindChannel($this->channel(NetherNetSession::RELIABLE_CHANNEL)));
		self::assertTrue($session->bindChannel($this->channel(NetherNetSession::UNRELIABLE_CHANNEL, ordered: false, maxRetransmits: 0)));
	}

	public function testRejectsAReliableChannelThatMayLoseOrReorder() : void{
		$session = $this->session();

		self::assertFalse($session->bindChannel($this->channel(NetherNetSession::RELIABLE_CHANNEL, ordered: false)));
		self::assertFalse($session->bindChannel($this->channel(NetherNetSession::RELIABLE_CHANNEL, maxRetransmits: 3)));
		self::assertFalse($session->bindChannel($this->channel(NetherNetSession::RELIABLE_CHANNEL, maxPacketLifeTime: 100)));
	}

	public function testRejectsAnUnreliableChannelThatRetransmitsOrKeepsOrder() : void{
		$session = $this->session();

		self::assertFalse($session->bindChannel($this->channel(NetherNetSession::UNRELIABLE_CHANNEL, maxRetransmits: 0)));
		self::assertFalse($session->bindChannel($this->channel(NetherNetSession::UNRELIABLE_CHANNEL, ordered: false)));
	}

	public function testRejectsAChannelWithASubprotocol() : void{
		self::assertFalse($this->session()->bindChannel($this->channel(NetherNetSession::RELIABLE_CHANNEL, protocol: "x")));
	}
}
