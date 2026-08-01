<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\discovery;

use altay\network\nethernet\discovery\DiscoveryCodec;
use altay\network\nethernet\discovery\DiscoveryMessagePacket;
use altay\network\nethernet\discovery\DiscoveryRequestPacket;
use altay\network\nethernet\discovery\DiscoveryResponsePacket;
use PHPUnit\Framework\TestCase;

final class DiscoveryCodecTest extends TestCase{

	public function testRequestRoundTrip() : void{
		$encoded = DiscoveryCodec::marshal(new DiscoveryRequestPacket(), 12345678901234);
		$result = DiscoveryCodec::unmarshal($encoded);
		self::assertNotNull($result);
		[$packet, $sender] = $result;
		self::assertInstanceOf(DiscoveryRequestPacket::class, $packet);
		self::assertSame(12345678901234, $sender);
	}

	public function testResponseRoundTrip() : void{
		$payload = random_bytes(40);
		$encoded = DiscoveryCodec::marshal(new DiscoveryResponsePacket($payload), 42);
		$result = DiscoveryCodec::unmarshal($encoded);
		self::assertNotNull($result);
		[$packet, $sender] = $result;
		self::assertInstanceOf(DiscoveryResponsePacket::class, $packet);
		self::assertSame($payload, $packet->applicationData);
		self::assertSame(42, $sender);
	}

	public function testMessageRoundTrip() : void{
		$encoded = DiscoveryCodec::marshal(new DiscoveryMessagePacket(777, "CONNECTREQUEST 5 data"), 42);
		$result = DiscoveryCodec::unmarshal($encoded);
		self::assertNotNull($result);
		[$packet, ] = $result;
		self::assertInstanceOf(DiscoveryMessagePacket::class, $packet);
		self::assertSame(777, $packet->recipientId);
		self::assertSame("CONNECTREQUEST 5 data", $packet->data);
	}

	public function testRejectsGarbage() : void{
		self::assertNull(DiscoveryCodec::unmarshal("garbage"));
		self::assertNull(DiscoveryCodec::unmarshal(str_repeat("\x00", 64)));
	}

	public function testRejectsTamperedChecksum() : void{
		$encoded = DiscoveryCodec::marshal(new DiscoveryRequestPacket(), 1);
		$encoded[0] = $encoded[0] === "\x00" ? "\x01" : "\x00";
		self::assertNull(DiscoveryCodec::unmarshal($encoded));
	}
}
