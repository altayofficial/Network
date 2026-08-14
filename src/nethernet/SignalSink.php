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
 * Where the signals belonging to one connection are written.
 *
 * Negotiation does not care how a signal reaches the other side. On LAN it goes back out of the
 * discovery socket, but an offer that arrived over HTTP has to be answered on that same request,
 * so the reply path is decided per connection rather than by the transport.
 */
interface SignalSink{

	/**
	 * Delivers a signal to the peer this sink belongs to.
	 *
	 * Implementations that can only carry a single reply - HTTP being the case that matters - are
	 * expected to keep the answer and discard anything that follows it.
	 */
	public function write(Signal $signal) : void;
}
