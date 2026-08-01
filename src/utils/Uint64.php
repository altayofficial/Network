<?php

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
