<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\endpoint;

use altay\network\nethernet\endpoint\EndpointClient;
use altay\network\nethernet\endpoint\EndpointHandler;
use altay\network\nethernet\NetherNetTransport;
use altay\network\nethernet\ServerData;
use altay\network\tests\nethernet\DiscardingLogger;
use altay\network\tests\nethernet\RecordingListener;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Socket\SocketServer;

final class EndpointRoundTripTest extends TestCase{

	private const HTTP_PORT = 17553;
	private const TRANSPORT_PORT = 17554;
	private const CLIENT_PORT = 17555;
	private const SERVER_NETWORK = 333;
	private const CLIENT_NETWORK = 444;
	private const TIMEOUT = 30;

	private ?NetherNetTransport $server = null;
	private ?NetherNetTransport $client = null;
	private ?SocketServer $socket = null;

	protected function tearDown() : void{
		$this->socket?->close();
		$this->client?->shutdown();
		$this->server?->shutdown();
	}

	/**
	 * @group integration
	 * @requires extension ffi
	 */
	public function testOfferPostedOverHttpEstablishesASession() : void{
		$logger = new DiscardingLogger();

		$serverEvents = new RecordingListener();
		$this->server = new NetherNetTransport($logger, self::SERVER_NETWORK, new ServerData("Altay", "World"), "127.0.0.1", self::TRANSPORT_PORT);
		$this->server->start($serverEvents);

		$this->socket = new SocketServer("127.0.0.1:" . self::HTTP_PORT);
		(new HttpServer(new EndpointHandler($this->server, $logger)))->listen($this->socket);

		//the dialling side still needs a transport of its own to own the peer connection
		$clientEvents = new RecordingListener();
		$this->client = new NetherNetTransport($logger, self::CLIENT_NETWORK, new ServerData("Client", "-"), "127.0.0.1", self::CLIENT_PORT);
		$this->client->start($clientEvents);

		$session = $this->client->dialEndpoint("http://127.0.0.1:" . self::HTTP_PORT);

		$payload = "posted over http";
		$sent = false;
		$deadline = microtime(true) + self::TIMEOUT;
		while(microtime(true) < $deadline){
			$this->server->tick();
			$this->client->tick();
			//the HTTP server and the Browser both run on the shared loop, which the transports only
			//advance one tick at a time
			Loop::futureTick(static fn() => Loop::stop());
			Loop::run();

			if(!$sent && $clientEvents->opened && $serverEvents->opened){
				$session->sendPacket($payload);
				$sent = true;
			}
			if($sent && $serverEvents->packets !== []){
				break;
			}
			usleep(20000);
		}

		self::assertTrue($clientEvents->opened, "the dialling side never reported the session open");
		self::assertTrue($serverEvents->opened, "the listening side never reported the session open");
		self::assertSame([$payload], $serverEvents->packets);
	}

	public function testReadinessProbe() : void{
		$logger = new DiscardingLogger();
		$this->server = new NetherNetTransport($logger, self::SERVER_NETWORK, new ServerData(), "127.0.0.1", self::TRANSPORT_PORT);

		$handler = new EndpointHandler($this->server, $logger);
		$response = $handler(new \React\Http\Message\ServerRequest("GET", "http://127.0.0.1/v1/join"));

		self::assertSame(200, $response->getStatusCode());
	}

	public function testRejectsBadRequests() : void{
		$logger = new DiscardingLogger();
		$this->server = new NetherNetTransport($logger, self::SERVER_NETWORK, new ServerData(), "127.0.0.1", self::TRANSPORT_PORT);
		$handler = new EndpointHandler($this->server, $logger);

		self::assertSame(404, $handler(new \React\Http\Message\ServerRequest("POST", "http://127.0.0.1/nope"))->getStatusCode());
		self::assertSame(400, $handler(new \React\Http\Message\ServerRequest("POST", "http://127.0.0.1/v1/join"))->getStatusCode());
		self::assertSame(405, $handler(new \React\Http\Message\ServerRequest("PUT", "http://127.0.0.1/v1/join/1"))->getStatusCode());
		self::assertSame(400, $handler(new \React\Http\Message\ServerRequest("POST", "http://127.0.0.1/v1/join/1"))->getStatusCode());
	}
}
