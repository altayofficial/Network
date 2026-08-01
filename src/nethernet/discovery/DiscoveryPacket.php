<?php

declare(strict_types=1);

namespace altay\network\nethernet\discovery;

use pocketmine\utils\BinaryStream;

abstract class DiscoveryPacket{

	abstract public function getId() : int;

	abstract public function encodePayload(BinaryStream $out) : void;

	abstract public function decodePayload(BinaryStream $in) : void;
}
