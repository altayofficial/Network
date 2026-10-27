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

use function ord;
use function strlen;
use function substr;

final class MessageAssembler{

	private int $pendingSegments = 0;
	private string $buffer = "";
	/** Whether the rest of a message that can no longer be completed is being skipped */
	private bool $dropping = false;

	/**
	 * @param bool $reliable Whether the channel promises every message. A reliable channel that
	 *                       breaks its framing can never be trusted again, so that ends it. An
	 *                       unreliable one loses messages anyway, so what cannot be delivered is
	 *                       dropped and the next message starts clean.
	 * @param int $maxSegmentSize Largest single message accepted. The size advertised in the SDP is
	 *                            not enforced by anything below this layer.
	 * @param int $maxPacketSize Largest packet that may be assembled out of those messages.
	 */
	public function __construct(
		private bool $reliable,
		private int $maxSegmentSize,
		private int $maxPacketSize
	){}

	/**
	 * @throws MessageFormatException on the reliable channel, when the message cannot belong to the
	 *         sequence in progress. The caller is expected to drop the peer - a broken sequence can
	 *         never complete, and continuing to buffer would let a peer grow the buffer without bound.
	 */
	public function push(string $data) : ?string{
		if(strlen($data) < 2){
			//a segment always carries the counter byte plus at least one payload byte
			$this->broken("Received a message without a payload");
			return null;
		}

		if(strlen($data) - 1 > $this->maxSegmentSize){
			$this->broken("Received a " . (strlen($data) - 1) . " byte message, the limit is $this->maxSegmentSize");
			return null;
		}

		$remaining = ord($data[0]);
		if($this->pendingSegments > 0 && $this->pendingSegments - 1 !== $remaining){
			$expected = $this->pendingSegments - 1;
			$this->broken("Expected segment counter $expected, got $remaining");
			if($remaining < $expected){
				//segments went missing inside this message, so follow its countdown out and start
				//clean on the next one. A higher counter already is the next one, a countdown only falls
				$this->dropping = true;
			}
		}

		if(!$this->dropping && strlen($this->buffer) + strlen($data) - 1 > $this->maxPacketSize){
			$this->broken("Assembled packet would exceed the $this->maxPacketSize byte limit");
			$this->dropping = true;
		}

		$this->pendingSegments = $remaining;
		if($this->dropping){
			$this->dropping = $remaining > 0;
			return null;
		}

		$this->buffer .= substr($data, 1);
		if($remaining > 0){
			return null;
		}

		$packet = $this->buffer;
		$this->buffer = "";
		return $packet;
	}

	/**
	 * Throws on the reliable channel. On the unreliable one it forgets whatever was being assembled.
	 *
	 * @throws MessageFormatException
	 */
	private function broken(string $reason) : void{
		if($this->reliable){
			throw new MessageFormatException($reason);
		}
		$this->pendingSegments = 0;
		$this->buffer = "";
		$this->dropping = false;
	}
}
