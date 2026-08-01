<?php

declare(strict_types=1);

namespace altay\network\nethernet;

final class Signal{

	public const TYPE_OFFER = "CONNECTREQUEST";
	public const TYPE_ANSWER = "CONNECTRESPONSE";
	public const TYPE_CANDIDATE = "CANDIDATEADD";
	public const TYPE_ERROR = "CONNECTERROR";

	public function __construct(
		public string $type,
		public int $connectionId,
		public string $data
	){}

	public static function fromString(string $str) : ?self{
		$parts = explode(" ", $str, 3);
		if(count($parts) !== 3 || !ctype_digit($parts[1])){
			return null;
		}
		return new self($parts[0], (int) $parts[1], $parts[2]);
	}

	public function __toString() : string{
		return $this->type . " " . $this->connectionId . " " . $this->data;
	}
}
