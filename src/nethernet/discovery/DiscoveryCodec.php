<?php

declare(strict_types=1);

namespace altay\network\nethernet\discovery;

use altay\network\nethernet\DiscoveryCrypto;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;

final class DiscoveryCodec{

	private const CHECKSUM_LENGTH = 32;
	private const HEADER_PADDING = 8;

	private function __construct(){

	}

	public static function marshal(DiscoveryPacket $packet, int $senderId) : string{
		$body = new BinaryStream();
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
			$in = new BinaryStream($payload);
			$in->getLShort();
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
		}catch(BinaryDataException | \InvalidArgumentException){
			return null;
		}

		return [$packet, $senderId];
	}
}
