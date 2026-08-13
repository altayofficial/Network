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

use function array_values;
use function is_array;
use function is_string;

/**
 * One STUN or TURN server, with the credentials it needs.
 */
final class IceServer{

	/**
	 * @param string[] $urls
	 */
	public function __construct(
		public array $urls,
		public ?string $username = null,
		public ?string $password = null
	){}

	/**
	 * @param array<string, mixed> $data
	 * @throws \InvalidArgumentException
	 */
	public static function fromArray(array $data) : self{
		$urls = $data["Urls"] ?? null;
		if(is_string($urls)){
			$urls = [$urls];
		}
		if(!is_array($urls) || $urls === []){
			throw new \InvalidArgumentException("ICE server has no URLs");
		}
		foreach($urls as $url){
			if(!is_string($url)){
				throw new \InvalidArgumentException("ICE server URL is not a string");
			}
		}

		$username = $data["Username"] ?? null;
		$password = $data["Password"] ?? null;

		return new self(
			array_values($urls),
			is_string($username) ? $username : null,
			is_string($password) ? $password : null
		);
	}

	/**
	 * @return array{urls: string[], username: ?string, credential: ?string, credentialType: string}
	 */
	public function toConfiguration() : array{
		return [
			"urls" => $this->urls,
			//a plain STUN server carries neither, but both keys have to be present: the library
			//passes credentialType straight into a non-nullable setter and fatals when it is absent
			"username" => $this->username,
			"credential" => $this->password,
			"credentialType" => "password"
		];
	}
}
