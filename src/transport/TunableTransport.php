<?php

declare(strict_types=1);

namespace altay\network\transport;

interface TunableTransport extends Transport{

	public function setPortCheck(bool $value) : void;

	public function setPacketsPerTickLimit(int $limit) : void;
}
