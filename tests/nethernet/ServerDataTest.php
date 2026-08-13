<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use altay\network\nethernet\ServerData;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use PHPUnit\Framework\TestCase;

final class ServerDataTest extends TestCase{

	public function testEncodesReadableFields() : void{
		$data = new ServerData("Altay", "World", ServerData::GAME_TYPE_CREATIVE, 3, 10);
		$in = new BinaryStream($data->encode());

		self::assertSame(ServerData::VERSION, $in->getByte());
		self::assertSame("Altay", $in->get($in->getUnsignedVarInt()));
		self::assertSame("World", $in->get($in->getUnsignedVarInt()));
		self::assertSame(ServerData::GAME_TYPE_CREATIVE, $in->getVarInt());
		self::assertSame(3, $in->getLInt());
		self::assertSame(10, $in->getLInt());
	}

	public function testDefaultPlayerCountIsZero() : void{
		self::assertSame(0, (new ServerData())->playerCount);
	}

	public function testGeneratesNonceWhenNoneGiven() : void{
		$data = new ServerData();
		self::assertMatchesRegularExpression("/^[0-9a-f]{32}$/", $data->nonce);
		self::assertNotSame($data->nonce, (new ServerData())->nonce);
	}

	public function testRoundTrip() : void{
		$data = new ServerData("Altay", "World", ServerData::GAME_TYPE_ADVENTURE, 3, 10, true, true, true, false, "abcdef");
		$decoded = ServerData::decode($data->encode());

		self::assertEquals($data, $decoded);
	}

	public function testDecodeRejectsUnknownVersion() : void{
		$this->expectException(BinaryDataException::class);
		ServerData::decode("\xff");
	}
}
