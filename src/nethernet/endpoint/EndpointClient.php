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

use Psr\Http\Message\ResponseInterface;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use function parse_url;
use function rtrim;
use function sprintf;
use function strlen;

final class EndpointClient{

	private Browser $browser;

	public function __construct(
		private int $networkId,
		?Browser $browser = null,
		float $timeout = 15.0
	){
		$this->browser = ($browser ?? new Browser())
			->withTimeout($timeout)
			//an error status carries a diagnostic body worth reading rather than throwing on
			->withRejectErrorResponse(false);
	}

	/**
	 * @param string $baseUrl the remote server's endpoint, for example https://example.com:443
	 *
	 * @return PromiseInterface<string>
	 * @throws \InvalidArgumentException when the base URL is not usable
	 */
	public function offer(string $baseUrl, string $sdp) : PromiseInterface{
		$parts = parse_url($baseUrl);
		if($parts === false || !isset($parts["scheme"], $parts["host"]) || ($parts["scheme"] !== "http" && $parts["scheme"] !== "https")){
			throw new \InvalidArgumentException("Endpoint address must be an HTTP or HTTPS URL: $baseUrl");
		}
		if(strlen($sdp) > EndpointHandler::MAX_BODY_LENGTH){
			throw new \InvalidArgumentException("SDP offer is larger than the " . EndpointHandler::MAX_BODY_LENGTH . " byte limit");
		}

		$url = sprintf("%s/v1/join/%d", rtrim($baseUrl, "/"), $this->networkId);

		return $this->browser
			->post($url, ["Content-Type" => "text/plain"], $sdp)
			->then(static function(ResponseInterface $response) use ($url) : string{
				$body = (string) $response->getBody();
				if($response->getStatusCode() !== 200){
					throw new EndpointException(sprintf("POST %s: %d %s: %s", $url, $response->getStatusCode(), $response->getReasonPhrase(), $body));
				}
				if($body === ""){
					throw new EndpointException("Endpoint returned an empty answer");
				}
				return $body;
			});
	}
}
