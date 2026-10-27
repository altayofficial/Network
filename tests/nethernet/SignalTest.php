<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use altay\network\nethernet\Signal;
use altay\network\nethernet\types\SignalErrorCode;
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

	public function testRejectsAMissingConnectionId() : void{
		self::assertNull(Signal::fromString("CONNECTREQUEST"));
		self::assertNull(Signal::fromString("CONNECTREQUEST  data"));
	}

	public function testAcceptsASignalWithoutPayload() : void{
		$parsed = Signal::fromString("CONNECTERROR 5");
		self::assertNotNull($parsed);
		self::assertSame("5", $parsed->connectionId);
		self::assertSame("", $parsed->data);
	}

	public function testKeepsTheConnectionIdExactlyAsSent() : void{
		self::assertSame("0000000005", Signal::fromString("CONNECTREQUEST 0000000005 data")?->connectionId);
		self::assertSame("abc-123", Signal::fromString("CONNECTREQUEST abc-123 data")?->connectionId);
		self::assertSame("99999999999999999999999", Signal::fromString("CONNECTREQUEST 99999999999999999999999 data")?->connectionId);
	}

	public function testRejectsAConnectionIdWithControlCharacters() : void{
		self::assertNull(Signal::fromString("CONNECTREQUEST a\x01b data"));
		self::assertNull(Signal::fromString("CONNECTREQUEST a\tb data"));
	}

	public function testKnowsTheIdentityErrorCodes() : void{
		self::assertSame(SignalErrorCode::FAILED_TO_CREATE_IDENTITY_ASSERTION, SignalErrorCode::tryFrom(36));
		self::assertSame(SignalErrorCode::IDENTITY_NOT_ALLOWED, SignalErrorCode::tryFrom(37));
		//the old name still resolves for anything written against it
		self::assertSame(SignalErrorCode::IDENTITY_NOT_ALLOWED, SignalErrorCode::IDENTITY_VERIFICATION_FAILED);
	}
}
