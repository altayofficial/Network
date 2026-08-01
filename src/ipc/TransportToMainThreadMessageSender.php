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

use altay\network\transport\Transport;
use altay\network\transport\TransportListener;
use altay\network\transport\TransportSession;
use pocketmine\utils\Binary;

final class TransportToMainThreadMessageSender implements TransportListener{

	public function __construct(
		private InterThreadChannelWriter $channel
	){}

	public function onSessionOpen(Transport $transport, TransportSession $session) : void{
		$address = $session->getAddress();
		$publicKey = $session->getAuthenticatedPublicKey() ?? "";
		$this->channel->write(
			chr(TransportEventId::SESSION_OPEN) .
			Binary::writeLLong($session->getId()) .
			chr(strlen($address)) . $address .
			Binary::writeLShort($session->getPort()) .
			Binary::writeLShort(strlen($publicKey)) . $publicKey
		);
	}

	public function onSessionClose(Transport $transport, TransportSession $session, string $reason) : void{
		$this->channel->write(
			chr(TransportEventId::SESSION_CLOSE) .
			Binary::writeLLong($session->getId()) .
			$reason
		);
	}

	public function onPacketReceive(Transport $transport, TransportSession $session, string $payload) : void{
		$this->channel->write(
			chr(TransportEventId::PACKET_RECEIVE) .
			Binary::writeLLong($session->getId()) .
			$payload
		);
	}

	public function onPacketAck(Transport $transport, TransportSession $session, int $receiptId) : void{
		$this->channel->write(
			chr(TransportEventId::PACKET_ACK) .
			Binary::writeLLong($session->getId()) .
			Binary::writeLLong($receiptId)
		);
	}

	public function onPingUpdate(Transport $transport, TransportSession $session, int $pingMS) : void{
		$this->channel->write(
			chr(TransportEventId::PING_UPDATE) .
			Binary::writeLLong($session->getId()) .
			Binary::writeLInt($pingMS)
		);
	}

	public function onRawPacketReceive(Transport $transport, string $address, int $port, string $payload) : void{
		$this->channel->write(
			chr(TransportEventId::RAW_PACKET_RECEIVE) .
			chr(strlen($address)) . $address .
			Binary::writeLShort($port) .
			$payload
		);
	}

	public function onBandwidthUpdate(Transport $transport, int $bytesSentDiff, int $bytesReceivedDiff) : void{
		$this->channel->write(
			chr(TransportEventId::BANDWIDTH_UPDATE) .
			Binary::writeLLong($bytesSentDiff) .
			Binary::writeLLong($bytesReceivedDiff)
		);
	}
}
