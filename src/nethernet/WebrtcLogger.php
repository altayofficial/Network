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

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use function is_scalar;
use function strtr;

/**
 * Hands what the ICE, DTLS and SCTP layers have to say to the server's own log.
 *
 * Those layers know why a connection went away - a consent check that stopped being answered, an
 * association the peer aborted, a handshake that never finished - and without this every one of
 * those reads as nothing more than "the connection closed". What they have to say below a warning
 * is a running commentary on every datagram, so it is kept out of the way unless it is asked for.
 */
final class WebrtcLogger extends AbstractLogger{

	private const IMPORTANT = [
		LogLevel::EMERGENCY => true,
		LogLevel::ALERT => true,
		LogLevel::CRITICAL => true,
		LogLevel::ERROR => true,
		LogLevel::WARNING => true
	];

	/**
	 * @param bool $verbose whether to pass on the per-message chatter as well, which is only worth
	 *                      having when a connection is being taken apart to find out why it failed
	 */
	public function __construct(
		private \Logger $logger,
		private string $prefix = "",
		private bool $verbose = false
	){}

	/**
	 * @param mixed[] $context
	 */
	public function log(mixed $level, string|\Stringable $message, array $context = []) : void{
		if(!$this->verbose && !isset(self::IMPORTANT[$level])){
			return;
		}
		$text = $this->prefix . self::interpolate((string) $message, $context);
		match($level){
			LogLevel::EMERGENCY => $this->logger->emergency($text),
			LogLevel::ALERT => $this->logger->alert($text),
			LogLevel::CRITICAL => $this->logger->critical($text),
			LogLevel::ERROR => $this->logger->error($text),
			LogLevel::WARNING => $this->logger->warning($text),
			LogLevel::NOTICE => $this->logger->notice($text),
			LogLevel::INFO => $this->logger->info($text),
			//the interesting ones are all debug, and the server only prints those when asked to
			default => $this->logger->debug($text)
		};
	}

	/**
	 * @param mixed[] $context
	 */
	private static function interpolate(string $message, array $context) : string{
		if($context === []){
			return $message;
		}
		$replacements = [];
		foreach($context as $key => $value){
			if(is_scalar($value) || $value === null || $value instanceof \Stringable){
				$replacements["{" . $key . "}"] = (string) $value;
			}
		}
		return $replacements === [] ? $message : strtr($message, $replacements);
	}
}
