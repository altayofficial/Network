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

final class Signal{

	public const TYPE_OFFER = "CONNECTREQUEST";
	public const TYPE_ANSWER = "CONNECTRESPONSE";
	public const TYPE_CANDIDATE = "CANDIDATEADD";
	public const TYPE_ERROR = "CONNECTERROR";

	public function __construct(
		public string $type,
		public string $connectionId,
		public string $data
	){}

	private const MAX_CONNECTION_ID = "18446744073709551615";

	public static function fromString(string $str) : ?self{
		$parts = explode(" ", $str, 3);
		if(count($parts) !== 3 || !ctype_digit($parts[1])){
			return null;
		}
		//the connection ID is an uint64 on the wire, compare as a string to keep it out of PHP's signed int range
		$connectionId = ltrim($parts[1], "0");
		if($connectionId === ""){
			$connectionId = "0";
		}
		$length = strlen($connectionId);
		if($length > strlen(self::MAX_CONNECTION_ID) || ($length === strlen(self::MAX_CONNECTION_ID) && strcmp($connectionId, self::MAX_CONNECTION_ID) > 0)){
			return null;
		}
		return new self($parts[0], $connectionId, $parts[2]);
	}

	public function __toString() : string{
		return $this->type . " " . $this->connectionId . " " . $this->data;
	}
}
