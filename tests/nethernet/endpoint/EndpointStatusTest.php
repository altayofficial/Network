<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\endpoint;

use altay\network\nethernet\endpoint\EndpointException;
use altay\network\nethernet\endpoint\EndpointStatus;
use altay\network\nethernet\ServerData;
use PHPUnit\Framework\TestCase;

final class EndpointStatusTest extends TestCase{

	public function testTakesEveryAdvertisedFieldFromTheServerData() : void{
		$status = EndpointStatus::fromServerData(new ServerData("Altay", 2187, "1.26.50", "World", ServerData::GAME_TYPE_CREATIVE, 3, 10));

		self::assertSame("Altay", $status->serverName);
		self::assertSame(2187, $status->protocol);
		self::assertSame("1.26.50", $status->gameVersion);
		self::assertSame("World", $status->levelName);
		self::assertSame(3, $status->playerCount);
		self::assertSame(10, $status->maxPlayerCount);
		self::assertSame(ServerData::GAME_TYPE_CREATIVE, $status->gameType);
	}

	public function testUsesTheFieldNamesVanillaSends() : void{
		$json = json_decode((new EndpointStatus("Altay", 2187, "1.26.50", "World", 3, 10, ServerData::GAME_TYPE_CREATIVE))->toJson(), true);

		self::assertSame([
			"name" => "Altay",
			"protocol" => 2187,
			"version" => "1.26.50",
			"level" => "World",
			"players" => 3,
			"maxPlayers" => 10,
			"gameType" => ServerData::GAME_TYPE_CREATIVE
		], $json);
	}

	public function testRoundTrip() : void{
		$status = new EndpointStatus("Altay", 2187, "1.26.50", "World", 3, 10, ServerData::GAME_TYPE_CREATIVE);

		self::assertEquals($status, EndpointStatus::fromJson($status->toJson()));
	}

	public function testRejectsAMissingField() : void{
		$this->expectException(EndpointException::class);
		EndpointStatus::fromJson('{"name":"Altay","version":"1.26.50","level":"World","players":3,"maxPlayers":10,"gameType":0}');
	}

	public function testRejectsAFieldOfTheWrongType() : void{
		$this->expectException(EndpointException::class);
		EndpointStatus::fromJson('{"name":"Altay","protocol":"2187","version":"1.26.50","level":"World","players":3,"maxPlayers":10,"gameType":0}');
	}

	public function testRejectsAJsonScalar() : void{
		$this->expectException(EndpointException::class);
		EndpointStatus::fromJson('"nope"');
	}
}
