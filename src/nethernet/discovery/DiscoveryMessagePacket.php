<?php

declare(strict_types=1);

namespace altay\network\nethernet\discovery;

use pocketmine\utils\BinaryStream;

final class DiscoveryMessagePacket extends DiscoveryPacket{

	public const ID = 0x02;

	public function __construct(
		public int $recipientId = 0,
		public string $data = ""
	){}

	public function getId() : int{
		return self::ID;
	}

	public function encodePayload(BinaryStream $out) : void{
		$out->putLLong($this->recipientId);
		$out->putLInt(strlen($this->data));
		$out->put($this->data);
	}

	public function decodePayload(BinaryStream $in) : void{
		$this->recipientId = $in->getLLong();
		$this->data = $in->get($in->getLInt());
	}
}
