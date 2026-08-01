<?php

declare(strict_types=1);

namespace altay\network\transport;

interface TransportSession{

	public function getId() : int;

	public function getAddress() : string;

	public function getPort() : int;

	public function getPing() : int;

	public function isConnected() : bool;

	public function sendPacket(string $payload, bool $immediate = false) : void;

	public function disconnect() : void;
}
