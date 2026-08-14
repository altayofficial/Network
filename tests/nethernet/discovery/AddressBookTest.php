<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\discovery;

use altay\network\nethernet\discovery\AddressBook;
use PHPUnit\Framework\TestCase;

final class AddressBookTest extends TestCase{

	public function testRemembersAnAddress() : void{
		$book = new AddressBook();
		$book->remember(42, "10.0.0.1", 7551, 1000);

		self::assertSame(["10.0.0.1", 7551], $book->lookup(42));
	}

	public function testUnknownNetworkReturnsNull() : void{
		self::assertNull((new AddressBook())->lookup(42));
	}

	public function testLaterSightingReplacesTheAddress() : void{
		$book = new AddressBook();
		$book->remember(42, "10.0.0.1", 7551, 1000);
		$book->remember(42, "10.0.0.2", 7552, 1010);

		self::assertSame(["10.0.0.2", 7552], $book->lookup(42));
		self::assertSame(1, $book->count());
	}

	public function testEntriesExpire() : void{
		$book = new AddressBook(60);
		$book->remember(42, "10.0.0.1", 7551, 1000);

		$book->expire(1059);
		self::assertNotNull($book->lookup(42));

		$book->expire(1060);
		self::assertNull($book->lookup(42));
	}

	public function testBeingSeenAgainRefreshesTheDeadline() : void{
		$book = new AddressBook(60);
		$book->remember(42, "10.0.0.1", 7551, 1000);
		$book->remember(42, "10.0.0.1", 7551, 1050);

		$book->expire(1100);
		self::assertNotNull($book->lookup(42));
	}

	public function testExpiryOnlyRemovesStaleEntries() : void{
		$book = new AddressBook(60);
		$book->remember(1, "10.0.0.1", 7551, 1000);
		$book->remember(2, "10.0.0.2", 7551, 1050);

		$book->expire(1070);

		self::assertNull($book->lookup(1));
		self::assertNotNull($book->lookup(2));
		self::assertSame([2], $book->networkIds());
	}

	public function testForgetRemovesAnEntry() : void{
		$book = new AddressBook();
		$book->remember(42, "10.0.0.1", 7551, 1000);
		$book->forget(42);

		self::assertNull($book->lookup(42));
	}
}
