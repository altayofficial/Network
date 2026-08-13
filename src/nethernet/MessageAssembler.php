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

/**
 * Rebuilds a packet from the segments received on one data channel.
 *
 * Every message carries a leading byte holding the number of segments still to come, counting down
 * to zero on the last one. Each channel keeps its own assembler - the counters of the reliable and
 * unreliable channels are independent, and sharing state between them would splice their payloads
 * together.
 */
final class MessageAssembler{

	private int $pendingSegments = 0;
	private string $buffer = "";

	/**
	 * @param bool $segmented Whether the channel may split a packet at all. An unreliable channel
	 *                        may not: a dropped segment would leave the rest unusable, so the
	 *                        counter is required to be zero.
	 */
	public function __construct(
		private bool $segmented
	){}

	/**
	 * Returns the completed packet, or null while segments are still outstanding.
	 *
	 * @throws MessageFormatException when the message cannot belong to the sequence in progress.
	 *         The caller is expected to drop the peer - a broken sequence can never complete, and
	 *         continuing to buffer would let a peer grow the buffer without bound.
	 */
	public function push(string $data) : ?string{
		if(strlen($data) < 2){
			//a segment always carries the counter byte plus at least one payload byte
			throw new MessageFormatException("Received a message without a payload");
		}

		$remaining = ord($data[0]);
		if(!$this->segmented && $remaining !== 0){
			throw new MessageFormatException("Received a segmented message on an unsegmented channel");
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
