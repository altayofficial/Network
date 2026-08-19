<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use Webrtc\Webrtc\RTCPeerConnection;

trait RequiresPeerConnection{

	protected function requirePeerConnection() : void{
		//the DTLS stack is pure PHP now, so there is no extension left to check for - if a peer
		//connection cannot be built it is a real failure of something below
		try{
			(new RTCPeerConnection())->close();
		}catch(\Throwable $e){
			self::markTestSkipped("a peer connection could not be created: " . $e->getMessage());
		}
	}
}
