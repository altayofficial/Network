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

use altay\network\nethernet\PacketSerializer;
use function min;
use function strlen;

final class DiscoveryMessagePacket extends DiscoveryPacket{

	public const ID = 0x02;

	/**
	 * The longest a signal is declared, by clients and BDS alike. A longer one, an offer listing
	 * many candidates for example, simply runs on to the end of the packet.
	 */
	public const MAX_DECLARED_LENGTH = 1140;

	public function __construct(
		public int $recipientId = 0,
		public string $data = ""
	){}

	public function getId() : int{
		return self::ID;
	}

	public function encodePayload(PacketSerializer $out) : void{
		$out->putLLong($this->recipientId);
		//yeah, the length is a lie past the cap, but that is how retail writes it
		$out->putLInt(min(strlen($this->data), self::MAX_DECLARED_LENGTH));
		$out->put($this->data);
	}

	public function decodePayload(PacketSerializer $in) : void{
		$this->recipientId = $in->getLLong();
		$this->data = $in->getByteArray();
		//some clients send a few bytes past the length they declared, and they belong to the signal
		if(!$in->feof()){
			$this->data .= $in->getRemaining();
		}
	}
}
