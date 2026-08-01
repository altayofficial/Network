<?php

declare(strict_types=1);

namespace altay\network\ipc;

interface InterThreadChannelWriter{

	public function write(string $str) : void;
}
