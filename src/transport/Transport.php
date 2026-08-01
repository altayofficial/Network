<?php

declare(strict_types=1);

namespace altay\network\transport;

interface Transport{

	public function getName() : string;

	/**
	 * @throws TransportException
	 */
	public function start(TransportListener $listener) : void;

	public function tick() : void;

	public function isSelfPacing() : bool;

	public function getSession(int $sessionId) : ?TransportSession;

	public function isRunning() : bool;

	public function shutdown() : void;
}
