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

namespace altay\network\utils;

use pocketmine\utils\Binary;

final class Uint64{ // TODO: move this class, or find a replacement

	private const MAX = "18446744073709551615";

	private function __construct(){

	}

	public static function toSignedInt(string $decimal) : int{
		$value = gmp_init($decimal, 10);
		if(gmp_cmp($value, 0) < 0 || gmp_cmp($value, self::MAX) > 0){
			throw new \InvalidArgumentException("Value $decimal does not fit into 64 bits");
		}
		return Binary::readLong(str_pad(gmp_export($value), 8, "\x00", STR_PAD_LEFT));
	}
}
