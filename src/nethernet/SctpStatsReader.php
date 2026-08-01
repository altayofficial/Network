<?php

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
