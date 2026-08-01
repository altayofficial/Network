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

interface TransportToMainThreadEventHandler{

	public function handleSessionOpen(int $sessionId, string $address, int $port, ?string $authenticatedPublicKey) : void;

	public function handleSessionClose(int $sessionId, string $reason) : void;

	public function handlePacketReceive(int $sessionId, string $payload) : void;

	public function handlePacketAck(int $sessionId, int $receiptId) : void;

	public function handlePingUpdate(int $sessionId, int $pingMS) : void;

	public function handleRawPacketReceive(string $address, int $port, string $payload) : void;

	public function handleBandwidthUpdate(int $bytesSentDiff, int $bytesReceivedDiff) : void;
}
