<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use altay\network\nethernet\MessageAssembler;
use altay\network\nethernet\MessageFormatException;
use PHPUnit\Framework\TestCase;

final class MessageAssemblerTest extends TestCase{

	public function testSingleSegmentIsReturnedImmediately() : void{
		$assembler = new MessageAssembler(true);

		self::assertSame("hello", $assembler->push("\x00hello"));
	}

	public function testSegmentsAreJoinedInOrder() : void{
		$assembler = new MessageAssembler(true);

		self::assertNull($assembler->push("\x02abc"));
		self::assertNull($assembler->push("\x01def"));
		self::assertSame("abcdefghi", $assembler->push("\x00ghi"));
	}

	public function testAssemblerIsReusedForTheNextPacket() : void{
		$assembler = new MessageAssembler(true);

		self::assertNull($assembler->push("\x01ab"));
		self::assertSame("abcd", $assembler->push("\x00cd"));
		self::assertSame("ef", $assembler->push("\x00ef"));
	}

	public function testRejectsBrokenSegmentSequence() : void{
		$assembler = new MessageAssembler(true);
		$assembler->push("\x03abc");

		$this->expectException(MessageFormatException::class);
		$this->expectExceptionMessage("Expected segment counter 2, got 7");
		$assembler->push("\x07def");
	}

	public function testRejectsRestartingSequenceMidPacket() : void{
		$assembler = new MessageAssembler(true);
		$assembler->push("\x01abc");

		$this->expectException(MessageFormatException::class);
		$assembler->push("\x01def");
	}

	public function testRejectsMessageWithoutPayload() : void{
		$assembler = new MessageAssembler(true);

		$this->expectException(MessageFormatException::class);
		$assembler->push("\x00");
	}

	public function testRejectsEmptyMessage() : void{
		$assembler = new MessageAssembler(true);

		$this->expectException(MessageFormatException::class);
		$assembler->push("");
	}

	public function testUnsegmentedChannelAcceptsWholeMessages() : void{
		$assembler = new MessageAssembler(false);

		self::assertSame("hello", $assembler->push("\x00hello"));
	}

	public function testUnsegmentedChannelRejectsSegments() : void{
		$assembler = new MessageAssembler(false);

		$this->expectException(MessageFormatException::class);
		$this->expectExceptionMessage("unsegmented channel");
		$assembler->push("\x01hello");
	}

	public function testCounterOf255IsAccepted() : void{
		$assembler = new MessageAssembler(true);

		self::assertNull($assembler->push("\xffa"));
		self::assertNull($assembler->push("\xfeb"));
	}

	public function testPayloadMayContainNullBytes() : void{
		$assembler = new MessageAssembler(true);

		self::assertSame("\x00\x01\x00", $assembler->push("\x00\x00\x01\x00"));
	}
}
