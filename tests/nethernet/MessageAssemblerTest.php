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

	public function testUnreliableChannelAcceptsWholeMessages() : void{
		$assembler = self::assembler(false);

		self::assertSame("hello", $assembler->push("\x00hello"));
	}

	public function testUnreliableChannelAssemblesSegments() : void{
		$assembler = self::assembler(false);

		self::assertNull($assembler->push("\x01hel"));
		self::assertSame("hello", $assembler->push("\x00lo"));
	}

	public function testUnreliableChannelDropsWhatItCannotRead() : void{
		$assembler = self::assembler(false, maxSegmentSize: 8);

		self::assertNull($assembler->push(""));
		self::assertNull($assembler->push("\x00"));
		self::assertNull($assembler->push("\x00" . str_repeat("x", 9)));
		self::assertSame("ok", $assembler->push("\x00ok"));
	}

	public function testUnreliableChannelSkipsTheRestOfAGappedMessage() : void{
		$assembler = self::assembler(false);

		self::assertNull($assembler->push("\x03abc"));
		//segment 2 got lost
		self::assertNull($assembler->push("\x01def"));
		self::assertNull($assembler->push("\x00ghi"));
		self::assertSame("next", $assembler->push("\x00next"));
	}

	public function testUnreliableChannelRestartsOnAHigherCounter() : void{
		$assembler = self::assembler(false);

		self::assertNull($assembler->push("\x01abc"));
		//the tail of that message got lost and this one is a new message
		self::assertNull($assembler->push("\x01new"));
		self::assertSame("newer", $assembler->push("\x00er"));
	}

	public function testUnreliableChannelSkipsAPacketPastTheLimit() : void{
		$assembler = self::assembler(false, maxSegmentSize: 8, maxPacketSize: 16);

		self::assertNull($assembler->push("\x02" . str_repeat("a", 8)));
		self::assertNull($assembler->push("\x01" . str_repeat("b", 8)));
		self::assertNull($assembler->push("\x00c"));
		self::assertSame("d", $assembler->push("\x00d"));
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

	private static function assembler(bool $reliable, int $maxSegmentSize = 1024, int $maxPacketSize = 65536) : MessageAssembler{
		return new MessageAssembler($reliable, $maxSegmentSize, $maxPacketSize);
	}
}
