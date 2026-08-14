<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\sdp;

use altay\network\nethernet\sdp\AnswerRewriter;
use altay\network\nethernet\sdp\SessionDescription;
use PHPUnit\Framework\TestCase;

final class AnswerRewriterTest extends TestCase{

	private const LIBRARY_ANSWER = "v=0\r\n" .
		"o=- 499453075625 499453075625 IN IP4 0.0.0.0\r\n" .
		"s=-\r\n" .
		"t=0 0\r\n" .
		"a=group:BUNDLE 0\r\n" .
		"a=msid-semantic:WMS *\r\n" .
		"m=application 36595 UDP/DTLS/SCTP webrtc-datachannel\r\n" .
		"c=IN IP4 192.168.1.29\r\n" .
		"a=mid:0\r\n" .
		"a=sctp-port:5000\r\n" .
		"a=max-message-size:65536\r\n" .
		"a=candidate:15cca1b8 1 udp 2130706431 192.168.1.29 36595 typ host\r\n" .
		"a=end-of-candidates\r\n" .
		"a=ice-ufrag:yhbK\r\n" .
		"a=ice-pwd:jcX20ruFUd989CVm0T5bbr\r\n" .
		"a=fingerprint:sha-256 6B:E9:9E:1E\r\n" .
		"a=setup:active\r\n";

	public function testRaisesMaxMessageSize() : void{
		$conformed = AnswerRewriter::conform(self::LIBRARY_ANSWER);

		self::assertSame("262144", SessionDescription::attribute($conformed, "max-message-size"));
		self::assertStringNotContainsString("65536", $conformed);
	}

	public function testAddsIceOptions() : void{
		$conformed = AnswerRewriter::conform(self::LIBRARY_ANSWER);

		self::assertSame("trickle", SessionDescription::attribute($conformed, "ice-options"));
	}

	public function testAddsExtmapAllowMixedAboveTheMediaSection() : void{
		$conformed = AnswerRewriter::conform(self::LIBRARY_ANSWER);

		self::assertStringContainsString("a=extmap-allow-mixed", SessionDescription::sessionSection($conformed));
	}

	public function testPreservesEverythingElse() : void{
		$conformed = AnswerRewriter::conform(self::LIBRARY_ANSWER);

		self::assertSame("yhbK", SessionDescription::attribute($conformed, "ice-ufrag"));
		self::assertSame("active", SessionDescription::attribute($conformed, "setup"));
		self::assertSame("0", SessionDescription::attribute($conformed, "mid"));
		self::assertSame("5000", SessionDescription::attribute($conformed, "sctp-port"));
		self::assertStringContainsString("m=application 36595 UDP/DTLS/SCTP webrtc-datachannel", $conformed);
		self::assertCount(1, SessionDescription::attributes($conformed, "candidate"));
	}

	public function testKeepsLineEndingsAndTrailingBreak() : void{
		$conformed = AnswerRewriter::conform(self::LIBRARY_ANSWER);

		self::assertStringEndsWith("\r\n", $conformed);
		self::assertStringNotContainsString("\r\r", $conformed);
		self::assertSame(substr_count($conformed, "\r\n"), substr_count($conformed, "\n"));
	}

	public function testIsIdempotent() : void{
		$once = AnswerRewriter::conform(self::LIBRARY_ANSWER);

		self::assertSame($once, AnswerRewriter::conform($once));
	}

	public function testAcceptsBareNewlines() : void{
		$conformed = AnswerRewriter::conform(str_replace("\r\n", "\n", self::LIBRARY_ANSWER));

		self::assertStringNotContainsString("\r", $conformed);
		self::assertSame("262144", SessionDescription::attribute($conformed, "max-message-size"));
	}
}
