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

final class JwsEs384{

	private const COMPONENT_LENGTH = 48;

	private function __construct(){

	}

	public static function base64UrlEncode(string $data) : string{
		return rtrim(strtr(base64_encode($data), "+/", "-_"), "=");
	}

	public static function base64UrlDecode(string $data) : ?string{
		$decoded = base64_decode(strtr($data, "-_", "+/"), true);
		return $decoded === false ? null : $decoded;
	}

	/**
	 * Signs the signing input and returns the raw r||s JOSE signature.
	 */
	public static function sign(string $signingInput, \OpenSSLAsymmetricKey $privateKey) : string{
		if(!openssl_sign($signingInput, $der, $privateKey, OPENSSL_ALGO_SHA384)){
			throw new \RuntimeException("Failed to sign: " . openssl_error_string());
		}
		return self::derToRaw($der);
	}

	public static function verify(string $signingInput, string $rawSignature, \OpenSSLAsymmetricKey $publicKey) : bool{
		$der = self::rawToDer($rawSignature);
		if($der === null){
			return false;
		}
		return openssl_verify($signingInput, $der, $publicKey, OPENSSL_ALGO_SHA384) === 1;
	}

	/**
	 * Converts a DER encoded ECDSA signature into the fixed-size raw r||s form used by JOSE.
	 */
	private static function derToRaw(string $der) : string{
		$offset = 2;
		if(ord($der[1]) & 0x80){
			$offset += ord($der[1]) & 0x7f;
		}
		$result = "";
		for($i = 0; $i < 2; $i++){
			if(ord($der[$offset]) !== 0x02){
				throw new \RuntimeException("Invalid DER signature structure");
			}
			$length = ord($der[$offset + 1]);
			$component = substr($der, $offset + 2, $length);
			$offset += 2 + $length;
			$component = ltrim($component, "\x00");
			$result .= str_pad($component, self::COMPONENT_LENGTH, "\x00", STR_PAD_LEFT);
		}
		return $result;
	}

	private static function rawToDer(string $raw) : ?string{
		if(strlen($raw) !== self::COMPONENT_LENGTH * 2){
			return null;
		}
		$encodeInteger = function(string $component) : string{
			$component = ltrim($component, "\x00");
			if($component === "" || ord($component[0]) & 0x80){
				$component = "\x00" . $component;
			}
			return "\x02" . chr(strlen($component)) . $component;
		};
		$body = $encodeInteger(substr($raw, 0, self::COMPONENT_LENGTH)) . $encodeInteger(substr($raw, self::COMPONENT_LENGTH));
		$length = strlen($body);
		$header = $length > 0x7f ? "\x30\x81" . chr($length) : "\x30" . chr($length);
		return $header . $body;
	}
}
