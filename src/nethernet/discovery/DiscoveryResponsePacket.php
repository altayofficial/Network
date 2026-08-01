<?php

declare(strict_types=1);

namespace altay\network\nethernet\discovery;

use pocketmine\utils\BinaryStream;

final class DiscoveryResponsePacket extends DiscoveryPacket{

	public const ID = 0x01;

	public function __construct(
		public string $applicationData = ""
	){}

	public function getId() : int{
		return self::ID;
	}

	public function encodePayload(BinaryStream $out) : void{
		$hex = bin2hex($this->applicationData);
		$out->putLInt(strlen($hex));
		$out->put($hex);
	}

	public function decodePayload(BinaryStream $in) : void{
		$length = $in->getLInt();
		$data = hex2bin($in->get($length));
		if($data === false){
			throw new \InvalidArgumentException("Invalid hex-encoded application data");
		}
		$this->applicationData = $data;
	}
}
