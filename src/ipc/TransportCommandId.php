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

final class TransportCommandId{

	public const SEND_PACKET = 0;
	public const CLOSE_SESSION = 1;
	public const SHUTDOWN = 2;
	public const SET_NAME = 3;
	public const BLOCK_ADDRESS = 4;
	public const UNBLOCK_ADDRESS = 5;
	public const SET_PORT_CHECK = 6;
	public const SET_PACKET_LIMIT = 7;
	public const ADD_RAW_PACKET_FILTER = 8;
	public const SEND_RAW = 9;

	private function __construct(){

	}
}
