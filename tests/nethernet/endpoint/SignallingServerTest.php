<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\endpoint;

use altay\network\nethernet\endpoint\SignallingServer;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\SocketServer;

final class SignallingServerTest extends TestCase{

	private const TIMEOUT = 5;

	private ?SocketServer $socket = null;
	private ?string $certificate = null;

	protected function tearDown() : void{
		$this->socket?->close();
		$this->socket = null;
		if($this->certificate !== null){
			@unlink($this->certificate);
			$this->certificate = null;
		}
	}

	/**
	 * @param mixed[] $tlsContext
	 */
	private function serve(array $tlsContext) : string{
		$this->socket = new SocketServer("127.0.0.1:0");
		$server = new HttpServer(static fn() => new Response(200, [], "ok"));
		$server->listen(new SignallingServer($this->socket, $tlsContext));

		$address = $this->socket->getAddress();
		self::assertNotNull($address);
		return str_replace("tcp://", "", $address);
	}

	/**
	 * @return string|null the response, or null when the connection was refused or closed unanswered
	 */
	private function request(string $uri) : ?string{
		$connector = new Connector(["tls" => ["verify_peer" => false, "verify_peer_name" => false]]);
		$response = null;
		$done = false;

		$connector->connect($uri)->then(function(ConnectionInterface $connection) use (&$response, &$done) : void{
			$connection->write("GET / HTTP/1.0\r\nHost: localhost\r\n\r\n");
			$connection->on("data", function(string $data) use (&$response) : void{
				$response = ($response ?? "") . $data;
			});
			$connection->on("close", function() use (&$done) : void{
				$done = true;
			});
		}, function() use (&$done) : void{
			$done = true;
		});

		//the transports drive the loop a tick at a time, so the test does the same
		$timeout = Loop::addTimer(self::TIMEOUT, static function() use (&$done) : void{
			$done = true;
		});
		while(!$done){
			Loop::futureTick(static fn() => Loop::stop());
			Loop::run();
			usleep(1000);
		}
		Loop::cancelTimer($timeout);
		return $response;
	}

	public function testServesPlaintextWithoutACertificate() : void{
		$address = $this->serve([]);

		self::assertStringStartsWith("HTTP/1.0 200", (string) $this->request("tcp://$address"));
	}

	public function testRefusesATlsHandshakeWithoutACertificate() : void{
		$address = $this->serve([]);

		//the client only falls back to plaintext once the handshake is refused, so it has to be
		self::assertNull($this->request("tls://$address"));
	}

	public function testServesTlsWithACertificate() : void{
		$address = $this->serve(["local_cert" => $this->selfSignedCertificate()]);

		self::assertStringStartsWith("HTTP/1.0 200", (string) $this->request("tls://$address"));
	}

	public function testStillServesPlaintextWithACertificate() : void{
		$address = $this->serve(["local_cert" => $this->selfSignedCertificate()]);

		self::assertStringStartsWith("HTTP/1.0 200", (string) $this->request("tcp://$address"));
	}

	private function selfSignedCertificate() : string{
		$key = openssl_pkey_new(["private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "prime256v1"]);
		self::assertNotFalse($key);
		$csr = openssl_csr_new(["commonName" => "localhost"], $key, ["digest_alg" => "sha256"]);
		self::assertNotFalse($csr);
		$certificate = openssl_csr_sign($csr, null, $key, 1, ["digest_alg" => "sha256"]);
		self::assertNotFalse($certificate);

		openssl_x509_export($certificate, $pem);
		openssl_pkey_export($key, $keyPem);

		$this->certificate = tempnam(sys_get_temp_dir(), "nethernet-tls");
		file_put_contents($this->certificate, $pem . $keyPem);
		return $this->certificate;
	}
}
