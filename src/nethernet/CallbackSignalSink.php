<?php

/*
 *
 *      _    _ _
 *     / \  | | |_ __ _ _   _
 *    / _ \ | | __/ _` | | | |
 *   / ___ \| | || (_| | |_| |
 *  /_/   \_\_|\__\__,_|\__, |
 *                       |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Original work by the PocketMine Team.
 * https://www.pocketmine.net/
 *
 * @author Altay Team
 * @link https://github.com/altayofficial
 */

declare(strict_types=1);

namespace altay\network\nethernet;

final class CallbackSignalSink implements SignalSink{

	/** @var \Closure(Signal) : void */
	private \Closure $handler;

	/**
	 * @param \Closure(Signal) : void $handler
	 * @param bool $trickle whether the handler can still carry candidates once the description is out
	 */
	public function __construct(\Closure $handler, private bool $trickle = true){
		$this->handler = $handler;
	}

	public function write(Signal $signal) : void{
		($this->handler)($signal);
	}

	public function supportsTrickle() : bool{
		return $this->trickle;
	}
}
