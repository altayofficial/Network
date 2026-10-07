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

final class SessionDescription{

	private function __construct(){

	}

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

	public static function attribute(string $sdp, string $key) : ?string{
		foreach(self::attributes($sdp, $key) as $value){
			return $value;
		}
		return null;
	}

	/**
	 * Drops every candidate line, and the end-of-candidates marker with them, since a peer that is
	 * told the candidates are over would ignore the ones that follow on their own.
	 */
	public static function withoutCandidates(string $sdp) : string{
		$lines = [];
		foreach(explode("\n", $sdp) as $line){
			$trimmed = trim($line);
			if(str_starts_with($trimmed, "a=candidate:") || $trimmed === "a=end-of-candidates"){
				continue;
			}
			$lines[] = $line;
		}
		return implode("\n", $lines);
	}

	/**
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
