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

namespace altay\network\nethernet\endpoint;

use altay\network\nethernet\Signal;
use altay\network\nethernet\SignalSink;

/**
 * Collects the one signal an HTTP request can be answered with.
 *
 * Endpoint signalling is a single request and a single response, so there is no channel to trickle
 * candidates over - they travel inside the answer instead, and any candidate signalled separately
 * is dropped. The first answer or error wins; anything after it has nowhere to go.
 */
final class EndpointSignalSink implements SignalSink{

	private ?Signal $reply = null;

	/** @var \Closure(Signal) : void */
	private \Closure $onReply;

	/**
	 * @param \Closure(Signal) : void $onReply called once, with the answer or the error
	 */
	public function __construct(\Closure $onReply){
		$this->onReply = $onReply;
	}

	public function write(Signal $signal) : void{
		if($signal->type === Signal::TYPE_CANDIDATE){
			//already embedded in the answer, since the library gathers before it resolves
			return;
		}
		if($this->reply !== null){
			return;
		}
		$this->reply = $signal;
		($this->onReply)($signal);
	}

	public function hasReplied() : bool{
		return $this->reply !== null;
	}
}
