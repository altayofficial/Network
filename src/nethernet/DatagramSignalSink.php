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

/**
 * Writes signals back out of the discovery socket, which is how LAN peers talk to each other.
 */
final class DatagramSignalSink implements SignalSink{

	/**
	 * @param \Closure(Signal, int, string, int) : void $send
	 */
	public function __construct(
		private \Closure $send,
		private int $recipientNetworkId,
		private string $address,
		private int $port
	){}

	public function write(Signal $signal) : void{
		($this->send)($signal, $this->recipientNetworkId, $this->address, $this->port);
	}
}
