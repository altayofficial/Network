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

	/**
	 * @param bool $segmented Whether the channel may split a packet at all. An unreliable channel
	 *                        may not: a dropped segment would leave the rest unusable, so the
	 *                        counter is required to be zero.
	 * @param int $maxSegmentSize Largest single message accepted. The size advertised in the SDP is
	 *                            not enforced by anything below this layer.
	 * @param int $maxPacketSize Largest packet that may be assembled out of those messages.
	 */
	public function __construct(
		private bool $segmented,
		private int $maxSegmentSize,
		private int $maxPacketSize
	){}

	/**
	 * @throws MessageFormatException when the message cannot belong to the sequence in progress.
	 *         The caller is expected to drop the peer - a broken sequence can never complete, and
	 *         continuing to buffer would let a peer grow the buffer without bound.
	 */
	public function push(string $data) : ?string{
		if(strlen($data) < 2){
			//a segment always carries the counter byte plus at least one payload byte
			throw new MessageFormatException("Received a message without a payload");
		}

		if(strlen($data) - 1 > $this->maxSegmentSize){
			throw new MessageFormatException("Received a " . (strlen($data) - 1) . " byte message, the limit is $this->maxSegmentSize");
		}

		$remaining = ord($data[0]);
		if(!$this->segmented && $remaining !== 0){
			throw new MessageFormatException("Received a segmented message on an unsegmented channel");
		}
		if(strlen($this->buffer) + strlen($data) - 1 > $this->maxPacketSize){
			throw new MessageFormatException("Assembled packet would exceed the $this->maxPacketSize byte limit");
		}
		if($this->pendingSegments > 0 && $this->pendingSegments - 1 !== $remaining){
			throw new MessageFormatException("Expected segment counter " . ($this->pendingSegments - 1) . ", got $remaining");
		}

		$this->pendingSegments = $remaining;
		$this->buffer .= substr($data, 1);
		if($remaining > 0){
			return null;
		}

		$packet = $this->buffer;
		$this->buffer = "";
		return $packet;
	}
}
