<?php

declare(strict_types=1);

namespace altay\network\transport;

interface TransportListener{

	public function onSessionOpen(Transport $transport, TransportSession $session) : void;

	public function onSessionClose(Transport $transport, TransportSession $session, string $reason) : void;

	public function onPacketReceive(Transport $transport, TransportSession $session, string $payload) : void;

	public function onRawPacketReceive(Transport $transport, string $address, int $port, string $payload) : void;
}
