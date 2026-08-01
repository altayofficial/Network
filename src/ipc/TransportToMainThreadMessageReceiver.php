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

final class TransportToMainThreadMessageReceiver{

	public function __construct(
		private InterThreadChannelReader $channel
	){}

	public function handle(TransportToMainThreadEventHandler $handler) : bool{
		$message = $this->channel->read();
		if($message === null){
			return false;
		}

		$id = ord($message[0]);
		$offset = 1;
		switch($id){
			case TransportEventId::SESSION_OPEN:
				$sessionId = Binary::readLLong(substr($message, $offset, 8));
				$offset += 8;
				$addressLength = ord($message[$offset++]);
				$address = substr($message, $offset, $addressLength);
				$offset += $addressLength;
				$port = Binary::readLShort(substr($message, $offset, 2));
				$offset += 2;
				$publicKeyLength = Binary::readLShort(substr($message, $offset, 2));
				$offset += 2;
				$publicKey = $publicKeyLength > 0 ? substr($message, $offset, $publicKeyLength) : null;
				$handler->handleSessionOpen($sessionId, $address, $port, $publicKey);
				break;
			case TransportEventId::SESSION_CLOSE:
				$sessionId = Binary::readLLong(substr($message, $offset, 8));
				$handler->handleSessionClose($sessionId, substr($message, $offset + 8));
				break;
			case TransportEventId::PACKET_RECEIVE:
				$sessionId = Binary::readLLong(substr($message, $offset, 8));
				$handler->handlePacketReceive($sessionId, substr($message, $offset + 8));
				break;
			case TransportEventId::PACKET_ACK:
				$sessionId = Binary::readLLong(substr($message, $offset, 8));
				$handler->handlePacketAck($sessionId, Binary::readLLong(substr($message, $offset + 8, 8)));
				break;
			case TransportEventId::PING_UPDATE:
				$sessionId = Binary::readLLong(substr($message, $offset, 8));
				$handler->handlePingUpdate($sessionId, Binary::readLInt(substr($message, $offset + 8, 4)));
				break;
			case TransportEventId::RAW_PACKET_RECEIVE:
				$addressLength = ord($message[$offset++]);
				$address = substr($message, $offset, $addressLength);
				$offset += $addressLength;
				$port = Binary::readLShort(substr($message, $offset, 2));
				$handler->handleRawPacketReceive($address, $port, substr($message, $offset + 2));
				break;
			case TransportEventId::BANDWIDTH_UPDATE:
				$sent = Binary::readLLong(substr($message, $offset, 8));
				$received = Binary::readLLong(substr($message, $offset + 8, 8));
				$handler->handleBandwidthUpdate($sent, $received);
				break;
		}
		return true;
	}
}
