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

use Webrtc\DataChannel\RTCDataChannel;

final class SctpStatsReader{

	private static ?\ReflectionProperty $srttProperty = null;
	private static bool $unavailable = false;

	private function __construct(){

	}

	public static function smoothedRoundTripTime(?RTCDataChannel $channel) : ?float{
		if($channel === null || self::$unavailable){
			return null;
		}
        $transport = $channel->getTransport();
        $property = self::$srttProperty;
        if($property === null){
            $reflection = new \ReflectionClass($transport);
            if(!$reflection->hasProperty("srtt")){
                self::$unavailable = true;
                return null;
            }
            $property = self::$srttProperty = $reflection->getProperty("srtt");
        }
        $value = $property->getValue($transport);
        return is_float($value) ? $value : null;
    }
}
