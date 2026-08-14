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

namespace altay\network\nethernet\auth;

use function base64_encode;
use function chr;
use function is_array;
use function is_string;
use function str_pad;
use function strlen;

final class JwkPublicKey{

	private const OID_EC_PUBLIC_KEY = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";

	private const CURVES = [
		//1.2.840.10045.3.1.7 - prime256v1
		"P-256" => ["\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07", 32],
		//1.3.132.0.34 - secp384r1, the curve NetherNet signs with
		"P-384" => ["\x06\x05\x2b\x81\x04\x00\x22", 48],
		//1.3.132.0.35 - secp521r1
		"P-521" => ["\x06\x05\x2b\x81\x04\x00\x23", 66]
	];

	private function __construct(){

	}

	/**
	 * @param mixed $jwk the decoded 'cpk' claim
	 * @throws IdentityException
	 */
	public static function toBase64Der(mixed $jwk) : string{
		if(!is_array($jwk)){
			throw new IdentityException("Public key is not a JSON Web Key");
		}
		if(($jwk["kty"] ?? null) !== "EC"){
			throw new IdentityException("Public key is not an EC key");
		}
		$curve = $jwk["crv"] ?? null;
		if(!is_string($curve) || !isset(self::CURVES[$curve])){
			throw new IdentityException("Public key uses an unsupported curve");
		}
		[$curveOid, $size] = self::CURVES[$curve];

		$point = "\x04" . self::coordinate($jwk["x"] ?? null, $size) . self::coordinate($jwk["y"] ?? null, $size);

		return base64_encode(self::sequence(
			self::sequence(self::OID_EC_PUBLIC_KEY . $curveOid) .
			//a BIT STRING is prefixed with the number of unused bits in its last byte
			"\x03" . self::length(strlen($point) + 1) . "\x00" . $point
		));
	}

	/**
	 * @throws IdentityException
	 */
	private static function coordinate(mixed $value, int $size) : string{
		if(!is_string($value)){
			throw new IdentityException("Public key is missing a coordinate");
		}
		$decoded = JwsEs384::base64UrlDecode($value);
		if($decoded === null || strlen($decoded) > $size){
			throw new IdentityException("Public key coordinate is malformed");
		}
		//JWK requires the full width, but a peer that stripped leading zeroes is easy to accept
		return str_pad($decoded, $size, "\x00", STR_PAD_LEFT);
	}

	private static function sequence(string $contents) : string{
		return "\x30" . self::length(strlen($contents)) . $contents;
	}

	private static function length(int $length) : string{
		if($length < 0x80){
			return chr($length);
		}
		$bytes = "";
		while($length > 0){
			$bytes = chr($length & 0xff) . $bytes;
			$length >>= 8;
		}
		return chr(0x80 | strlen($bytes)) . $bytes;
	}
}
