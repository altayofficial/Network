<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet;

use altay\network\nethernet\Credentials;
use altay\network\nethernet\IceServer;
use PHPUnit\Framework\TestCase;
use Webrtc\Webrtc\RTCConfiguration;

final class CredentialsTest extends TestCase{

	private const PAYLOAD = '{"ExpirationInSeconds":86400,"TurnAuthServers":[' .
		'{"Username":"user","Password":"pass","Urls":["turn:turn.example.com:3478"]},' .
		'{"Username":"","Password":"","Urls":["stun:stun.example.com:3478"]}' .
		']}';

	public function testParsesTheServicePayload() : void{
		$credentials = Credentials::fromJson(self::PAYLOAD, 1000);

		self::assertCount(2, $credentials->iceServers);
		self::assertSame(["turn:turn.example.com:3478"], $credentials->iceServers[0]->urls);
		self::assertSame("user", $credentials->iceServers[0]->username);
		self::assertSame("pass", $credentials->iceServers[0]->password);
		self::assertSame(87400, $credentials->expiresAt);
	}

	public function testAcceptsASingleUrlAsAString() : void{
		$server = IceServer::fromArray(["Urls" => "stun:stun.example.com:3478"]);

		self::assertSame(["stun:stun.example.com:3478"], $server->urls);
	}

	public function testRejectsAServerWithoutUrls() : void{
		$this->expectException(\InvalidArgumentException::class);
		IceServer::fromArray(["Username" => "user"]);
	}

	public function testExpiry() : void{
		$credentials = Credentials::fromJson(self::PAYLOAD, 1000);

		self::assertFalse($credentials->isExpired(87399));
		self::assertTrue($credentials->isExpired(87400));
	}

	public function testCredentialsWithoutLifetimeNeverExpire() : void{
		$credentials = Credentials::fromArray(["TurnAuthServers" => []], 1000);

		self::assertNull($credentials->expiresAt);
		self::assertFalse($credentials->isExpired(PHP_INT_MAX));
	}

	public function testConfigurationIsAcceptedByTheLibrary() : void{
		$credentials = Credentials::fromJson(self::PAYLOAD, 1000);
		$configuration = new RTCConfiguration($credentials->toPeerConnectionConfiguration());

		self::assertCount(2, $configuration->getIceServers());
		self::assertSame("user", $configuration->getIceServers()[0]->getUsername());
	}

	public function testStunOnlyServerIsAcceptedByTheLibrary() : void{
		$credentials = new Credentials([new IceServer(["stun:stun.example.com:3478"])]);
		$configuration = new RTCConfiguration($credentials->toPeerConnectionConfiguration());

		self::assertCount(1, $configuration->getIceServers());
		self::assertNull($configuration->getIceServers()[0]->getUsername());
	}
}
