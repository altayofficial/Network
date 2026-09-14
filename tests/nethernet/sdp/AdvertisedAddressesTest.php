<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\sdp;

use altay\network\nethernet\sdp\AdvertisedAddresses;
use altay\network\tests\nethernet\DiscardingLogger;
use PHPUnit\Framework\TestCase;

final class AdvertisedAddressesTest extends TestCase{

	private function logger() : \Logger{
		return new DiscardingLogger();
	}

	private function answer(string ...$addresses) : string{
		$sdp = "v=0\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\n";
		$port = 50000;
		foreach($addresses as $address){
			$sdp .= "a=candidate:1 1 udp 2130706431 $address " . $port++ . " typ host\r\n";
		}
		return $sdp;
	}

	public function testAllowsEverythingWhenNothingIsConfigured() : void{
		$advertised = new AdvertisedAddresses([]);

		self::assertTrue($advertised->isEmpty());
		self::assertTrue($advertised->allows("10.0.0.1"));
	}

	public function testMatchesTheSameAddressWrittenTwoWays() : void{
		$advertised = new AdvertisedAddresses(["2001:db8:0:0:0:0:0:1"]);

		self::assertTrue($advertised->allows("2001:db8::1"));
		self::assertFalse($advertised->allows("2001:db8::2"));
	}

	public function testDropsTheCandidatesOnAddressesItWasNotGiven() : void{
		$filtered = (new AdvertisedAddresses(["81.2.3.4"]))->filter($this->answer("81.2.3.4", "172.17.0.1"), $this->logger());

		self::assertStringContainsString("81.2.3.4", $filtered);
		self::assertStringNotContainsString("172.17.0.1", $filtered);
	}

	public function testLeavesEverythingElseInTheDescriptionAlone() : void{
		$sdp = $this->answer("81.2.3.4", "172.17.0.1");
		$filtered = (new AdvertisedAddresses(["81.2.3.4"]))->filter($sdp, $this->logger());

		self::assertStringStartsWith("v=0\r\nm=application", $filtered);
	}

	public function testKeepsEveryCandidateWhenNoneOfThemMatch() : void{
		$sdp = $this->answer("172.17.0.1", "10.0.0.5");

		self::assertSame($sdp, (new AdvertisedAddresses(["81.2.3.4"]))->filter($sdp, $this->logger()));
	}

	public function testIgnoresAnAddressItCannotRead() : void{
		self::assertTrue((new AdvertisedAddresses(["localhost"]))->isEmpty());
	}
}
