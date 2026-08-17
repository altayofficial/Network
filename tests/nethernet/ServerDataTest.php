<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use altay\network\nethernet\ServerData;
use altay\network\nethernet\types\ConnectionType;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use PHPUnit\Framework\TestCase;

final class ServerDataTest extends TestCase{

	public function testEncodesReadableFields() : void{
		$data = new ServerData("Altay", 2187, "1.26.50", "World", ServerData::GAME_TYPE_CREATIVE, 3, 10);
		$in = new BinaryStream($data->encode());

		self::assertSame(ServerData::VERSION, $in->getByte());
		self::assertSame("Altay", $in->get($in->getUnsignedVarInt()));
		self::assertSame(2187, $in->getVarInt());
		self::assertSame("1.26.50", $in->get($in->getUnsignedVarInt()));
		self::assertSame("World", $in->get($in->getUnsignedVarInt()));
		self::assertSame(3, $in->getVarInt());
		self::assertSame(10, $in->getVarInt());
		self::assertSame(ServerData::GAME_TYPE_CREATIVE, $in->getVarInt());
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
		$data = new ServerData("Altay", 2187, "1.26.50", "World", ServerData::GAME_TYPE_ADVENTURE, 3, 10, true, true, true, false, "abcdef");
		$decoded = ServerData::decode($data->encode());

		self::assertEquals($data, $decoded);
	}

	/**
	 * The application data of a discovery response from a vanilla 1.26.50.24 dedicated server,
	 * hosting a creative world with a 13 player limit.
	 */
	public function testDecodesVanillaServerData() : void{
		$data = ServerData::decode(hex2bin(
			"0710446564696361746564205365727665728a2207312e32362e3530" .
			"0c437265617469766554657374001a0200000001103865656232666530633365336632643808"
		));

		self::assertSame("Dedicated Server", $data->serverName);
		self::assertSame(2181, $data->protocol);
		self::assertSame("1.26.50", $data->gameVersion);
		self::assertSame("CreativeTest", $data->levelName);
		self::assertSame(0, $data->playerCount);
		self::assertSame(13, $data->maxPlayerCount);
		self::assertSame(ServerData::GAME_TYPE_CREATIVE, $data->gameType);
		self::assertSame("8eeb2fe0c3e3f2d8", $data->nonce);
		self::assertSame(ConnectionType::LAN_SIGNALING, $data->connectionType);
	}

	public function testDecodeRejectsUnknownVersion() : void{
		$this->expectException(BinaryDataException::class);
		ServerData::decode("\xff");
	}
}
