<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use altay\network\nethernet\Signal;
use PHPUnit\Framework\TestCase;

final class SignalTest extends TestCase{

	public function testRoundTrip() : void{
		$signal = new Signal(Signal::TYPE_OFFER, "18446744073709551615", "sdp payload with spaces");
		$parsed = Signal::fromString((string) $signal);
		self::assertNotNull($parsed);
		self::assertSame(Signal::TYPE_OFFER, $parsed->type);
		self::assertSame("18446744073709551615", $parsed->connectionId);
		self::assertSame("sdp payload with spaces", $parsed->data);
	}

	public function testDataMayContainSpaces() : void{
		$parsed = Signal::fromString("CANDIDATEADD 5 a b c");
		self::assertNotNull($parsed);
		self::assertSame("a b c", $parsed->data);
	}

	public function testRejectsMissingFields() : void{
		self::assertNull(Signal::fromString("CONNECTREQUEST 5"));
	}

	public function testRejectsNonNumericConnectionId() : void{
		self::assertNull(Signal::fromString("CONNECTREQUEST notanumber data"));
	}

	public function testRejectsConnectionIdAboveUint64() : void{
		self::assertNull(Signal::fromString("CONNECTREQUEST 18446744073709551616 data"));
		self::assertNull(Signal::fromString("CONNECTREQUEST 99999999999999999999999 data"));
	}

	public function testNormalisesLeadingZeroesInConnectionId() : void{
		$parsed = Signal::fromString("CONNECTREQUEST 0000000005 data");
		self::assertNotNull($parsed);
		self::assertSame("5", $parsed->connectionId);
	}
}
