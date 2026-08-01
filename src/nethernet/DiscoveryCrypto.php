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

final class DiscoveryCrypto{

	private const APPLICATION_ID = 0xdeadbeef;

	private static ?string $key = null;

	private function __construct(){

	}

	private static function key() : string{
		return self::$key ??= hash("sha256", pack("P", self::APPLICATION_ID), true);
	}

	public static function encrypt(string $payload) : string{
		$encrypted = openssl_encrypt($payload, "aes-256-ecb", self::key(), OPENSSL_RAW_DATA);
		if($encrypted === false){
			throw new \RuntimeException("Failed to encrypt discovery payload");
		}
		return $encrypted;
	}

	public static function decrypt(string $ciphertext) : ?string{
		$decrypted = openssl_decrypt($ciphertext, "aes-256-ecb", self::key(), OPENSSL_RAW_DATA);
		return $decrypted === false ? null : $decrypted;
	}

	public static function checksum(string $payload) : string{
		return hash_hmac("sha256", $payload, self::key(), true);
	}
}
