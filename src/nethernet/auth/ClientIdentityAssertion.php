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

final class ClientIdentityAssertion{

	private function __construct(
		private string $fingerprintsJws,
		private string $token,
		private string $publicKeyBase64,
		private array $tokenClaims
	){}

	public function getPublicKeyBase64() : string{
		return $this->publicKeyBase64;
	}

	public function getToken() : string{
		return $this->token;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getTokenClaims() : array{
		return $this->tokenClaims;
	}

	/**
	 * Parses the 'a=identity' attribute from an SDP. Returns null when the SDP does not
	 * carry an identity assertion, throws when it carries a malformed one.
	 *
	 * @throws IdentityException
	 */
	public static function fromSdp(string $sdp) : ?self{
		if(preg_match('/^a=identity:(\S+)\s*$/m', $sdp, $match) === 0){
			return null;
		}
		$decoded = base64_decode($match[1], true);
		if($decoded === false){
			throw new IdentityException("Identity attribute is not valid base64");
		}
		$data = json_decode($decoded, true);
		if(!is_array($data) || !isset($data["assertion"]) || !is_string($data["assertion"])){
			throw new IdentityException("Identity attribute has an invalid structure");
		}
		$assertion = json_decode($data["assertion"], true);
		if(!is_array($assertion) || !isset($assertion["fingerprints"], $assertion["token"]) ||
			!is_string($assertion["fingerprints"]) || !is_string($assertion["token"]) ||
			substr_count($assertion["fingerprints"], ".") !== 2 || substr_count($assertion["token"], ".") !== 2
		){
			throw new IdentityException("Identity assertion has an invalid structure");
		}
		self::checkIdentityProvider($data["idp"] ?? null);

		[$claims, $publicKeyBase64] = self::extractTokenClaims($assertion["token"]);

		return new self($assertion["fingerprints"], $assertion["token"], $publicKeyBase64, $claims);
	}

	/**
	 * @throws IdentityException
	 */
	private static function checkIdentityProvider(mixed $idp) : void{
		if(!is_array($idp)){
			throw new IdentityException("Identity assertion has no identity provider");
		}
		if(($idp["protocol"] ?? null) !== "default"){
			throw new IdentityException("Identity provider uses an unsupported protocol");
		}
		if(!isset($idp["domain"]) || !is_string($idp["domain"]) || $idp["domain"] === ""){
			throw new IdentityException("Identity provider has no domain");
		}
	}

	/**
	 * @return array{array, string} claims and the base64 encoded 'cpk' public key
	 * @throws IdentityException
	 */
	private static function extractTokenClaims(string $token) : array{
		$parts = explode(".", $token);
		$payload = JwsEs384::base64UrlDecode($parts[1]);
		if($payload === null){
			throw new IdentityException("Identity token payload is not valid base64");
		}
		$claims = json_decode($payload, true);
		if(!is_array($claims) || !isset($claims["cpk"])){
			throw new IdentityException("Identity token does not contain a cpk claim");
		}
		if(isset($claims["exp"]) && is_int($claims["exp"]) && $claims["exp"] < time()){
			throw new IdentityException("Identity token is expired");
		}
		if(isset($claims["nbf"]) && is_int($claims["nbf"]) && $claims["nbf"] > time() + 60){
			throw new IdentityException("Identity token is not yet valid");
		}
		//the claim is either a base64 encoded PKIX key or a JSON Web Key object
		$publicKey = is_string($claims["cpk"]) ? $claims["cpk"] : JwkPublicKey::toBase64Der($claims["cpk"]);
		return [$claims, $publicKey];
	}

	/**
	 * Verifies that the DTLS fingerprints of the given SDP were signed with the private key
	 * corresponding to the token's 'cpk' claim, binding the identity to the peer connection.
	 *
	 * @throws IdentityException
	 */
	public function verify(string $sdp) : void{
		$payload = SdpFingerprints::canonicalPayload($sdp);
		if($payload === null){
			throw new IdentityException("SDP does not contain DTLS fingerprints");
		}

		$publicKey = openssl_pkey_get_public(
			"-----BEGIN PUBLIC KEY-----\n" . chunk_split($this->publicKeyBase64, 64, "\n") . "-----END PUBLIC KEY-----\n"
		);
		if($publicKey === false){
			throw new IdentityException("Invalid cpk public key in identity token");
		}

		$parts = explode(".", $this->fingerprintsJws);
		$header = json_decode(JwsEs384::base64UrlDecode($parts[0]) ?? "", true);
		if(!is_array($header) || ($header["alg"] ?? null) !== "ES384"){
			throw new IdentityException("Unsupported fingerprint assertion algorithm");
		}
		$signature = JwsEs384::base64UrlDecode($parts[2]);
		if($signature === null){
			throw new IdentityException("Invalid fingerprint assertion signature encoding");
		}

		$signingInput = $parts[0] . "." . JwsEs384::base64UrlEncode($payload);
		if(!JwsEs384::verify($signingInput, $signature, $publicKey)){
			throw new IdentityException("Fingerprint assertion signature does not match");
		}
	}
}
