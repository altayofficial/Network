<?php

declare(strict_types=1);

namespace altay\network\nethernet;

use pocketmine\utils\BinaryStream;

final class ServerData{

	public const VERSION = 5;

	public const GAME_TYPE_SURVIVAL = 0;
	public const GAME_TYPE_CREATIVE = 1;
	public const GAME_TYPE_ADVENTURE = 2;

	public const TRANSPORT_LAYER_RAKNET = 0;
	public const TRANSPORT_LAYER_NETHERNET = 2;

	public const CONNECTION_TYPE_LAN_SIGNALING = 4;

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
		public int $transportLayer = self::TRANSPORT_LAYER_NETHERNET, // wtf? mojang
		public int $connectionType = self::CONNECTION_TYPE_LAN_SIGNALING
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
		$out->putVarInt($this->transportLayer);
		$out->putVarInt($this->connectionType);
		return $out->getBuffer();
	}

	private static function putString(BinaryStream $out, string $str) : void{
		$out->putUnsignedVarInt(strlen($str));
		$out->put($str);
	}
}
