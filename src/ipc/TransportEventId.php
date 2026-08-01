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

namespace altay\network\ipc;

final class TransportEventId{

	public const SESSION_OPEN = 0;
	public const SESSION_CLOSE = 1;
	public const PACKET_RECEIVE = 2;
	public const PACKET_ACK = 3;
	public const PING_UPDATE = 4;
	public const RAW_PACKET_RECEIVE = 5;
	public const BANDWIDTH_UPDATE = 6;

	private function __construct(){

	}
}
