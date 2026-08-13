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
use function in_array;
use function ltrim;
use function preg_split;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;

/**
 * A single ICE candidate as it appears in an SDP or in a CANDIDATEADD signal.
 *
 * Vanilla signals candidates in the form produced by the C++ implementation of WebRTC, which
 * carries four trailing attributes ('generation', 'ufrag', 'network-id', 'network-cost') that
 * a plain SDP candidate line does not. Clients ignore candidates that lack them, so outgoing
 * candidates have to be rebuilt rather than copied out of the local description.
 */
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

	/**
	 * Parses a candidate line. The leading 'a=' and the 'candidate:' prefix are both optional,
	 * since signals and SDP attributes spell them differently.
	 */
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
	 * Extracts every candidate attribute from an SDP, in the order they appear.
	 *
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
	 * Renders the candidate the way the C++ WebRTC implementation does. The component is always 1
	 * because NetherNet only ever negotiates a single RTP component.
	 */
	public function format(int $networkId, string $ufrag) : string{
		return "candidate:" . $this->toSdpValue() . " generation 0 ufrag $ufrag network-id $networkId network-cost 0";
	}

	/**
	 * Renders the candidate without the 'candidate:' prefix and without the vanilla trailer.
	 *
	 * This is the shape the WebRTC library's own parser expects - it splits on spaces and reads
	 * the first field as the foundation, so feeding it a prefixed line silently produces a
	 * foundation of 'candidate:<foundation>'.
	 */
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
