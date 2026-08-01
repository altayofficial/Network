<?php

declare(strict_types=1);

namespace altay\network\raknet;

use altay\network\raknet\server\ServerEventSource;
use altay\network\raknet\server\ServerInterface;

final class NullServerEventSource implements ServerEventSource{

	public function process(ServerInterface $server) : bool{
		return false;
	}
}
