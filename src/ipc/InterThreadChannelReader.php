<?php

declare(strict_types=1);

namespace altay\network\ipc;

interface InterThreadChannelReader{

	public function read() : ?string;
}
