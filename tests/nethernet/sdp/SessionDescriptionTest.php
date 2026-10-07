<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\sdp;

use altay\network\nethernet\sdp\IceCandidate;
use altay\network\nethernet\sdp\SessionDescription;
use PHPUnit\Framework\TestCase;

final class SessionDescriptionTest extends TestCase{

	private const OFFER = "v=0\r\n" .
		"o=- 1 2 IN IP4 127.0.0.1\r\n" .
		"a=group:BUNDLE 0\r\n" .
		"a=candidate:9 1 udp 111 9.9.9.9 9999 typ host\r\n" .
		"m=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\n" .
		"a=ice-ufrag:4ZcD\r\n" .
		"a=ice-pwd:secret\r\n" .
		"a=candidate:1 1 udp 100 10.0.0.1 5000 typ host\r\n" .
		"a=candidate:2 1 udp 90 10.0.0.2 5001 typ host\r\n";

	public function testAttributeReturnsFirstMatch() : void{
		self::assertSame("4ZcD", SessionDescription::attribute(self::OFFER, "ice-ufrag"));
		self::assertSame("secret", SessionDescription::attribute(self::OFFER, "ice-pwd"));
	}

	public function testAttributeReturnsNullWhenAbsent() : void{
		self::assertNull(SessionDescription::attribute(self::OFFER, "fingerprint"));
	}

	public function testAttributesReturnsEveryMatch() : void{
		self::assertCount(3, SessionDescription::attributes(self::OFFER, "candidate"));
	}

	public function testSessionSectionStopsAtTheFirstMediaLine() : void{
		$section = SessionDescription::sessionSection(self::OFFER);

		self::assertStringContainsString("a=group:BUNDLE 0", $section);
		self::assertStringNotContainsString("m=application", $section);
		self::assertStringNotContainsString("a=ice-ufrag", $section);
	}

	public function testSessionSectionIsolatesSessionLevelCandidates() : void{
		$candidates = IceCandidate::parseAll(SessionDescription::sessionSection(self::OFFER));

		self::assertCount(1, $candidates);
		self::assertSame("9.9.9.9", $candidates[0]->address);
	}

	public function testWithoutCandidatesKeepsEverythingElse() : void{
		$sdp = "v=0\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\na=candidate:1 1 udp 1 10.0.0.1 5000 typ host\r\na=end-of-candidates\r\na=ice-ufrag:x\r\n";

		self::assertSame("v=0\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\na=ice-ufrag:x\r\n", SessionDescription::withoutCandidates($sdp));
	}

	public function testMaxMessageSizeComesFromTheDescription() : void{
		self::assertSame(65536, SessionDescription::maxMessageSize("v=0\r\na=max-message-size:65536\r\n"));
		self::assertSame(0, SessionDescription::maxMessageSize("v=0\r\na=max-message-size:0\r\n"));
	}

	public function testMissingMaxMessageSizeMeansTheSctpMinimum() : void{
		self::assertSame(SessionDescription::DEFAULT_MAX_MESSAGE_SIZE, SessionDescription::maxMessageSize("v=0\r\na=ice-ufrag:x\r\n"));
		self::assertSame(SessionDescription::DEFAULT_MAX_MESSAGE_SIZE, SessionDescription::maxMessageSize("v=0\r\na=max-message-size:lots\r\n"));
	}
}
