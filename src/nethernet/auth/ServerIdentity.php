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

final class ServerIdentity{

	private const TOKEN_LIFETIME = 60;

	private function __construct(
		private \OpenSSLAsymmetricKey $privateKey,
		private string $publicKeyBase64,
		private string $domain
	){}

	public static function generate(string $domain = "self") : self{
		$key = openssl_pkey_new([
			"private_key_type" => OPENSSL_KEYTYPE_EC,
			"curve_name" => "secp384r1"
		]);
		if($key === false){
			throw new \RuntimeException("Failed to generate identity key: " . openssl_error_string());
		}
		return new self($key, self::extractPublicKeyBase64($key), $domain);
	}

	private static function extractPublicKeyBase64(\OpenSSLAsymmetricKey $key) : string{
		$details = openssl_pkey_get_details($key);
		if($details === false){
			throw new \RuntimeException("Failed to read identity key details");
		}
		$pem = $details["key"];
		return str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n", "\r"], "", $pem);
	}

	public function getPublicKeyBase64() : string{
		return $this->publicKeyBase64;
	}

	/**
	 * Builds the value for the 'a=identity' SDP attribute, containing a freshly issued
	 * self-signed token and a detached JWS over the DTLS fingerprints of the given SDP.
	 */
	public function createIdentityAttribute(string $sdp) : ?string{
		$payload = SdpFingerprints::canonicalPayload($sdp);
		if($payload === null){
			return null;
		}

		$now = time();
		$token = $this->signCompact(
			['alg' => 'ES384', 'x5u' => $this->publicKeyBase64],
			json_encode([
				'cpk' => $this->publicKeyBase64,
				'exp' => $now + self::TOKEN_LIFETIME,
				'iat' => $now
			], JSON_UNESCAPED_SLASHES),
			false
		);
		$fingerprints = $this->signCompact(['alg' => 'ES384'], $payload, true);

		$identity = '{"assertion":' . json_encode(
			'{"fingerprints":' . json_encode($fingerprints) . ',"token":' . json_encode($token) . '}'
		) . ',"idp":{"domain":' . json_encode($this->domain) . ',"protocol":"default"}}';

		return base64_encode($identity);
	}

	private function signCompact(array $header, string $payload, bool $detached) : string{
		$encodedHeader = JwsEs384::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
		$encodedPayload = JwsEs384::base64UrlEncode($payload);
		$signature = JwsEs384::base64UrlEncode(JwsEs384::sign($encodedHeader . "." . $encodedPayload, $this->privateKey));
		return $encodedHeader . "." . ($detached ? "" : $encodedPayload) . "." . $signature;
	}
}
