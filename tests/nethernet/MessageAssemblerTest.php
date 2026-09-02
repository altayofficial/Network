<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use altay\network\nethernet\MessageAssembler;
use altay\network\nethernet\MessageFormatException;
use PHPUnit\Framework\TestCase;

final class MessageAssemblerTest extends TestCase{

	public function testSingleSegmentIsReturnedImmediately() : void{
		$assembler = self::assembler(true);

		self::assertSame("hello", $assembler->push("\x00hello"));
	}

	public function testSegmentsAreJoinedInOrder() : void{
		$assembler = self::assembler(true);

		self::assertNull($assembler->push("\x02abc"));
		self::assertNull($assembler->push("\x01def"));
		self::assertSame("abcdefghi", $assembler->push("\x00ghi"));
	}

	public function testAssemblerIsReusedForTheNextPacket() : void{
		$assembler = self::assembler(true);

		self::assertNull($assembler->push("\x01ab"));
		self::assertSame("abcd", $assembler->push("\x00cd"));
		self::assertSame("ef", $assembler->push("\x00ef"));
	}

	public function testRejectsBrokenSegmentSequence() : void{
		$assembler = self::assembler(true);
		$assembler->push("\x03abc");

		$this->expectException(MessageFormatException::class);
		$this->expectExceptionMessage("Expected segment counter 2, got 7");
		$assembler->push("\x07def");
	}

	public function testRejectsRestartingSequenceMidPacket() : void{
		$assembler = self::assembler(true);
		$assembler->push("\x01abc");

		$this->expectException(MessageFormatException::class);
		$assembler->push("\x01def");
	}

	public function testRejectsMessageWithoutPayload() : void{
		$assembler = self::assembler(true);

		$this->expectException(MessageFormatException::class);
		$assembler->push("\x00");
	}

	public function testRejectsEmptyMessage() : void{
		$assembler = self::assembler(true);

		$this->expectException(MessageFormatException::class);
		$assembler->push("");
	}

	public function testUnsegmentedChannelAcceptsWholeMessages() : void{
		$assembler = self::assembler(false);

		self::assertSame("hello", $assembler->push("\x00hello"));
	}

	public function testUnsegmentedChannelRejectsSegments() : void{
		$assembler = self::assembler(false);

		$this->expectException(MessageFormatException::class);
		$this->expectExceptionMessage("unsegmented channel");
		$assembler->push("\x01hello");
	}

	public function testCounterOf255IsAccepted() : void{
		$assembler = self::assembler(true);

		self::assertNull($assembler->push("\xffa"));
		self::assertNull($assembler->push("\xfeb"));
	}

	public function testPayloadMayContainNullBytes() : void{
		$assembler = self::assembler(true);

		self::assertSame("\x00\x01\x00", $assembler->push("\x00\x00\x01\x00"));
	}

	public function testRejectsOversizedSegment() : void{
		$assembler = self::assembler(true, maxSegmentSize: 8);

		$this->expectException(MessageFormatException::class);
		$this->expectExceptionMessage("Received a 9 byte message, the limit is 8");
		$assembler->push("\x00" . str_repeat("x", 9));
	}

	public function testRejectsPacketGrowingPastTheLimit() : void{
		$assembler = self::assembler(true, maxSegmentSize: 8, maxPacketSize: 16);
		$assembler->push("\x02" . str_repeat("a", 8));
		$assembler->push("\x01" . str_repeat("b", 8));

		$this->expectException(MessageFormatException::class);
		$this->expectExceptionMessage("would exceed the 16 byte limit");
		$assembler->push("\x00c");
	}

	public function testPacketOfExactlyTheLimitIsAccepted() : void{
		$assembler = self::assembler(true, maxSegmentSize: 8, maxPacketSize: 16);

		self::assertNull($assembler->push("\x01" . str_repeat("a", 8)));
		self::assertSame(str_repeat("a", 8) . str_repeat("b", 8), $assembler->push("\x00" . str_repeat("b", 8)));
	}

	private static function assembler(bool $segmented, int $maxSegmentSize = 1024, int $maxPacketSize = 65536) : MessageAssembler{
		return new MessageAssembler($segmented, $maxSegmentSize, $maxPacketSize);
	}
}
