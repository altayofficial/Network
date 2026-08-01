<?php

declare(strict_types=1);

namespace altay\network\nethernet\discovery;

use pocketmine\utils\BinaryStream;

final class DiscoveryRequestPacket extends DiscoveryPacket{

	public const ID = 0x00;

	public function getId() : int{
		return self::ID;
	}

	public function encodePayload(BinaryStream $out) : void{

	}

	public function decodePayload(BinaryStream $in) : void{

	}
}
