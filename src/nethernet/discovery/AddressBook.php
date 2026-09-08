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

namespace altay\network\nethernet\discovery;

use function array_keys;
use function count;

final class AddressBook{

	/** @var array<int, array{address: string, port: int, seenAt: int}> */
	private array $entries = [];

	/**
	 * @param int $maxEntries Upper bound on the networks tracked at once. A sender ID is whatever the
	 *                        datagram claims it is, so without a cap anyone who can reach the discovery
	 *                        port could grow this table for as long as the timeout lets them.
	 */
	public function __construct(
		private int $timeout = 60,
		private int $maxEntries = 256
	){}

	/**
	 * @return bool false when the table is full of networks that are still live and this one is not
	 *         already among them
	 */
	public function remember(int $networkId, string $address, int $port, int $now) : bool{
		if(!isset($this->entries[$networkId]) && count($this->entries) >= $this->maxEntries){
			$this->expire($now);
			if(count($this->entries) >= $this->maxEntries){
				return false;
			}
		}
		$this->entries[$networkId] = ["address" => $address, "port" => $port, "seenAt" => $now];
		return true;
	}

	/**
	 * @return array{string, int}|null address and port, null when the network is unknown
	 */
	public function lookup(int $networkId) : ?array{
		$entry = $this->entries[$networkId] ?? null;
		return $entry === null ? null : [$entry["address"], $entry["port"]];
	}

	public function forget(int $networkId) : void{
		unset($this->entries[$networkId]);
	}

	public function expire(int $now) : void{
		foreach($this->entries as $networkId => $entry){
			if($now - $entry["seenAt"] >= $this->timeout){
				unset($this->entries[$networkId]);
			}
		}
	}

	/**
	 * @return int[] the network IDs currently known
	 */
	public function networkIds() : array{
		return array_keys($this->entries);
	}

	public function count() : int{
		return count($this->entries);
	}
}
