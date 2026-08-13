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
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Minimal reader for the SDP attributes NetherNet negotiation needs.
 *
 * The WebRTC library owns parsing for everything it consumes; this only picks out the handful of
 * values that have to be read back out of a description to build signals.
 */
final class SessionDescription{

	private function __construct(){

	}

	/**
	 * Returns the part of an SDP above the first media description.
	 *
	 * Attributes are scoped: those below an 'm=' line belong to that media description, those above
	 * it apply to the session. The WebRTC library only ingests candidates attached to a media
	 * description, so the session level has to be read separately.
	 */
	public static function sessionSection(string $sdp) : string{
		$lines = [];
		foreach(explode("\n", $sdp) as $line){
			if(str_starts_with(trim($line), "m=")){
				break;
			}
			$lines[] = $line;
		}
		return implode("\n", $lines);
	}

	/**
	 * Returns the value of the first 'a=<key>:<value>' attribute, or null when it is absent.
	 */
	public static function attribute(string $sdp, string $key) : ?string{
		foreach(self::attributes($sdp, $key) as $value){
			return $value;
		}
		return null;
	}

	/**
	 * Returns the values of every 'a=<key>:<value>' attribute, in the order they appear.
	 *
	 * @return string[]
	 */
	public static function attributes(string $sdp, string $key) : array{
		$prefix = "a=$key:";
		$values = [];
		foreach(explode("\n", $sdp) as $line){
			$line = trim($line);
			if(str_starts_with($line, $prefix)){
				$values[] = substr($line, strlen($prefix));
			}
		}
		return $values;
	}
}
