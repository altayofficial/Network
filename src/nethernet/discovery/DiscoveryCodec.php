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
	private const CIPHER_BLOCK_LENGTH = 16;
	/**
	 * Discovery rides on a single datagram and the signalling messages in it are SDP sized, so
	 * anything past this was never ours. The checksum covers the plaintext and cannot be checked
	 * before decrypting, so the cheap length tests are all that keeps junk away from the cipher.
	 */
	private const MAX_CIPHERTEXT_LENGTH = 64 * 1024;
	/** How much of an unaccounted tail is worth putting in a log line */
	private const PREVIEW_LENGTH = 32;

	private function __construct(){

	}

	/**
	 * What is left of a payload the parser could not account for, in a form that fits in a log line.
	 * A protocol that grew a field shows up as readable text or a short run of bytes; something that
	 * was never ours does not.
	 */
	private static function preview(string $bytes) : string{
		$head = substr($bytes, 0, self::PREVIEW_LENGTH);
		$printable = preg_replace('/[^\x20-\x7e]/', ".", $head);

		return bin2hex($head) . " (\"" . $printable . "\")" . (strlen($bytes) > self::PREVIEW_LENGTH ? " ..." : "");
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
	 * @param string|null $reason  set to what the datagram failed on, for a caller that wants to say
	 *                              so. Which check it is tells whether something is speaking a
	 *                              different protocol at this port or a peer's message did not
	 *                              survive the trip.
	 * @param string|null $payload set to the decrypted payload once there is one, so that a caller
	 *                              looking at a rejected datagram can see what it held
	 *
	 * @return array{DiscoveryPacket, int}|null packet and sender network ID, null if the datagram is not a valid discovery packet
	 *
	 * @phpstan-param-out string|null $reason
	 * @phpstan-param-out string|null $payload
	 */
	public static function unmarshal(string $bytes, ?string &$reason = null, ?string &$payload = null) : ?array{
		$reason = null;
		$payload = null;
		$ciphertextLength = strlen($bytes) - self::CHECKSUM_LENGTH;
		if($ciphertextLength <= 0){
			$reason = "shorter than the checksum it should start with";
			return null;
		}
		if($ciphertextLength % self::CIPHER_BLOCK_LENGTH !== 0){
			$reason = "the body is not a whole number of cipher blocks, so it was never encrypted with ours";
			return null;
		}
		if($ciphertextLength > self::MAX_CIPHERTEXT_LENGTH){
			$reason = "larger than any discovery message";
			return null;
		}
		$payload = DiscoveryCrypto::decrypt(substr($bytes, self::CHECKSUM_LENGTH));
		if($payload === null){
			$reason = "will not decrypt, which is what foreign traffic on this port looks like";
			return null;
		}
		if(!hash_equals(DiscoveryCrypto::checksum($payload), substr($bytes, 0, self::CHECKSUM_LENGTH))){
			$reason = "decrypted but the checksum does not match, so the sender signs differently";
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
				$reason = "carries an unknown packet ID $packetId";
				return null;
			}
			$packet->decodePayload($in);
			if(!$in->feof()){
				$remaining = $in->getRemaining();
				$reason = "has " . strlen($remaining) . " bytes left over after packet $packetId: " . self::preview($remaining);
				return null;
			}
		}catch(BinaryDataException | \InvalidArgumentException $e){
			$reason = "is malformed: " . $e->getMessage();
			return null;
		}

		return [$packet, $senderId];
	}
}
