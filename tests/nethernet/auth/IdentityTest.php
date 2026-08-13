<?php

declare(strict_types=1);

namespace altay\network\tests\nethernet\auth;

use altay\network\nethernet\auth\ClientIdentityAssertion;
use altay\network\nethernet\auth\IdentityException;
use altay\network\nethernet\auth\JwsEs384;
use altay\network\nethernet\auth\ServerIdentity;
use altay\network\nethernet\auth\SdpFingerprints;
use PHPUnit\Framework\TestCase;

final class IdentityTest extends TestCase{

	private const SDP_TEMPLATE = "v=0\r\no=- 1 2 IN IP4 127.0.0.1\r\ns=-\r\nt=0 0\r\na=fingerprint:sha-256 %s\r\nm=application 9 UDP/DTLS/SCTP webrtc-datachannel\r\n";

	private \OpenSSLAsymmetricKey $clientKey;
	private string $clientPublicKey;

	protected function setUp() : void{
		$this->clientKey = openssl_pkey_new(["private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "secp384r1"]);
		$pem = openssl_pkey_get_details($this->clientKey)["key"];
		$this->clientPublicKey = str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n", "\r"], "", $pem);
	}

	private function sdp(string $fingerprint) : string{
		return sprintf(self::SDP_TEMPLATE, $fingerprint);
	}

	private function fingerprint(string $seed) : string{
		return strtoupper(implode(":", str_split(hash("sha256", $seed), 2)));
	}

	/**
	 * @param array<string, mixed>|null $idp
	 */
	private function buildClientOffer(string $fingerprint, ?int $exp = null, ?array $idp = ["domain" => "test", "protocol" => "default"]) : string{
		$b64u = fn(string $d) => JwsEs384::base64UrlEncode($d);
		$header = $b64u(json_encode(["alg" => "ES384"]));
		$claims = $b64u(json_encode(["cpk" => $this->clientPublicKey, "exp" => $exp ?? time() + 300]));
		$token = "$header.$claims." . $b64u(JwsEs384::sign("$header.$claims", $this->clientKey));

		$sdp = $this->sdp($fingerprint);
		$payload = SdpFingerprints::canonicalPayload($sdp);
		$fpJws = "$header.." . $b64u(JwsEs384::sign("$header." . $b64u($payload), $this->clientKey));

		$data = ["assertion" => json_encode(["fingerprints" => $fpJws, "token" => $token])];
		if($idp !== null){
			$data["idp"] = $idp;
		}
		return $sdp . "a=identity:" . base64_encode(json_encode($data)) . "\r\n";
	}

	public function testValidAssertionVerifies() : void{
		$fp = $this->fingerprint("cert");
		$offer = $this->buildClientOffer($fp);
		$assertion = ClientIdentityAssertion::fromSdp($offer);
		self::assertNotNull($assertion);
		$assertion->verify($offer);
		self::assertSame($this->clientPublicKey, $assertion->getPublicKeyBase64());
	}

	public function testTamperedFingerprintRejected() : void{
		$offer = $this->buildClientOffer($this->fingerprint("cert"));
		$tampered = str_replace($this->fingerprint("cert"), $this->fingerprint("evil"), $offer);
		$assertion = ClientIdentityAssertion::fromSdp($tampered);
		self::assertNotNull($assertion);
		$this->expectException(IdentityException::class);
		$assertion->verify($tampered);
	}

	public function testExpiredTokenRejected() : void{
		$this->expectException(IdentityException::class);
		ClientIdentityAssertion::fromSdp($this->buildClientOffer($this->fingerprint("cert"), time() - 10));
	}

	public function testMissingIdentityReturnsNull() : void{
		self::assertNull(ClientIdentityAssertion::fromSdp($this->sdp($this->fingerprint("cert"))));
	}

	public function testMissingIdentityProviderRejected() : void{
		$this->expectException(IdentityException::class);
		ClientIdentityAssertion::fromSdp($this->buildClientOffer($this->fingerprint("cert"), null, null));
	}

	public function testUnsupportedIdentityProviderProtocolRejected() : void{
		$this->expectException(IdentityException::class);
		ClientIdentityAssertion::fromSdp($this->buildClientOffer($this->fingerprint("cert"), null, ["domain" => "test", "protocol" => "custom"]));
	}

	public function testEmptyIdentityProviderDomainRejected() : void{
		$this->expectException(IdentityException::class);
		ClientIdentityAssertion::fromSdp($this->buildClientOffer($this->fingerprint("cert"), null, ["domain" => "", "protocol" => "default"]));
	}

	public function testServerIdentityRoundTrip() : void{
		$server = ServerIdentity::generate();
		$answer = $this->sdp($this->fingerprint("servercert"));
		$attribute = $server->createIdentityAttribute($answer);
		self::assertNotNull($attribute);
		$answerWith = str_replace("m=", "a=identity:$attribute\r\nm=", $answer);
		$assertion = ClientIdentityAssertion::fromSdp($answerWith);
		self::assertNotNull($assertion);
		$assertion->verify($answerWith);
		self::assertSame($server->getPublicKeyBase64(), $assertion->getPublicKeyBase64());
	}
}
