<?php

declare(strict_types=1);

namespace altay\network\transport;

interface NameableTransport extends Transport{

	public function setName(string $name) : void;

	public function getServerId() : ?int;
}
