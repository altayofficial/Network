<?php

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
