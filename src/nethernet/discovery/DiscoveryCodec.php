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

use altay\network\nethernet\DiscoveryCrypto;
use altay\network\nethernet\PacketSerializer;
use pocketmine\utils\BinaryDataException;

final class DiscoveryCodec{

	private const CHECKSUM_LENGTH = 32;
	private const HEADER_PADDING = 8;

	private function __construct(){

	}

	public static function marshal(DiscoveryPacket $packet, int $senderId) : string{
		$body = new PacketSerializer();
		$body->putLShort($packet->getId());
		$body->putLLong($senderId);
		$body->put(str_repeat("\x00", self::HEADER_PADDING));
		$packet->encodePayload($body);

		$buffer = $body->getBuffer();
		$payload = pack("v", strlen($buffer) + 2) . $buffer;

		return DiscoveryCrypto::checksum($payload) . DiscoveryCrypto::encrypt($payload);
	}

	/**
	 * @return array{DiscoveryPacket, int}|null packet and sender network ID, null if the datagram is not a valid discovery packet
	 */
	public static function unmarshal(string $bytes) : ?array{
		if(strlen($bytes) < self::CHECKSUM_LENGTH){
			return null;
		}
		$payload = DiscoveryCrypto::decrypt(substr($bytes, self::CHECKSUM_LENGTH));
		if($payload === null || !hash_equals(DiscoveryCrypto::checksum($payload), substr($bytes, 0, self::CHECKSUM_LENGTH))){
			return null;
		}

		try{
			$in = new PacketSerializer($payload);
			$in->getLShort(); //length prefix, counts itself and pretty unused

			$packetId = $in->getLShort();
			$senderId = $in->getLLong();
			$in->get(self::HEADER_PADDING);

			$packet = match($packetId){
				DiscoveryRequestPacket::ID => new DiscoveryRequestPacket(),
				DiscoveryResponsePacket::ID => new DiscoveryResponsePacket(),
				DiscoveryMessagePacket::ID => new DiscoveryMessagePacket(),
				default => null
			};
			if($packet === null){
				return null;
			}
			$packet->decodePayload($in);
			if(!$in->feof()){
				return null;
			}
		}catch(BinaryDataException | \InvalidArgumentException){
			return null;
		}

		return [$packet, $senderId];
	}
}
