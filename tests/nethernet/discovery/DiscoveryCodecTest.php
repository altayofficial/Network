<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\discovery;

use altay\network\nethernet\discovery\DiscoveryCodec;
use altay\network\nethernet\discovery\DiscoveryMessagePacket;
use altay\network\nethernet\discovery\DiscoveryRequestPacket;
use altay\network\nethernet\discovery\DiscoveryResponsePacket;
use altay\network\nethernet\DiscoveryCrypto;
use altay\network\nethernet\PacketSerializer;
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

	public function testMessageKeepsBytesPastItsDeclaredLength() : void{
		$body = new PacketSerializer();
		$body->putLShort(DiscoveryMessagePacket::ID);
		$body->putLLong(42);
		$body->put(str_repeat("\x00", 8));
		$body->putLLong(777);
		$body->putByteArray("CONNECTREQUEST 5 da");
		$body->put("ta");
		$payload = pack("v", strlen($body->getBuffer()) + 2) . $body->getBuffer();

		$result = DiscoveryCodec::unmarshal(DiscoveryCrypto::checksum($payload) . DiscoveryCrypto::encrypt($payload));
		self::assertNotNull($result);
		[$packet, ] = $result;
		self::assertInstanceOf(DiscoveryMessagePacket::class, $packet);
		self::assertSame("CONNECTREQUEST 5 data", $packet->data);
	}

	public function testLongMessageDeclaresTheCappedLengthsAndStillRoundTrips() : void{
		$signal = "CONNECTREQUEST 5 " . str_repeat("v=0\r\n", 400);
		$encoded = DiscoveryCodec::marshal(new DiscoveryMessagePacket(777, $signal), 42);

		$payload = DiscoveryCrypto::decrypt(substr($encoded, 32));
		self::assertNotNull($payload);
		self::assertSame(1172, unpack("v", $payload)[1]);
		//length prefix, id, sender, padding and recipient come before the signal's own length
		self::assertSame(DiscoveryMessagePacket::MAX_DECLARED_LENGTH, unpack("V", $payload, 2 + 2 + 8 + 8 + 8)[1]);

		$result = DiscoveryCodec::unmarshal($encoded);
		self::assertNotNull($result);
		[$packet, ] = $result;
		self::assertInstanceOf(DiscoveryMessagePacket::class, $packet);
		self::assertSame($signal, $packet->data);
	}

	public function testUnwrapsTheSequencedEnvelopeBdsSendsSignalsIn() : void{
		$inner = new PacketSerializer();
		$inner->putLShort(DiscoveryMessagePacket::ID);
		$inner->putLLong(42);
		$inner->put("\x01" . str_repeat("\x00", 7));
		$inner->putLLong(777);
		$inner->putByteArray("CONNECTRESPONSE 5 data");

		//laid out the way 1.26.70.24 sends it: recipient, a value fixed per sender, then the sequence
		$body = new PacketSerializer();
		$body->putLShort(0x03);
		$body->putLLong(42);
		$body->put("\x01" . str_repeat("\x00", 7));
		$body->putLLong(777);
		$body->put((string) hex2bin("f56332b6488da861"));
		$body->putLLong(4);
		$body->put(pack("v", strlen($inner->getBuffer()) + 2) . $inner->getBuffer());
		$payload = pack("v", strlen($body->getBuffer()) + 2) . $body->getBuffer();

		$result = DiscoveryCodec::unmarshal(DiscoveryCrypto::checksum($payload) . DiscoveryCrypto::encrypt($payload));
		self::assertNotNull($result);
		[$packet, $sender] = $result;
		self::assertInstanceOf(DiscoveryMessagePacket::class, $packet);
		self::assertSame(777, $packet->recipientId);
		self::assertSame("CONNECTRESPONSE 5 data", $packet->data);
		self::assertSame(42, $sender);
	}

	public function testRejectsAnEnvelopeInsideAnEnvelope() : void{
		$body = new PacketSerializer();
		$body->putLShort(0x03);
		$body->putLLong(42);
		$body->put(str_repeat("\x00", 8 + 24));
		$body->putLShort(0);
		$body->putLShort(0x03);
		$body->putLLong(42);
		$body->put(str_repeat("\x00", 8 + 24));
		$payload = pack("v", strlen($body->getBuffer()) + 2) . $body->getBuffer();

		self::assertNull(DiscoveryCodec::unmarshal(DiscoveryCrypto::checksum($payload) . DiscoveryCrypto::encrypt($payload)));
	}

	public function testRejectsGarbage() : void{
		self::assertNull(DiscoveryCodec::unmarshal("garbage"));
		self::assertNull(DiscoveryCodec::unmarshal(str_repeat("\x00", 64)));
	}

	public function testRejectsCiphertextTheCipherCouldNotHaveProduced() : void{
		//no block cipher output is a partial block, so these never reach the cipher or the MAC
		self::assertNull(DiscoveryCodec::unmarshal(str_repeat("\x00", 32)));
		self::assertNull(DiscoveryCodec::unmarshal(str_repeat("\x00", 32 + 17)));
		self::assertNull(DiscoveryCodec::unmarshal(str_repeat("\x00", 32 + 64 * 1024 + 16)));
	}

	public function testRejectsTamperedChecksum() : void{
		$encoded = DiscoveryCodec::marshal(new DiscoveryRequestPacket(), 1);
		$encoded[0] = $encoded[0] === "\x00" ? "\x01" : "\x00";
		self::assertNull(DiscoveryCodec::unmarshal($encoded));
	}
}
