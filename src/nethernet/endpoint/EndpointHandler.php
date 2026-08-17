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

use altay\network\nethernet\NetherNetTransport;
use altay\network\nethernet\Signal;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function ctype_digit;
use function preg_match;
use function random_int;
use function strlen;

final class EndpointHandler{

	public const MAX_BODY_LENGTH = 1048576;

	private const NEGOTIATION_TIMEOUT = 15;

	private const PATH_PATTERN = '#^/v1/join(?:/([0-9]+))?/?$#';

	public function __construct(
		private NetherNetTransport $transport,
		private \Logger $logger
	){}

	/**
	 * @return Response|PromiseInterface<Response>
	 */
	public function __invoke(ServerRequestInterface $request) : Response|PromiseInterface{
		$path = $request->getUri()->getPath();
		if(preg_match(self::PATH_PATTERN, $path, $match) !== 1){
			return self::text(404, "Not found");
		}
		$networkId = $match[1] ?? "";

		if($request->getMethod() === "GET" && $networkId === ""){
			return self::json(200, EndpointStatus::fromServerData($this->transport->getServerData())->toJson());
		}
		if($request->getMethod() !== "POST"){
			return self::text(405, "Method not allowed");
		}
		if($networkId === ""){
			return self::text(400, "Expected /v1/join/{networkID}");
		}
		if(!ctype_digit($networkId)){
			return self::text(400, "Network ID must be uint64");
		}

		$offer = (string) $request->getBody();
		if($offer === ""){
			return self::text(400, "Missing SDP offer in request body");
		}
		if(strlen($offer) > self::MAX_BODY_LENGTH){
			return self::text(413, "SDP offer is too large");
		}

		return $this->negotiate($networkId, $offer);
	}

	/**
	 * @return PromiseInterface<Response>
	 */
	private function negotiate(string $networkId, string $offer) : PromiseInterface{
		//nothing in the request names the connection, so this side assigns the ID
		$connectionId = (string) random_int(0, PHP_INT_MAX);

		/** @var Deferred<Response> $deferred */
		$deferred = new Deferred();
		$sink = new EndpointSignalSink(function(Signal $reply) use ($deferred, $connectionId) : void{
			if($reply->type === Signal::TYPE_ANSWER){
				$deferred->resolve(self::text(200, $reply->data));
				return;
			}
			$this->logger->debug("Endpoint negotiation for connection $connectionId failed with code " . $reply->data);
			$deferred->resolve(self::text(503, "Service unavailable"));
		});

		$this->logger->debug("Endpoint offer from network $networkId, assigned connection $connectionId");
		//the peer is reached over HTTP rather than a socket address, so there is none to record
		$this->transport->acceptOffer(
			new Signal(Signal::TYPE_OFFER, $connectionId, $offer),
			(int) $networkId,
			"",
			0,
			$sink
		);

		if(!$sink->hasReplied()){
			//negotiation runs on the transport's own tick, so nothing here would ever time it out
			$timer = Loop::addTimer(self::NEGOTIATION_TIMEOUT, function() use ($deferred, $sink, $connectionId) : void{
				if($sink->hasReplied()){
					return;
				}
				$this->logger->debug("Endpoint negotiation for connection $connectionId timed out");
				$deferred->resolve(self::text(504, "Timed out waiting for answer"));
			});
			$deferred->promise()->finally(static fn() => Loop::cancelTimer($timer));
		}
		return $deferred->promise();
	}

	private static function text(int $status, string $body) : Response{
		return new Response($status, ["Content-Type" => "text/plain; charset=utf-8", "Connection" => "close"], $body);
	}

	private static function json(int $status, string $body) : Response{
		return new Response($status, ["Content-Type" => "application/json", "Connection" => "close"], $body);
	}
}
