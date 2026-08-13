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

namespace altay\network\nethernet;

use altay\network\nethernet\types\ConnectionType;
use altay\network\nethernet\types\TransportLayer;
use pocketmine\utils\BinaryDataException;
use function bin2hex;
use function random_bytes;

final class ServerData{

	public const VERSION = 7;

	public const GAME_TYPE_SURVIVAL = 0; // TODO: we have this, kinda duplicate code
	public const GAME_TYPE_CREATIVE = 1;
	public const GAME_TYPE_ADVENTURE = 2;

	private const NONCE_LENGTH = 16;

	public function __construct(
		public string $serverName = "Altay",
		public string $levelName = "Altay Server",
		public int $gameType = self::GAME_TYPE_SURVIVAL,
		public int $playerCount = 0,
		public int $maxPlayerCount = 20,
		public bool $editorWorld = false,
		public bool $hardcore = false,
		public bool $acceptsOnlineAuth = false,
		public bool $acceptsSelfSignedAuth = true,
		public string $nonce = "",
		public TransportLayer $transportLayer = TransportLayer::NETHERNET,
		public ConnectionType $connectionType = ConnectionType::LAN_SIGNALING
	){
		if($this->nonce === ""){
			$this->nonce = self::generateNonce();
		}
	}

	/**
	 * Returns a hex-encoded random nonce. Clients echo the nonce advertised here back in the
	 * ClientData of their login, so it must stay the same for as long as the world is hosted.
	 */
	public static function generateNonce() : string{
		return bin2hex(random_bytes(self::NONCE_LENGTH));
	}

	public function encode() : string{
		$out = new PacketSerializer();
		$out->putByte(self::VERSION);
		$out->putString($this->serverName);
		$out->putString($this->levelName);
		$out->putVarInt($this->gameType);
		$out->putLInt($this->playerCount);
		$out->putLInt($this->maxPlayerCount);
		$out->putBool($this->editorWorld);
		$out->putBool($this->hardcore);
		$out->putBool($this->acceptsOnlineAuth);
		$out->putBool($this->acceptsSelfSignedAuth);
		$out->putString($this->nonce);
		$out->putVarInt($this->transportLayer->value);
		$out->putVarInt($this->connectionType->value);
		return $out->getBuffer();
	}

	/**
	 * @throws BinaryDataException
	 */
	public static function decode(string $buffer) : self{
		$in = new PacketSerializer($buffer);
		$version = $in->getByte();
		if($version !== self::VERSION){
			throw new BinaryDataException("Unsupported server data version $version");
		}

		$serverName = $in->getString();
		$levelName = $in->getString();
		$gameType = $in->getVarInt();
		$playerCount = $in->getLInt();
		$maxPlayerCount = $in->getLInt();
		$editorWorld = $in->getBool();
		$hardcore = $in->getBool();
		$acceptsOnlineAuth = $in->getBool();
		$acceptsSelfSignedAuth = $in->getBool();
		$nonce = $in->getString();
		$transportLayerId = $in->getVarInt();
		$connectionTypeId = $in->getVarInt();
		if(!$in->feof()){
			throw new BinaryDataException("Unread bytes left in server data");
		}

		$data = new self(
			$serverName,
			$levelName,
			$gameType,
			$playerCount,
			$maxPlayerCount,
			$editorWorld,
			$hardcore,
			$acceptsOnlineAuth,
			$acceptsSelfSignedAuth,
			$nonce,
			TransportLayer::tryFrom($transportLayerId) ?? throw new BinaryDataException("Unknown transport layer $transportLayerId"),
			ConnectionType::tryFrom($connectionTypeId) ?? throw new BinaryDataException("Unknown connection type $connectionTypeId")
		);
		//an empty nonce would otherwise be replaced by a freshly generated one in the constructor
		$data->nonce = $nonce;
		return $data;
	}
}
