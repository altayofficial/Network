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

use function explode;
use function implode;
use function inet_pton;
use function rtrim;
use function str_starts_with;

/**
 * The local addresses players may be offered a path on.
 *
 * ICE gathers a candidate on every address it is allowed to see, and the answer carries all of them.
 * Each one a player cannot reach still costs that player a round of connectivity checks before it
 * is given up on, so an operator who knows which addresses reach the server can name them and keep
 * the rest out of the answer.
 */
final class AdvertisedAddresses{

	/** @var array<string, true> the allowed addresses, keyed by their packed form */
	private array $allowed = [];

	/**
	 * @param string[] $addresses
	 */
	public function __construct(array $addresses){
		foreach($addresses as $address){
			$packed = @inet_pton($address);
			if($packed !== false){
				$this->allowed[$packed] = true;
			}
		}
	}

	public function isEmpty() : bool{
		return $this->allowed === [];
	}

	/**
	 * Addresses are compared in their packed form, so the same one written two ways still matches.
	 * An address that is not a literal, such as an mDNS candidate, is never advertised: resolving it
	 * here would say nothing about whether a player can reach it.
	 */
	public function allows(string $address) : bool{
		if($this->allowed === []){
			return true;
		}
		$packed = @inet_pton($address);
		return $packed !== false && isset($this->allowed[$packed]);
	}

	/**
	 * Drops the candidate lines of a description that name an address this does not allow. A
	 * description that would be left without any candidate at all is returned untouched, since no
	 * candidates can never connect, and whatever was gathered is a better guess than nothing.
	 */
	public function filter(string $sdp, \Logger $logger) : string{
		if($this->allowed === []){
			return $sdp;
		}

		$lines = [];
		$kept = 0;
		$dropped = 0;
		foreach(explode("\n", $sdp) as $line){
			if(str_starts_with(rtrim($line, "\r"), "a=candidate:")){
				$candidate = IceCandidate::parse($line);
				if($candidate === null || !$this->allows($candidate->address)){
					$dropped++;
					continue;
				}
				$kept++;
			}
			$lines[] = $line;
		}

		if($kept === 0 && $dropped > 0){
			$logger->warning("None of the gathered ICE candidates are on an advertised address, offering all of them instead");
			return $sdp;
		}
		return implode("\n", $lines);
	}
}
