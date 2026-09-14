<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\sdp;

use altay\network\nethernet\sdp\CandidateInference;
use PHPUnit\Framework\TestCase;

final class CandidateInferenceTest extends TestCase{

	private function offer(string ...$candidates) : string{
		$sdp = "v=0\r\no=- 0 0 IN IP4 127.0.0.1\r\ns=-\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\n";
		foreach($candidates as $candidate){
			$sdp .= "a=candidate:$candidate\r\n";
		}
		return $sdp;
	}

	public function testGuessesTheSignallingAddressWithEveryPortTheOfferHolds() : void{
		$candidates = CandidateInference::reflexiveFor(
			$this->offer("1 1 udp 2130706431 192.168.1.5 50000 typ host", "2 1 udp 2130706431 10.0.0.9 50001 typ host"),
			"81.2.3.4"
		);

		self::assertCount(2, $candidates);
		self::assertSame("81.2.3.4", $candidates[0]->address);
		self::assertSame(50000, $candidates[0]->port);
		self::assertSame("srflx", $candidates[0]->type);
		self::assertSame(50001, $candidates[1]->port);
		self::assertNotSame($candidates[0]->foundation, $candidates[1]->foundation);
	}

	public function testGuessesNothingForAPeerThatOffersAReachableCandidate() : void{
		self::assertSame([], CandidateInference::reflexiveFor(
			$this->offer("1 1 udp 2130706431 192.168.1.5 50000 typ host", "2 1 udp 1694498815 81.2.3.9 50000 typ srflx"),
			"81.2.3.4"
		));
	}

	public function testGuessesNothingForAPeerOnThisNetwork() : void{
		self::assertSame([], CandidateInference::reflexiveFor(
			$this->offer("1 1 udp 2130706431 192.168.1.5 50000 typ host"),
			"192.168.1.5"
		));
	}

	public function testKeepsThePortsItGuessesAtToAHandful() : void{
		$candidates = [];
		for($i = 0; $i < 20; $i++){
			$candidates[] = "$i 1 udp 2130706431 192.168.1.5 " . (50000 + $i) . " typ host";
		}

		self::assertCount(8, CandidateInference::reflexiveFor($this->offer(...$candidates), "81.2.3.4"));
	}

	public function testIgnoresCandidatesThatAreNotUdp() : void{
		self::assertSame([], CandidateInference::reflexiveFor(
			$this->offer("1 1 tcp 2130706431 192.168.1.5 50000 typ host"),
			"81.2.3.4"
		));
	}

	/**
	 * @dataProvider unroutableAddresses
	 */
	public function testGuessesNothingFromAnAddressNobodyRoutes(string $address) : void{
		self::assertFalse(CandidateInference::isGlobalUnicast($address));
	}

	/**
	 * @return string[][]
	 */
	public static function unroutableAddresses() : array{
		return [
			["0.0.0.0"], ["10.1.2.3"], ["100.64.0.1"], ["127.0.0.1"], ["169.254.1.1"], ["172.16.0.1"],
			["192.168.1.1"], ["192.0.0.1"], ["192.0.2.1"], ["192.88.99.1"], ["198.18.0.1"],
			["198.51.100.1"], ["203.0.113.1"], ["224.0.0.1"], ["255.255.255.255"], ["not an address"],
			["::1"], ["fe80::1"], ["fd00::1"], ["2001:db8::1"], ["2002::1"], ["2001:1::1"], ["3fff::1"]
		];
	}

	/**
	 * @dataProvider routableAddresses
	 */
	public function testRecognisesAnAddressTheInternetRoutes(string $address) : void{
		self::assertTrue(CandidateInference::isGlobalUnicast($address));
	}

	/**
	 * @return string[][]
	 */
	public static function routableAddresses() : array{
		return [["81.2.3.4"], ["172.15.255.255"], ["100.128.0.1"], ["2001:4860:4860::8888"], ["2400::1"], ["::ffff:81.2.3.4"]];
	}
}
