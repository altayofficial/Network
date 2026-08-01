<?php

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
