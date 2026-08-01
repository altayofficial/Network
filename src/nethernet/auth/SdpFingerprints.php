<?php

declare(strict_types=1);

namespace altay\network\nethernet\auth;

final class SdpFingerprints{

	private function __construct(){

	}

	/**
	 * Builds the canonical JSON payload covering the DTLS fingerprints of an SDP,
	 * matching the format vanilla signs in the identity assertion.
	 */
	public static function canonicalPayload(string $sdp) : ?string{
		if(preg_match_all('/^a=fingerprint:(\S+) (\S+)\s*$/m', $sdp, $matches, PREG_SET_ORDER) === 0){
			return null;
		}
		$parts = [];
		foreach($matches as $match){
			$parts[] = '{"algorithm":' . json_encode($match[1]) . ',"digest":' . json_encode($match[2]) . '}';
		}
		return '{"fingerprint":[' . implode(",", $parts) . ']}';
	}
}
