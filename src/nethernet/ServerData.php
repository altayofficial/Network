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
use pocketmine\utils\BinaryStream;

final class ServerData{

	public const VERSION = 7;

	public const GAME_TYPE_SURVIVAL = 0; // TODO: we have this, kinda duplicate code
	public const GAME_TYPE_CREATIVE = 1;
	public const GAME_TYPE_ADVENTURE = 2;

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
	){}

	public function encode() : string{
		$out = new BinaryStream();
		$out->putByte(self::VERSION);
		self::putString($out, $this->serverName);
		self::putString($out, $this->levelName);
		$out->putVarInt($this->gameType);
		$out->putLInt($this->playerCount);
		$out->putLInt($this->maxPlayerCount);
		$out->putBool($this->editorWorld);
		$out->putBool($this->hardcore);
		$out->putBool($this->acceptsOnlineAuth);
		$out->putBool($this->acceptsSelfSignedAuth);
        self::putString($out, $this->nonce);
		$out->putVarInt($this->transportLayer->value);
		$out->putVarInt($this->connectionType->value);
		return $out->getBuffer();
	}

	private static function putString(BinaryStream $out, string $str) : void{
		$out->putUnsignedVarInt(strlen($str));
		$out->put($str);
	}
}
