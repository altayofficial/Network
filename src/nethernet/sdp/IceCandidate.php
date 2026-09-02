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

namespace altay\network\nethernet\sdp;

use function array_shift;
use function count;
use function explode;
use function filter_var;
use function in_array;
use function inet_pton;
use function ltrim;
use function ord;
use function preg_split;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function trim;
use const FILTER_FLAG_NO_RES_RANGE;
use const FILTER_VALIDATE_IP;

final class IceCandidate{

	private const RELATED_TYPES = ["relay", "srflx"];

	public function __construct(
		public string $foundation,
		public string $protocol,
		public int $priority,
		public string $address,
		public int $port,
		public string $type,
		public ?string $relatedAddress = null,
		public ?int $relatedPort = null
	){}

	public static function parse(string $line) : ?self{
		$line = trim($line);
		if(str_starts_with($line, "a=")){
			$line = substr($line, 2);
		}
		if(!str_starts_with($line, "candidate:")){
			return null;
		}

		$parts = preg_split('/\s+/', substr($line, 10));
		if($parts === false || count($parts) < 8 || $parts[6] !== "typ"){
			return null;
		}

		[$foundation, , $protocol, $priority, $address, $port] = $parts;
		if(!self::isUnsignedInt($priority) || !self::isUnsignedInt($port)){
			return null;
		}
		$candidate = new self($foundation, self::normaliseProtocol($protocol), (int) $priority, $address, (int) $port, $parts[7]);

		//the remaining tokens are key/value extension attributes
		$rest = $parts;
		for($i = 0; $i < 8; $i++){
			array_shift($rest);
		}
		for($i = 0; $i + 1 < count($rest); $i += 2){
			if($rest[$i] === "raddr"){
				$candidate->relatedAddress = $rest[$i + 1];
			}elseif($rest[$i] === "rport" && self::isUnsignedInt($rest[$i + 1])){
				$candidate->relatedPort = (int) $rest[$i + 1];
			}
		}

		return $candidate;
	}

	/**
	 * @return self[]
	 */
	public static function parseAll(string $sdp) : array{
		$candidates = [];
		foreach(explode("\n", $sdp) as $line){
			$candidate = self::parse($line);
			if($candidate !== null){
				$candidates[] = $candidate;
			}
		}
		return $candidates;
	}

	/**
	 * Whether the candidate names a host worth sending connectivity checks to.
	 *
	 * Private ranges have to stay allowed, they are what a LAN connection runs over, but loopback,
	 * link-local, multicast and the reserved ranges never belong to a remote peer - and since the
	 * peer that offered the candidate is not authenticated, accepting those would turn the server
	 * into a probe aimed at whatever listens on them. Hostnames are left to the ICE agent, which
	 * resolves mDNS candidates itself.
	 */
	public function hasConnectableAddress() : bool{
		if($this->port < 1 || $this->port > 65535){
			return false;
		}
		if(filter_var($this->address, FILTER_VALIDATE_IP) === false){
			return str_ends_with(strtolower($this->address), ".local");
		}
		if(filter_var($this->address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE) === false){
			return false;
		}
		return !self::isMulticast($this->address);
	}

	private static function isMulticast(string $address) : bool{
		$packed = inet_pton($address);
		if($packed === false){
			return false;
		}
		$first = ord($packed[0]);
		//224.0.0.0/4 for IPv4, ff00::/8 for IPv6
		return strlen($packed) === 4 ? ($first & 0xf0) === 0xe0 : $first === 0xff;
	}

	public function format(int $networkId, string $ufrag) : string{
		return "candidate:" . $this->toSdpValue() . " generation 0 ufrag $ufrag network-id $networkId network-cost 0";
	}

	public function toSdpValue() : string{
		$out = "$this->foundation 1 $this->protocol $this->priority $this->address $this->port typ $this->type";
		if($this->relatedAddress !== null && $this->relatedPort !== null && in_array($this->type, self::RELATED_TYPES, true)){
			$out .= " raddr $this->relatedAddress rport $this->relatedPort";
		}
		return $out;
	}

	private static function normaliseProtocol(string $protocol) : string{
		return strtolower($protocol);
	}

	private static function isUnsignedInt(string $value) : bool{
		return $value !== "" && ltrim($value, "0123456789") === "";
	}
}
