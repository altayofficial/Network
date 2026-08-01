<?php

declare(strict_types=1);

namespace altay\network\transport;

interface RawPacketTransport extends Transport{

	public function sendRaw(string $address, int $port, string $payload) : void;

	public function addRawPacketFilter(string $regex) : void;
}
