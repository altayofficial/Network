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

interface TransportListener{

	public function onSessionOpen(Transport $transport, TransportSession $session) : void;

	public function onSessionClose(Transport $transport, TransportSession $session, string $reason) : void;

	public function onPacketReceive(Transport $transport, TransportSession $session, string $payload) : void;

	public function onPacketAck(Transport $transport, TransportSession $session, int $receiptId) : void;

	public function onPingUpdate(Transport $transport, TransportSession $session, int $pingMS) : void;

	public function onRawPacketReceive(Transport $transport, string $address, int $port, string $payload) : void;

	public function onBandwidthUpdate(Transport $transport, int $bytesSentDiff, int $bytesReceivedDiff) : void;
}
