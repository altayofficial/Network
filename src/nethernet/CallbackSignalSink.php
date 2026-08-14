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

/**
 * Hands every signal to a closure, for reply paths that are not a plain socket write.
 */
final class CallbackSignalSink implements SignalSink{

	/** @var \Closure(Signal) : void */
	private \Closure $handler;

	/**
	 * @param \Closure(Signal) : void $handler
	 */
	public function __construct(\Closure $handler){
		$this->handler = $handler;
	}

	public function write(Signal $signal) : void{
		($this->handler)($signal);
	}
}
