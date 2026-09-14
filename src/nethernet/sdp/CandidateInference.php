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

use function count;
use function inet_pton;
use function ord;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Guesses where a peer can be reached when its own offer does not say.
 *
 * A peer that gathered nothing but host candidates on a private network offers no address anyone
 * outside it can use, and its own checks die on the first NAT they meet. Its public address is
 * known anyway - it just signalled from it - and a consumer NAT usually keeps the port a socket is
 * already using, so checking there costs a few packets and opens the path both ways when it works.
 */
final class CandidateInference{

	/**
	 * A peer gathers one port per interface it holds, so a handful covers any real client. How many
	 * packets leave this server is not something an unauthenticated offer gets to decide.
	 */
	private const MAX_CANDIDATES = 8;

	/** The priority a reflexive candidate of the peer's own would have carried. */
	private const PRIORITY = 1677721855;

	private const FOUNDATION_BASE = 90000000;

	private function __construct(){

	}

	/**
	 * Reflexive candidates for the address an offer arrived from, one per port the peer gathered.
	 *
	 * Nothing is guessed for a peer that already offered a reflexive or relayed candidate, or that
	 * signalled from an address on this network, since both of those have a real path already.
	 *
	 * @return IceCandidate[]
	 */
	public static function reflexiveFor(string $sdp, string $address) : array{
		if(!self::isGlobalUnicast($address)){
			return [];
		}

		$ports = [];
		foreach(IceCandidate::parseAll($sdp) as $candidate){
			if($candidate->type !== "host"){
				//the peer can be reached without guessing
				return [];
			}
			if($candidate->protocol === "udp" && !isset($ports[$candidate->port]) && count($ports) < self::MAX_CANDIDATES){
				$ports[$candidate->port] = true;
			}
		}

		$candidates = [];
		$foundation = self::FOUNDATION_BASE;
		foreach($ports as $port => $_){
			$candidates[] = new IceCandidate((string) $foundation++, "udp", self::PRIORITY, $address, $port, "srflx", "0.0.0.0", 0);
		}
		return $candidates;
	}

	/**
	 * Whether an address is one the internet routes, which is what makes it worth guessing from. A
	 * private, carrier grade NAT or unique local address belongs to a peer that reached this server
	 * some other way, and its ports say nothing about how anyone else gets to it.
	 */
	public static function isGlobalUnicast(string $address) : bool{
		$packed = @inet_pton($address);
		if($packed === false){
			return false;
		}
		if(strlen($packed) === 16 && str_starts_with($packed, str_repeat("\x00", 10) . "\xff\xff")){
			//an IPv4-mapped address is an IPv4 address wearing a hat, so judge it as one
			$packed = substr($packed, 12);
		}
		return strlen($packed) === 4 ? self::isGlobalUnicastV4($packed) : self::isGlobalUnicastV6($packed);
	}

	private static function isGlobalUnicastV4(string $packed) : bool{
		$first = ord($packed[0]);
		$second = ord($packed[1]);
		$third = ord($packed[2]);

		if($first === 0 || $first === 10 || $first === 127 || $first >= 224){ //this network, private, loopback, multicast and reserved
			return false;
		}
		if($first === 100 && $second >= 64 && $second <= 127){ //100.64.0.0/10 carrier grade NAT
			return false;
		}
		if($first === 169 && $second === 254){ //169.254.0.0/16 link-local
			return false;
		}
		if($first === 172 && $second >= 16 && $second <= 31){ //172.16.0.0/12 private
			return false;
		}
		if($first === 192){
			if($second === 168){ //192.168.0.0/16 private
				return false;
			}
			//192.0.0.0/24 protocol assignments, 192.0.2.0/24 and 192.88.99.0/24 documentation and 6to4 relay
			if($second === 0 && ($third === 0 || $third === 2)){
				return false;
			}
			if($second === 88 && $third === 99){
				return false;
			}
		}
		if($first === 198 && ($second === 18 || $second === 19)){ //198.18.0.0/15 benchmarking
			return false;
		}
		if($first === 198 && $second === 51 && $third === 100){ //198.51.100.0/24 documentation
			return false;
		}
		return !($first === 203 && $second === 0 && $third === 113); //203.0.113.0/24 documentation
	}

	private static function isGlobalUnicastV6(string $packed) : bool{
		$first = (ord($packed[0]) << 8) | ord($packed[1]);
		$second = (ord($packed[2]) << 8) | ord($packed[3]);

		if(($first & 0xe000) !== 0x2000){ //global unicast is 2000::/3, which leaves out unique local and link-local
			return false;
		}
		if($first === 0x2002){ //2002::/16 6to4
			return false;
		}
		if($first === 0x2001 && ($second < 0x200 || $second === 0xdb8)){ //2001::/23 protocol assignments, 2001:db8::/32 documentation
			return false;
		}
		return !($first === 0x3fff && $second < 0x1000); //3fff::/20 documentation
	}
}
