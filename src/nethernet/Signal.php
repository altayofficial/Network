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

	/**
	 * The connection ID is opaque: it is compared and echoed back exactly as it came, never read as
	 * a number. A signal without a payload is fine, it just carries an empty one.
	 */
	public static function fromString(string $str) : ?self{
		$parts = explode(" ", $str, 3);
		if(count($parts) < 2 || !self::isPrintableAscii($parts[1])){
			return null;
		}
		return new self($parts[0], $parts[1], $parts[2] ?? "");
	}

	/**
	 * The ID ends up in logs and back on the wire, so a control character in it is refused rather
	 * than passed along.
	 */
	private static function isPrintableAscii(string $value) : bool{
		return $value !== "" && preg_match('/^[\x21-\x7e]+$/', $value) === 1;
	}

	public function __toString() : string{
		return $this->type . " " . $this->connectionId . " " . $this->data;
	}
}
