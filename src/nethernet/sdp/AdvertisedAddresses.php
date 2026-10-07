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

use function array_splice;
use function count;
use function explode;
use function implode;
use function inet_ntop;
use function inet_pton;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function trim;

/**
 * The local addresses players may be offered a path on.
 *
 * ICE gathers a candidate on every address it is allowed to see, and the answer carries all of them.
 * Each one a player cannot reach still costs that player a round of connectivity checks before it
 * is given up on, so an operator who knows which addresses reach the server can name them and keep
 * the rest out of the answer.
 */
final class AdvertisedAddresses{

	/** What a reflexive candidate gathered from a STUN server would be given, so it ranks like one */
	private const ADVERTISED_PRIORITY = (100 << 24) | (65535 << 8) | 255;

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
	 * Drops the candidate lines of a description that name an address this does not allow, and adds
	 * one for every allowed address nothing was gathered on. Behind a forwarded port the public
	 * address never shows up among the gathered ones, so it is announced as a reflexive candidate on
	 * the port the host candidate listens on. A description that would still be left without any
	 * candidate is returned untouched, since whatever was gathered beats nothing.
	 */
	public function filter(string $sdp, \Logger $logger) : string{
		if($this->allowed === []){
			return $sdp;
		}

		$lines = [];
		$kept = 0;
		$dropped = 0;
		$matched = [];
		$hostPort = null;
		$lastCandidate = null;
		$eol = "";
		foreach(explode("\n", $sdp) as $line){
			if(str_starts_with(rtrim($line, "\r"), "a=candidate:")){
				$candidate = IceCandidate::parse($line);
				if($candidate !== null && $hostPort === null && $candidate->type === "host" && $candidate->protocol === "udp"){
					$hostPort = $candidate->port;
				}
				if($candidate === null || !$this->allows($candidate->address)){
					$dropped++;
					continue;
				}
				$matched[(string) inet_pton($candidate->address)] = true;
				$kept++;
				$lastCandidate = count($lines);
				$eol = str_ends_with($line, "\r") ? "\r" : "";
			}elseif($lastCandidate === null && str_starts_with($line, "a=end-of-candidates")){
				$lastCandidate = count($lines) - 1;
				$eol = str_ends_with($line, "\r") ? "\r" : "";
			}
			$lines[] = $line;
		}

		$added = [];
		if($hostPort !== null){
			foreach($this->allowed as $packed => $_){
				if(isset($matched[$packed])){
					continue;
				}
				$address = (string) inet_ntop((string) $packed);
				$candidate = new IceCandidate("advertised" . count($added), "udp", self::ADVERTISED_PRIORITY, $address, $hostPort, "srflx");
				$added[] = "a=candidate:" . $candidate->toSdpValue() . " raddr " . (strlen((string) $packed) === 16 ? "::" : "0.0.0.0") . " rport 0" . $eol;
			}
		}

		if($kept === 0 && $added === []){
			if($dropped > 0){
				$logger->warning("None of the gathered ICE candidates are on an advertised address, offering all of them instead");
			}
			return $sdp;
		}
		if($added !== []){
			array_splice($lines, $lastCandidate === null ? self::mediaEnd($lines) : $lastCandidate + 1, 0, $added);
		}
		return implode("\n", $lines);
	}

	/**
	 * Where a candidate goes when the description had none left to put it next to: the end of the
	 * description, ahead of the empty line its final line break leaves behind.
	 *
	 * @param string[] $lines
	 */
	private static function mediaEnd(array $lines) : int{
		$end = count($lines);
		while($end > 0 && trim($lines[$end - 1]) === ""){
			$end--;
		}
		return $end;
	}
}
