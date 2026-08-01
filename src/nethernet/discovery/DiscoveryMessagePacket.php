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
