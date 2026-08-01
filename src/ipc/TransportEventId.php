<?php

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
