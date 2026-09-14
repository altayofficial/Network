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

namespace altay\network\nethernet\endpoint;

use Evenement\EventEmitter;
use React\Socket\ConnectionInterface;
use React\Socket\ServerInterface;

/**
 * A server that accepts nothing of its own and only passes on the connections it is handed.
 *
 * React's TLS server wraps another server and secures everything that arrives on it, while only
 * some of what reaches the signalling port is TLS. This stands in between: the connections that
 * opened with a handshake are handed here, and the rest never reach it.
 *
 * @internal
 */
final class ConnectionRelay extends EventEmitter implements ServerInterface{

	public function __construct(
		private ServerInterface $listener
	){}

	public function handle(ConnectionInterface $connection) : void{
		$this->emit("connection", [$connection]);
	}

	public function getAddress() : ?string{
		return $this->listener->getAddress();
	}

	public function pause() : void{
		$this->listener->pause();
	}

	public function resume() : void{
		$this->listener->resume();
	}

	public function close() : void{
		$this->listener->close();
	}
}
