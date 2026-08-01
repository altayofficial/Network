<?php

declare(strict_types=1);

namespace altay\network\transport;

interface AddressBlockingTransport extends Transport{

	public function blockAddress(string $address, int $timeout = 300) : void;

	public function unblockAddress(string $address) : void;
}
