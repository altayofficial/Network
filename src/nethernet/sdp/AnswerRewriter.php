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

use function array_pop;
use function array_splice;
use function count;
use function explode;
use function implode;
use function str_contains;
use function str_starts_with;
use function trim;

final class AnswerRewriter{

	public const MAX_MESSAGE_SIZE = 262144;

	private function __construct(){

	}

	public static function conform(string $sdp) : string{
		$eol = str_contains($sdp, "\r\n") ? "\r\n" : "\n";
		$lines = explode("\n", $sdp);
		foreach($lines as $index => $line){
			$lines[$index] = trim($line, "\r");
		}
		//a trailing empty element from the final line break would otherwise be reordered
		$trailing = "";
		while($lines !== [] && trim($lines[count($lines) - 1]) === ""){
			array_pop($lines);
			$trailing = $eol;
		}

		$lines = self::withMaxMessageSize($lines);
		$lines = self::withIceOptions($lines);
		$lines = self::withExtmapAllowMixed($lines);

		return implode($eol, $lines) . $trailing;
	}

	/**
	 * @param string[] $lines
	 * @return string[]
	 */
	private static function withMaxMessageSize(array $lines) : array{
		foreach($lines as $index => $line){
			if(str_starts_with($line, "a=max-message-size:")){
				$lines[$index] = "a=max-message-size:" . self::MAX_MESSAGE_SIZE;
				return $lines;
			}
		}
		$media = self::mediaIndex($lines);
		if($media !== null){
			array_splice($lines, $media + 1, 0, ["a=max-message-size:" . self::MAX_MESSAGE_SIZE]);
		}
		return $lines;
	}

	/**
	 * @param string[] $lines
	 * @return string[]
	 */
	private static function withIceOptions(array $lines) : array{
		$anchor = null;
		foreach($lines as $index => $line){
			if(str_starts_with($line, "a=ice-options:")){
				return $lines;
			}
			if(str_starts_with($line, "a=ice-pwd:") || str_starts_with($line, "a=ice-ufrag:")){
				$anchor = $index;
			}
		}
		if($anchor === null){
			return $lines;
		}
		array_splice($lines, $anchor + 1, 0, ["a=ice-options:trickle"]);
		return $lines;
	}

	/**
	 * @param string[] $lines
	 * @return string[]
	 */
	private static function withExtmapAllowMixed(array $lines) : array{
		$media = self::mediaIndex($lines);
		$end = $media ?? count($lines);
		for($i = 0; $i < $end; $i++){
			if($lines[$i] === "a=extmap-allow-mixed"){
				return $lines;
			}
		}
		if($media === null){
			$lines[] = "a=extmap-allow-mixed";
			return $lines;
		}
		array_splice($lines, $media, 0, ["a=extmap-allow-mixed"]);
		return $lines;
	}

	/**
	 * @param string[] $lines
	 */
	private static function mediaIndex(array $lines) : ?int{
		foreach($lines as $index => $line){
			if(str_starts_with($line, "m=")){
				return $index;
			}
		}
		return null;
	}
}
