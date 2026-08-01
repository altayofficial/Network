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

use pocketmine\utils\Binary;

final class MainToTransportThreadMessageSender{

	private const FLAG_IMMEDIATE = 0x01;
	private const FLAG_HAS_RECEIPT = 0x02;

	public function __construct(
		private InterThreadChannelWriter $channel
	){}

	public function sendPacket(int $sessionId, string $payload, bool $immediate = false, ?int $receiptId = null) : void{
		$flags = ($immediate ? self::FLAG_IMMEDIATE : 0) | ($receiptId !== null ? self::FLAG_HAS_RECEIPT : 0);
		$this->channel->write(
			chr(TransportCommandId::SEND_PACKET) .
			Binary::writeLLong($sessionId) .
			chr($flags) .
			($receiptId !== null ? Binary::writeLLong($receiptId) : "") .
			$payload
		);
	}

	public function closeSession(int $sessionId) : void{
		$this->channel->write(chr(TransportCommandId::CLOSE_SESSION) . Binary::writeLLong($sessionId));
	}

	public function shutdown() : void{
		$this->channel->write(chr(TransportCommandId::SHUTDOWN));
	}

	public function setName(string $name) : void{
		$this->channel->write(chr(TransportCommandId::SET_NAME) . $name);
	}

	public function blockAddress(string $address, int $timeout) : void{
		$this->channel->write(chr(TransportCommandId::BLOCK_ADDRESS) . Binary::writeLInt($timeout) . $address);
	}

	public function unblockAddress(string $address) : void{
		$this->channel->write(chr(TransportCommandId::UNBLOCK_ADDRESS) . $address);
	}

	public function setPortCheck(bool $value) : void{
		$this->channel->write(chr(TransportCommandId::SET_PORT_CHECK) . chr($value ? 1 : 0));
	}

	public function setPacketsPerTickLimit(int $limit) : void{
		$this->channel->write(chr(TransportCommandId::SET_PACKET_LIMIT) . Binary::writeLInt($limit));
	}

	public function addRawPacketFilter(string $regex) : void{
		$this->channel->write(chr(TransportCommandId::ADD_RAW_PACKET_FILTER) . $regex);
	}

	public function sendRaw(string $address, int $port, string $payload) : void{
		$this->channel->write(
			chr(TransportCommandId::SEND_RAW) .
			chr(strlen($address)) . $address .
			Binary::writeLShort($port) .
			$payload
		);
	}
}
