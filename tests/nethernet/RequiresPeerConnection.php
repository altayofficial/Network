<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use Webrtc\Webrtc\RTCPeerConnection;
use function extension_loaded;

trait RequiresPeerConnection{

	protected function requirePeerConnection() : void{
		if(!extension_loaded("ffi")){
			self::markTestSkipped("the WebRTC stack needs ext-ffi");
		}
		try{
			(new RTCPeerConnection())->close();
		}catch(\Throwable $e){
			self::markTestSkipped("a peer connection could not be created: " . $e->getMessage());
		}
	}
}
