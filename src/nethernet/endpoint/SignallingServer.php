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
use React\EventLoop\Loop;
use React\Socket\Connection;
use React\Socket\ConnectionInterface;
use React\Socket\SecureServer;
use React\Socket\ServerInterface;
use function is_resource;
use function ord;
use function stream_socket_recvfrom;
use function strlen;
use const STREAM_PEEK;

/**
 * Serves both schemes on the single port the endpoint is bound to.
 *
 * A client opens with a TLS handshake and only falls back to plaintext once that is refused, so the
 * first byte says which of the two a connection is. Without a certificate the handshake is closed
 * immediately, which is what makes the client try again in the clear - React's HTTP parser would
 * otherwise read the handshake as an unfinished request and leave the client waiting on it.
 */
final class SignallingServer extends EventEmitter implements ServerInterface{

	private const TLS_HANDSHAKE_RECORD = 0x16;
	//a connection that never says anything holds a socket and a read watcher, so it does not get to wait forever
	private const FIRST_BYTE_TIMEOUT = 10;

	private ?ConnectionRelay $tlsConnections = null;

	/**
	 * @param mixed[] $tlsContext options for the "ssl" stream context, or an empty array to serve
	 *                            plaintext only. "local_cert" is the least it takes.
	 */
	public function __construct(
		private ServerInterface $server,
		array $tlsContext = []
	){
		if($tlsContext !== []){
			$this->tlsConnections = new ConnectionRelay($this->server);
			$secure = new SecureServer($this->tlsConnections, null, $tlsContext);
			$secure->on("connection", function(ConnectionInterface $connection) : void{
				//the handshake resumed the connection on its way out, so it is passed on as it is
				$this->emit("connection", [$connection]);
			});
			$secure->on("error", function(\Throwable $error) : void{
				$this->emit("error", [$error]);
			});
		}

		$this->server->on("connection", $this->inspect(...));
		$this->server->on("error", function(\Throwable $error) : void{
			$this->emit("error", [$error]);
		});
	}

	private function inspect(ConnectionInterface $connection) : void{
		$connection->pause();

		$resource = $connection instanceof Connection ? $connection->stream : null;
		if(!is_resource($resource)){
			$this->accept($connection);
			return;
		}

		$timeout = Loop::addTimer(self::FIRST_BYTE_TIMEOUT, static function() use ($connection, $resource) : void{
			Loop::removeReadStream($resource);
			$connection->close();
		});

		Loop::addReadStream($resource, function() use ($connection, $resource, $timeout) : void{
			Loop::removeReadStream($resource);
			Loop::cancelTimer($timeout);

			$first = @stream_socket_recvfrom($resource, 1, STREAM_PEEK);
			if($first === false || strlen($first) === 0){
				$connection->close();
				return;
			}
			if(ord($first) === self::TLS_HANDSHAKE_RECORD){
				if($this->tlsConnections === null){
					$connection->close();
					return;
				}
				//peeking left the byte in the socket, so the handshake still reads a whole ClientHello
				$this->tlsConnections->handle($connection);
				return;
			}
			$this->accept($connection);
		});
	}

	private function accept(ConnectionInterface $connection) : void{
		$this->emit("connection", [$connection]);
		$connection->resume();
	}

	public function getAddress() : ?string{
		return $this->server->getAddress();
	}

	public function pause() : void{
		$this->server->pause();
	}

	public function resume() : void{
		$this->server->resume();
	}

	public function close() : void{
		$this->server->close();
	}
}
