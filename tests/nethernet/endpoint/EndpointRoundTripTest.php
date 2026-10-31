<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\endpoint;

use altay\network\nethernet\endpoint\EndpointClient;
use altay\network\nethernet\endpoint\EndpointHandler;
use altay\network\nethernet\endpoint\EndpointStatus;
use altay\network\nethernet\NetherNetTransport;
use altay\network\nethernet\ServerData;
use altay\network\tests\nethernet\DiscardingLogger;
use altay\network\tests\nethernet\RecordingListener;
use altay\network\tests\nethernet\RequiresPeerConnection;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;
use React\Promise\PromiseInterface;
use React\Socket\SocketServer;

final class EndpointRoundTripTest extends TestCase{

	use RequiresPeerConnection;

	private const HTTP_PORT = 17553;
	private const TRANSPORT_PORT = 17554;
	private const CLIENT_PORT = 17555;
	private const SHARED_MEDIA_PORT = 17556;
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
	 */
	public function testOfferPostedOverHttpEstablishesASession() : void{
		$this->requirePeerConnection();
		$logger = new DiscardingLogger();

		$serverEvents = new RecordingListener();
		$this->server = new NetherNetTransport($logger, self::SERVER_NETWORK, new ServerData("Altay", levelName: "World"), "127.0.0.1", self::TRANSPORT_PORT);
		$this->server->start($serverEvents);

		$this->socket = new SocketServer("127.0.0.1:" . self::HTTP_PORT);
		(new HttpServer(new EndpointHandler($this->server, $logger)))->listen($this->socket);

		//the dialling side still needs a transport of its own to own the peer connection
		$clientEvents = new RecordingListener();
		$this->client = new NetherNetTransport($logger, self::CLIENT_NETWORK, new ServerData("Client", levelName: "-"), "127.0.0.1", self::CLIENT_PORT);
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

	/**
	 * @group integration
	 */
	public function testAnswerAnnouncesTheHostThePlayerAskedFor() : void{
		$this->requirePeerConnection();
		$logger = new DiscardingLogger();

		//grab a real offer first, posting it to an endpoint that never answers
		$offer = null;
		$this->socket = new SocketServer("127.0.0.1:" . self::HTTP_PORT);
		(new HttpServer(static function(ServerRequestInterface $request) use (&$offer) : Response{
			$offer = (string) $request->getBody();
			return new Response(503);
		}))->listen($this->socket);
		$this->client = new NetherNetTransport($logger, self::CLIENT_NETWORK, new ServerData("Client", levelName: "-"), "127.0.0.1", self::CLIENT_PORT);
		$this->client->start(new RecordingListener());
		$this->client->dialEndpoint("http://127.0.0.1:" . self::HTTP_PORT);
		$deadline = microtime(true) + self::TIMEOUT;
		while($offer === null && microtime(true) < $deadline){
			$this->client->tick();
			usleep(20000);
		}
		self::assertIsString($offer, "the dialling side never posted an offer");

		$this->server = new NetherNetTransport($logger, self::SERVER_NETWORK, new ServerData("Altay", levelName: "World"), "127.0.0.1", self::TRANSPORT_PORT);
		$this->server->start(new RecordingListener());
		$request = new ServerRequest("POST", "http://203.0.113.9:19132/v1/join/" . self::CLIENT_NETWORK, [], $offer, "1.1", ["REMOTE_ADDR" => "127.0.0.1", "REMOTE_PORT" => 50000]);
		$answer = null;
		$result = (new EndpointHandler($this->server, $logger))($request);
		self::assertInstanceOf(PromiseInterface::class, $result);
		$result->then(static function(Response $response) use (&$answer) : void{
			$answer = (string) $response->getBody();
		});
		$deadline = microtime(true) + self::TIMEOUT;
		while($answer === null && microtime(true) < $deadline){
			$this->server->tick();
			usleep(20000);
		}

		self::assertIsString($answer, "the endpoint never answered");
		self::assertStringContainsString(" 203.0.113.9 ", $answer);
		self::assertStringContainsString(" typ host", $answer, "the gathered candidates have to stay");
	}

	public function testPingAnswersWithTheServerStatus() : void{
		$logger = new DiscardingLogger();
		$serverData = new ServerData("Altay", 2187, "1.26.50", "World", ServerData::GAME_TYPE_CREATIVE, 3, 10);
		$this->server = new NetherNetTransport($logger, self::SERVER_NETWORK, $serverData, "127.0.0.1", self::TRANSPORT_PORT);

		$handler = new EndpointHandler($this->server, $logger);
		$response = $handler(new \React\Http\Message\ServerRequest("GET", "http://127.0.0.1/v1/join"));

		self::assertSame(200, $response->getStatusCode());
		self::assertSame("application/json", $response->getHeaderLine("Content-Type"));
		self::assertEquals(EndpointStatus::fromServerData($serverData), EndpointStatus::fromJson((string) $response->getBody()));
	}

	public function testHeadIsAnsweredLikeAPing() : void{
		$logger = new DiscardingLogger();
		$this->server = new NetherNetTransport($logger, self::SERVER_NETWORK, new ServerData("Altay", levelName: "World"), "127.0.0.1", self::TRANSPORT_PORT);

		$handler = new EndpointHandler($this->server, $logger);
		$response = $handler(new \React\Http\Message\ServerRequest("HEAD", "http://127.0.0.1/v1/join"));

		self::assertInstanceOf(\React\Http\Message\Response::class, $response);
		self::assertSame(200, $response->getStatusCode());
		self::assertSame("application/json", $response->getHeaderLine("Content-Type"));
	}

	public function testTransportServesTheEndpointItWasGivenAnAddressFor() : void{
		$logger = new DiscardingLogger();
		$this->server = new NetherNetTransport(
			$logger,
			self::SERVER_NETWORK,
			new ServerData("Altay", levelName: "World"),
			"127.0.0.1",
			self::TRANSPORT_PORT,
			endpointAddress: "127.0.0.1:" . self::HTTP_PORT
		);
		$this->server->start(new RecordingListener());

		$status = null;
		(new EndpointClient(self::CLIENT_NETWORK))
			->ping("http://127.0.0.1:" . self::HTTP_PORT)
			->then(static function(EndpointStatus $received) use (&$status) : void{
				$status = $received;
			});

		$deadline = microtime(true) + self::TIMEOUT;
		while($status === null && microtime(true) < $deadline){
			$this->server->tick();
			usleep(20000);
		}

		self::assertNotNull($status, "the endpoint never answered the ping");
		self::assertSame("Altay", $status->serverName);
		self::assertSame("World", $status->levelName);
	}

	public function testTransportServesTheEndpointOnEveryAddressItWasGiven() : void{
		self::requireIpv6Loopback();
		$logger = new DiscardingLogger();
		$this->server = new NetherNetTransport(
			$logger,
			self::SERVER_NETWORK,
			new ServerData("Altay", levelName: "World"),
			"127.0.0.1",
			self::TRANSPORT_PORT,
			endpointAddress: "127.0.0.1:" . self::HTTP_PORT
		);
		$this->server->addEndpointAddress("[::1]:" . self::HTTP_PORT);
		$this->server->start(new RecordingListener());

		$statuses = [];
		foreach(["http://127.0.0.1:" . self::HTTP_PORT, "http://[::1]:" . self::HTTP_PORT] as $url){
			(new EndpointClient(self::CLIENT_NETWORK))
				->ping($url)
				->then(static function(EndpointStatus $received) use (&$statuses, $url) : void{
					$statuses[$url] = $received->serverName;
				});
		}

		$deadline = microtime(true) + self::TIMEOUT;
		while(count($statuses) < 2 && microtime(true) < $deadline){
			$this->server->tick();
			usleep(20000);
		}

		ksort($statuses);
		self::assertSame(["http://127.0.0.1:" . self::HTTP_PORT => "Altay", "http://[::1]:" . self::HTTP_PORT => "Altay"], $statuses, "both families should answer");
	}

	/**
	 * @return iterable<string, array{bool}>
	 */
	public static function mediaPortModes() : iterable{
		yield "a media port per player" => [false];
		yield "a shared media port" => [true];
	}

	/**
	 * Signals over the IPv6 listener and pins both agents to one global IPv6 address of this
	 * machine, so the only pair ICE can pick is an IPv6 one. That's the bracket handling on the
	 * bind, the candidate and every send. Loopback won't do for the media, offers naming it are
	 * refused on purpose.
	 *
	 * @group integration
	 * @dataProvider mediaPortModes
	 */
	public function testOfferPostedOverIpv6CarriesMediaOverIpv6(bool $sharedMediaPort) : void{
		$this->requirePeerConnection();
		self::requireIpv6Loopback();
		$media = self::globalIpv6Address();
		$logger = new DiscardingLogger();

		$serverEvents = new RecordingListener();
		$this->server = new NetherNetTransport(
			$logger,
			self::SERVER_NETWORK,
			new ServerData("Altay", levelName: "World"),
			"127.0.0.1",
			self::TRANSPORT_PORT,
			endpointAddress: "127.0.0.1:" . self::HTTP_PORT,
			iceInterfaces: [$media]
		);
		$this->server->addEndpointAddress("[::1]:" . self::HTTP_PORT);
		if($sharedMediaPort){
			$this->server->setUdpPort(self::SHARED_MEDIA_PORT);
		}
		$this->server->start($serverEvents);

		$clientEvents = new RecordingListener();
		$this->client = new NetherNetTransport($logger, self::CLIENT_NETWORK, new ServerData("Client", levelName: "-"), "127.0.0.1", self::CLIENT_PORT, iceInterfaces: [$media]);
		$this->client->start($clientEvents);

		$session = $this->client->dialEndpoint("http://[::1]:" . self::HTTP_PORT);

		$payload = "posted over ipv6";
		$sent = false;
		$deadline = microtime(true) + self::TIMEOUT;
		while(microtime(true) < $deadline){
			$this->server->tick();
			$this->client->tick();
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
		self::assertSame($media, $session->getAddress(), "media should flow over the IPv6 pair");
		if($sharedMediaPort){
			self::assertSame(self::SHARED_MEDIA_PORT, $session->getPort());
		}
	}

	private static function requireIpv6Loopback() : void{
		if(!self::deliversUdp("::1")){
			self::markTestSkipped("IPv6 loopback is missing or drops traffic here");
		}
	}

	/**
	 * A global IPv6 address of this machine that UDP actually gets through on, or a skip.
	 */
	private static function globalIpv6Address() : string{
		foreach(net_get_interfaces() ?: [] as $interface){
			foreach($interface["unicast"] ?? [] as $unicast){
				$address = $unicast["address"] ?? null;
				if(!is_string($address) || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false){
					continue;
				}
				$packed = (string) inet_pton($address);
				//loopback and link-local are both refused as candidates, ICE would never use them
				if($address === "::1" || (ord($packed[0]) === 0xfe && (ord($packed[1]) & 0xc0) === 0x80)){
					continue;
				}
				if(self::deliversUdp($address)){
					return $address;
				}
			}
		}
		self::markTestSkipped("this machine has no global IPv6 address that carries traffic");
	}

	private static function deliversUdp(string $address) : bool{
		//hmm, binding isn't enough, some sandboxes and containers let you bind and then drop
		//everything sent there, so only a datagram that actually comes back counts
		$probe = @stream_socket_server("udp://[$address]:0", $errno, $errstr, STREAM_SERVER_BIND);
		if($probe === false){
			return false;
		}
		$sender = @stream_socket_client("udp://" . stream_socket_get_name($probe, false), $errno, $errstr);
		$read = [$probe];
		$write = $except = null;
		$delivered = $sender !== false && @fwrite($sender, "probe") === 5 && @stream_select($read, $write, $except, 1) === 1;
		if($sender !== false){
			fclose($sender);
		}
		fclose($probe);
		return $delivered;
	}

	public function testClosesAConnectionThatOpensWithTls() : void{
		$logger = new DiscardingLogger();
		$this->server = new NetherNetTransport(
			$logger,
			self::SERVER_NETWORK,
			new ServerData("Altay", levelName: "World"),
			"127.0.0.1",
			self::TRANSPORT_PORT,
			endpointAddress: "127.0.0.1:" . self::HTTP_PORT
		);
		$this->server->start(new RecordingListener());

		$client = stream_socket_client("tcp://127.0.0.1:" . self::HTTP_PORT, $errno, $errstr, self::TIMEOUT);
		self::assertNotFalse($client, "could not reach the endpoint: $errstr");
		stream_set_blocking($client, false);
		//the first bytes of a TLS client hello: a handshake record for TLS 1.0 and up
		fwrite($client, "\x16\x03\x01\x00\x2f\x01");

		$closed = false;
		$deadline = microtime(true) + self::TIMEOUT;
		while(microtime(true) < $deadline){
			$this->server->tick();
			if(fread($client, 1) === "" && feof($client)){
				$closed = true;
				break;
			}
			usleep(20000);
		}
		fclose($client);

		self::assertTrue($closed, "the endpoint left the TLS attempt open");
	}

	public function testRejectsBadRequests() : void{
		$logger = new DiscardingLogger();
		$this->server = new NetherNetTransport($logger, self::SERVER_NETWORK, new ServerData(), "127.0.0.1", self::TRANSPORT_PORT);
		$handler = new EndpointHandler($this->server, $logger);

		self::assertSame(404, $handler(new \React\Http\Message\ServerRequest("POST", "http://127.0.0.1/nope"))->getStatusCode());
		self::assertSame(400, $handler(new \React\Http\Message\ServerRequest("POST", "http://127.0.0.1/v1/join"))->getStatusCode());
		self::assertSame(405, $handler(new \React\Http\Message\ServerRequest("PUT", "http://127.0.0.1/v1/join/1"))->getStatusCode());
		self::assertSame(400, $handler(new \React\Http\Message\ServerRequest("POST", "http://127.0.0.1/v1/join/1"))->getStatusCode());
		self::assertSame(400, $handler(new \React\Http\Message\ServerRequest("POST", "http://127.0.0.1/v1/join/18446744073709551616"))->getStatusCode());
	}
}
