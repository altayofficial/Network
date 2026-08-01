<?php

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
