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

use function is_array;
use function is_int;
use function json_decode;
use function time;

/**
 * The STUN and TURN servers a peer connection may gather candidates from.
 *
 * On LAN discovery there are none - peers reach each other directly. They matter for signalling
 * over Minecraft's own service, which hands these out with a short lifetime so a relayed connection
 * can be established between networks that cannot see each other.
 */
final class Credentials{

	/**
	 * @param IceServer[] $iceServers
	 */
	public function __construct(
		public array $iceServers = [],
		public ?int $expiresAt = null
	){}

	public function isExpired(?int $now = null) : bool{
		return $this->expiresAt !== null && ($now ?? time()) >= $this->expiresAt;
	}

	/**
	 * Reads the payload Minecraft's signalling service sends. Field names are theirs.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function fromJson(string $json, ?int $now = null) : self{
		$data = json_decode($json, true);
		if(!is_array($data)){
			throw new \InvalidArgumentException("Credentials payload is not a JSON object");
		}
		return self::fromArray($data, $now);
	}

	/**
	 * @param array<string, mixed> $data
	 * @throws \InvalidArgumentException
	 */
	public static function fromArray(array $data, ?int $now = null) : self{
		$servers = [];
		$declared = $data["TurnAuthServers"] ?? [];
		if(!is_array($declared)){
			throw new \InvalidArgumentException("TurnAuthServers is not a list");
		}
		foreach($declared as $server){
			if(!is_array($server)){
				throw new \InvalidArgumentException("TurnAuthServers contains a non-object entry");
			}
			$servers[] = IceServer::fromArray($server);
		}

		$lifetime = $data["ExpirationInSeconds"] ?? null;
		$expiresAt = is_int($lifetime) ? ($now ?? time()) + $lifetime : null;

		return new self($servers, $expiresAt);
	}

	/**
	 * Builds the configuration array the WebRTC library takes for a peer connection.
	 *
	 * @return array{iceServers: list<array{urls: string[], username?: string, credential?: string}>}
	 */
	public function toPeerConnectionConfiguration() : array{
		$iceServers = [];
		foreach($this->iceServers as $server){
			$iceServers[] = $server->toConfiguration();
		}
		return ["iceServers" => $iceServers];
	}
}
