<?php

/*
 *
 *      _    _ _
 *     / \  | | |_ __ _ _   _
 *    / _ \ | | __/ _` | | | |
 *   / ___ \| | || (_| | |_| |
 *  /_/   \_\_|\__\__,_|\__, |
 *                       |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Original work by the PocketMine Team.
 * https://www.pocketmine.net/
 *
 * @author Altay Team
 * @link https://github.com/altayofficial
 */

declare(strict_types=1);

namespace altay\network\nethernet\endpoint;

use altay\network\nethernet\ServerData;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;

/**
 * The status a server advertises over its signalling endpoint, the HTTP counterpart of the
 * ServerData sent in a LAN discovery response.
 */
final class EndpointStatus{

	public function __construct(
		public string $serverName,
		public int $protocol,
		public string $gameVersion,
		public string $levelName,
		public int $playerCount,
		public int $maxPlayerCount,
		public int $gameType
	){}

	public static function fromServerData(ServerData $data) : self{
		return new self(
			$data->serverName,
			$data->protocol,
			$data->gameVersion,
			$data->levelName,
			$data->playerCount,
			$data->maxPlayerCount,
			$data->gameType
		);
	}

	/**
	 * @throws EndpointException
	 */
	public static function fromJson(string $json) : self{
		$decoded = json_decode($json, true);
		if(!is_array($decoded)){
			throw new EndpointException("Endpoint status is not a JSON object");
		}
		return new self(
			self::readString($decoded, "name"),
			self::readInt($decoded, "protocol"),
			self::readString($decoded, "version"),
			self::readString($decoded, "level"),
			self::readInt($decoded, "players"),
			self::readInt($decoded, "maxPlayers"),
			self::readInt($decoded, "gameType")
		);
	}

	public function toJson() : string{
		$json = json_encode([
			"name" => $this->serverName,
			"protocol" => $this->protocol,
			"version" => $this->gameVersion,
			"level" => $this->levelName,
			"players" => $this->playerCount,
			"maxPlayers" => $this->maxPlayerCount,
			"gameType" => $this->gameType
		]);
		if($json === false){
			throw new \RuntimeException("Failed to encode endpoint status");
		}
		return $json;
	}

	/**
	 * @param mixed[] $data
	 *
	 * @throws EndpointException
	 */
	private static function readString(array $data, string $key) : string{
		$value = $data[$key] ?? null;
		if(!is_string($value)){
			throw new EndpointException("Endpoint status field \"$key\" is not a string");
		}
		return $value;
	}

	/**
	 * @param mixed[] $data
	 *
	 * @throws EndpointException
	 */
	private static function readInt(array $data, string $key) : int{
		$value = $data[$key] ?? null;
		if(!is_int($value)){
			throw new EndpointException("Endpoint status field \"$key\" is not an integer");
		}
		return $value;
	}
}
