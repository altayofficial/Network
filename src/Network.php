<?php

declare(strict_types=1);

namespace altay\network;

use altay\network\transport\Transport;
use altay\network\transport\TransportException;
use altay\network\transport\TransportListener;

final class Network{

	/** @var Transport[] */
	private array $transports = [];

	public function __construct(
		private TransportListener $listener
	){}

	public function registerTransport(Transport $transport) : void{
		$name = $transport->getName();
		if(isset($this->transports[$name])){
			throw new TransportException("Transport \"$name\" is already registered");
		}
		$this->transports[$name] = $transport;
	}

	public function getTransport(string $name) : ?Transport{
		return $this->transports[$name] ?? null;
	}

	/**
	 * @return Transport[]
	 */
	public function getTransports() : array{
		return $this->transports;
	}

	public function start() : void{
		foreach($this->transports as $transport){
			if(!$transport->isRunning()){
				$transport->start($this->listener);
			}
		}
	}

	public function tick() : void{
		foreach($this->transports as $transport){
			if($transport->isRunning()){
				$transport->tick();
			}
		}
	}

	public function shutdown() : void{
		foreach($this->transports as $transport){
			if($transport->isRunning()){
				$transport->shutdown();
			}
		}
	}
}
