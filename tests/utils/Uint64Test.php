<?php

declare(strict_types=1);

namespace altay\network\tests\utils;

use altay\network\utils\Uint64;
use PHPUnit\Framework\TestCase;

final class Uint64Test extends TestCase{

	public function testZero() : void{
		self::assertSame(0, Uint64::toSignedInt("0"));
	}

	public function testMaxSignedStaysPositive() : void{
		self::assertSame(PHP_INT_MAX, Uint64::toSignedInt("9223372036854775807"));
	}

	public function testWrapsAroundSignedBoundary() : void{
		self::assertSame(PHP_INT_MIN, Uint64::toSignedInt("9223372036854775808"));
	}

	public function testUnsignedMaxIsMinusOne() : void{
		self::assertSame(-1, Uint64::toSignedInt("18446744073709551615"));
	}

	public function testArbitraryLargeValue() : void{
		self::assertSame(-6101065172474983726, Uint64::toSignedInt("12345678901234567890"));
	}

	public function testRejectsOverflow() : void{
		$this->expectException(\InvalidArgumentException::class);
		Uint64::toSignedInt("18446744073709551616");
	}

	public function testRejectsNegative() : void{
		$this->expectException(\InvalidArgumentException::class);
		Uint64::toSignedInt("-1");
	}
}
