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

use altay\network\utils\PacketSerializerInterface;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use function bin2hex;
use function hex2bin;
use function strlen;

final class PacketSerializer extends BinaryStream implements PacketSerializerInterface{

	public const MAX_PAYLOAD_LENGTH = 0xffff;

	/**
	 * @throws BinaryDataException
	 */
	public function getString() : string{
		return $this->get($this->getUnsignedVarInt());
	}

	public function putString(string $v) : void{
		$this->putUnsignedVarInt(strlen($v));
		$this->put($v);
	}

	/**
	 * Reads a byte array prefixed with a little-endian uint32 length.
	 *
	 * @throws BinaryDataException
	 */
	public function getByteArray() : string{
		$length = $this->getLInt();
		if($length < 0 || $length > self::MAX_PAYLOAD_LENGTH){
			throw new BinaryDataException("Byte array length $length is out of bounds");
		}
		return $this->get($length);
	}

	public function putByteArray(string $v) : void{
		$this->putLInt(strlen($v));
		$this->put($v);
	}

	/**
	 * @throws BinaryDataException
	 */
	public function getHexByteArray() : string{
		$encoded = $this->getByteArray();
		try{
			//hex2bin() rejects an odd length with a ValueError and non-hex characters with a warning
			$decoded = @hex2bin($encoded);
		}catch(\ValueError){
			$decoded = false;
		}
		if($decoded === false){
			throw new BinaryDataException("Invalid hex-encoded byte array");
		}
		return $decoded;
	}

	public function putHexByteArray(string $v) : void{
		$this->putByteArray(bin2hex($v));
	}
}
