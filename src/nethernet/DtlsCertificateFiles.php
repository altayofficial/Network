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

use altay\dtls\Certificate;
use function chmod;
use function file_put_contents;
use function openssl_pkey_export;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * A DTLS certificate on disk, shared by every peer connection the transport opens.
 *
 * The WebRTC library generates a fresh key pair per connection unless it is given a path to one,
 * and that key generation is the single most expensive step of accepting an offer - which anyone
 * on the network may ask for. Generating it once per run keeps that cost off the hot path.
 */
final class DtlsCertificateFiles{

	private function __construct(
		public readonly string $certificatePath,
		public readonly string $privateKeyPath
	){}

	/**
	 * @throws \RuntimeException
	 */
	public static function generate() : self{
		$certificate = Certificate::generate();
		if(!openssl_pkey_export($certificate->privateKey(), $privateKeyPem)){
			throw new \RuntimeException("Failed to export the DTLS private key");
		}

		$certificatePath = self::write(Certificate::derToPem($certificate->der()));
		try{
			$privateKeyPath = self::write($privateKeyPem);
		}catch(\RuntimeException $e){
			@unlink($certificatePath);
			throw $e;
		}

		return new self($certificatePath, $privateKeyPath);
	}

	/**
	 * @throws \RuntimeException
	 */
	private static function write(string $pem) : string{
		$path = tempnam(sys_get_temp_dir(), "nethernet-dtls-");
		if($path === false){
			throw new \RuntimeException("Failed to create a temporary file for the DTLS certificate");
		}
		//the key would otherwise be world readable in a shared temporary directory
		@chmod($path, 0600);
		if(file_put_contents($path, $pem) === false){
			@unlink($path);
			throw new \RuntimeException("Failed to write the DTLS certificate to $path");
		}
		return $path;
	}

	public function delete() : void{
		@unlink($this->certificatePath);
		@unlink($this->privateKeyPath);
	}
}
