<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\sdp;

use altay\network\nethernet\sdp\IceCandidate;
use PHPUnit\Framework\TestCase;

final class IceCandidateTest extends TestCase{

	public function testParsesHostCandidate() : void{
		$candidate = IceCandidate::parse("a=candidate:1467250027 1 udp 2122260223 192.168.0.196 46243 typ host");

		self::assertNotNull($candidate);
		self::assertSame("1467250027", $candidate->foundation);
		self::assertSame("udp", $candidate->protocol);
		self::assertSame(2122260223, $candidate->priority);
		self::assertSame("192.168.0.196", $candidate->address);
		self::assertSame(46243, $candidate->port);
		self::assertSame("host", $candidate->type);
		self::assertNull($candidate->relatedAddress);
	}

	public function testParsesWithoutAttributePrefix() : void{
		self::assertNotNull(IceCandidate::parse("candidate:1 1 udp 100 10.0.0.1 5000 typ host"));
	}

	public function testParsesRelatedAddress() : void{
		$candidate = IceCandidate::parse("candidate:4 1 udp 1686052607 1.2.3.4 51772 typ srflx raddr 192.168.0.196 rport 51772");

		self::assertNotNull($candidate);
		self::assertSame("192.168.0.196", $candidate->relatedAddress);
		self::assertSame(51772, $candidate->relatedPort);
	}

	public function testRejectsNonCandidateLines() : void{
		self::assertNull(IceCandidate::parse("a=ice-ufrag:4ZcD"));
		self::assertNull(IceCandidate::parse("candidate:1 1 udp 100 10.0.0.1 5000 host"));
		self::assertNull(IceCandidate::parse("candidate:1 1 udp notanumber 10.0.0.1 5000 typ host"));
	}

	public function testFormatsLikeVanilla() : void{
		$host = IceCandidate::parse("a=candidate:1467250027 1 udp 2122260223 192.168.0.196 46243 typ host");
		self::assertNotNull($host);
		self::assertSame(
			"candidate:1467250027 1 udp 2122260223 192.168.0.196 46243 typ host generation 0 ufrag 4ZcD network-id 0 network-cost 0",
			$host->format(0, "4ZcD")
		);

		$srflx = IceCandidate::parse("candidate:4 1 udp 1686052607 1.2.3.4 51772 typ srflx raddr 192.168.0.196 rport 51772");
		self::assertNotNull($srflx);
		self::assertSame(
			"candidate:4 1 udp 1686052607 1.2.3.4 51772 typ srflx raddr 192.168.0.196 rport 51772 generation 0 ufrag 4ZcD network-id 2 network-cost 0",
			$srflx->format(2, "4ZcD")
		);
	}

	public function testFormatForcesComponentOne() : void{
		$candidate = IceCandidate::parse("candidate:1 2 udp 100 10.0.0.1 5000 typ host");

		self::assertNotNull($candidate);
		self::assertStringStartsWith("candidate:1 1 udp", $candidate->format(0, "x"));
	}

	public function testFormatOmitsRelatedAddressForHost() : void{
		$candidate = new IceCandidate("1", "udp", 100, "10.0.0.1", 5000, "host", "10.0.0.2", 6000);

		self::assertStringNotContainsString("raddr", $candidate->format(0, "x"));
	}

	public function testSdpValueDropsThePrefix() : void{
		$candidate = IceCandidate::parse("a=candidate:1467250027 1 udp 2122260223 192.168.0.196 46243 typ host generation 0 ufrag 4ZcD");

		self::assertNotNull($candidate);
		self::assertSame("1467250027 1 udp 2122260223 192.168.0.196 46243 typ host", $candidate->toSdpValue());
	}

	public function testSdpValueKeepsRelatedAddress() : void{
		$candidate = IceCandidate::parse("candidate:4 1 udp 168 1.2.3.4 51772 typ relay raddr 192.168.0.196 rport 51772");

		self::assertNotNull($candidate);
		self::assertSame("4 1 udp 168 1.2.3.4 51772 typ relay raddr 192.168.0.196 rport 51772", $candidate->toSdpValue());
	}

	public function testParseAllKeepsSdpOrder() : void{
		$sdp = implode("\r\n", [
			"v=0",
			"a=ice-ufrag:4ZcD",
			"a=candidate:1 1 udp 100 10.0.0.1 5000 typ host",
			"a=candidate:2 1 tcp 90 10.0.0.2 5001 typ host",
			"a=end-of-candidates"
		]);

		$candidates = IceCandidate::parseAll($sdp);
		self::assertCount(2, $candidates);
		self::assertSame("10.0.0.1", $candidates[0]->address);
		self::assertSame("10.0.0.2", $candidates[1]->address);
	}

	/**
	 * @dataProvider addressProvider
	 */
	public function testOnlyConnectableAddressesAreAccepted(string $address, bool $expected) : void{
		$candidate = IceCandidate::parse("a=candidate:1 1 udp 100 $address 5000 typ host");

		self::assertNotNull($candidate);
		self::assertSame($expected, $candidate->hasConnectableAddress());
	}

	/**
	 * @return array<string, array{string, bool}>
	 */
	public static function addressProvider() : array{
		return [
			"lan" => ["192.168.1.5", true],
			"public" => ["8.8.8.8", true],
			"public v6" => ["2001:db8::1", true],
			"mdns" => ["8bf1d2a0.local", true],
			"loopback" => ["127.0.0.1", false],
			"loopback v6" => ["::1", false],
			"link local" => ["169.254.1.2", false],
			"multicast" => ["224.0.0.1", false],
			"multicast v6" => ["ff02::1", false],
			"unspecified" => ["0.0.0.0", false],
			"hostname" => ["example.com", false]
		];
	}

	public function testPortOutOfRangeIsNotConnectable() : void{
		$candidate = IceCandidate::parse("a=candidate:1 1 udp 100 10.0.0.1 0 typ host");

		self::assertNotNull($candidate);
		self::assertFalse($candidate->hasConnectableAddress());
	}
}
