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

namespace altay\network\transport;

interface Transport{

	public function getName() : string;

	/**
	 * @throws TransportException
	 */
	public function start(TransportListener $listener) : void;

	public function tick() : void;

	public function isSelfPacing() : bool;

	public function getSession(int $sessionId) : ?TransportSession;

	public function isRunning() : bool;

	public function shutdown() : void;
}
