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

use altay\network\nethernet\discovery\AddressBook;
use altay\network\nethernet\endpoint\EndpointClient;
use altay\network\nethernet\endpoint\EndpointHandler;
use altay\network\nethernet\endpoint\EndpointSignalSink;
use altay\network\nethernet\endpoint\SignallingServer;
use altay\network\nethernet\discovery\DiscoveryCodec;
use altay\network\nethernet\discovery\DiscoveryMessagePacket;
use altay\network\nethernet\discovery\DiscoveryRequestPacket;
use altay\network\nethernet\discovery\DiscoveryResponsePacket;
use altay\network\nethernet\auth\ClientIdentityAssertion;
use altay\network\nethernet\auth\IdentityException;
use altay\network\nethernet\auth\ServerIdentity;
use altay\network\nethernet\auth\TokenTrust;
use altay\network\nethernet\sdp\AdvertisedAddresses;
use altay\network\nethernet\sdp\AnswerRewriter;
use altay\network\nethernet\sdp\CandidateInference;
use altay\network\nethernet\sdp\IceCandidate;
use altay\network\nethernet\sdp\SessionDescription;
use altay\network\nethernet\types\SignalErrorCode;
use altay\network\transport\AddressBlockingTransport;
use altay\network\transport\NameableTransport;
use altay\network\transport\RawPacketTransport;
use altay\network\transport\TransportException;
use altay\network\transport\TransportListener;
use altay\network\utils\Uint64;
use pocketmine\utils\Binary;
use React\Dns\Config\Config as DnsConfig;
use React\Dns\Resolver\Factory as DnsResolverFactory;
use React\Dns\Resolver\ResolverInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer;
use React\Http\Middleware\LimitConcurrentRequestsMiddleware;
use React\Http\Middleware\RequestBodyBufferMiddleware;
use React\Socket\SocketServer;
use function React\Promise\set_rejection_handler;
use Webrtc\DataChannel\RTCDataChannel;
use Webrtc\DataChannel\RTCDataChannelParameters;
use Webrtc\ICE\Enum\CandidateType;
use Webrtc\ICE\Enum\IceGatheringState;
use Webrtc\ICE\Enum\TransportPolicyType;
use Webrtc\ICE\RTCIceCandidate;
use Webrtc\SDP\RTCSessionDescription;
use Webrtc\STUN\UdpMux;
use Webrtc\Webrtc\Enum\ConnectionState;
use Webrtc\Webrtc\RTCConfiguration;
use Webrtc\Webrtc\RTCPeerConnection;

final class NetherNetTransport implements NameableTransport, AddressBlockingTransport, RawPacketTransport{

	public const DISCOVERY_PORT = 7551;

	/**
	 * How long a join has from its offer until both data channels are open. A player relayed over
	 * TURN or on a slow mobile link easily needs more than a LAN peer does.
	 */
	public const DEFAULT_HANDSHAKE_TIMEOUT = 30;
	/**
	 * How long an established session may stay silent. The peer connection reports nothing when a
	 * client disappears without closing, so without this the session - and the handful of sockets
	 * its ICE agent holds - would stay for as long as the server runs.
	 */
	private const SESSION_IDLE_TIMEOUT = 30;
	private const MAINTENANCE_INTERVAL = 1;
	private const SIGNAL_SOCKET_BUFFER = 4 * 1024 * 1024;
	private const SIGNAL_RETRANSMIT_INTERVAL = 2;

	/**
	 * Negotiating a connection costs a DTLS handshake and an ICE agent, and nothing about an offer is
	 * authenticated, so what one peer may have in flight has to be capped, or an anonymous peer can
	 * keep the transport thread busy enough to starve the players already on the server.
	 *
	 * What is capped is the negotiations an address holds open at once, not how many it may start.
	 * A join is over in a fraction of a second, so a household, a school or a mobile carrier - all of
	 * which put many players behind one address - never stack up more than a handful, while a peer
	 * that opens negotiations and abandons them is stopped at the point where it starts costing
	 * something.
	 */
	private const MAX_PENDING_NEGOTIATIONS = 64;
	private const MAX_NEGOTIATIONS_PER_ADDRESS = 32;
	private const MAX_REMOTE_CANDIDATES = 32;

	private int $maxPendingNegotiations = self::MAX_PENDING_NEGOTIATIONS;
	private int $maxNegotiationsPerAddress = self::MAX_NEGOTIATIONS_PER_ADDRESS;
	private int $handshakeTimeout = self::DEFAULT_HANDSHAKE_TIMEOUT;
	private ?int $udpPort = null;
	private ?UdpMux $udpMux = null;
	private bool $udpPortRequired = true;
	/** @var string[] patterns a datagram on the media port no agent claims has to match to reach the listener */
	private array $rawPacketFilters = [];

	private const ENDPOINT_MAX_CONCURRENT_REQUESTS = 64;

	/**
	 * How long a signalling channel that cannot carry candidates on their own waits for gathering to
	 * finish. A TURN server that never answers must not hold the description back forever, so the
	 * peer gets whatever was gathered by the time this runs out.
	 */
	private const GATHERING_TIMEOUT = 5;

	/** How long a gap between two ticks has to be before it is worth reporting, in seconds */
	private const STALL_THRESHOLD = 0.1;

	private ?\Socket $socket = null;
	/** @var SocketServer[] */
	private array $endpointSockets = [];
	/** @var string[] */
	private array $extraEndpointAddresses = [];
	private ?TransportListener $listener = null;
	private ?ServerIdentity $identity = null;
	private bool $running = false;
	private ?DtlsCertificateFiles $certificateFiles = null;
	private ?string $discoveryResponse = null;
	/** @var array<string, int> address => unix time the block expires */
	private array $blockedAddresses = [];
	/** @var array<string, array{connection: RTCPeerConnection, networkId: int, address: string, port: int, publicKey: ?string, createdAt: int, outgoing: bool, sink: SignalSink, offer: ?string, answer: ?string, candidates: list<string>, peerMaxMessageSize: ?int, lastSignalAt: float}> */
	private array $pending = [];
	/** @var NetherNetSession[] */
	private array $sessions = [];

	private ?float $lastTickAt = null;

	private ?Credentials $signallingCredentials = null;

	private AddressBook $addressBook;
	private AdvertisedAddresses $advertised;
	private int $lastMaintenance = 0;

	/**
	 * @param string[] $iceInterfaces
	 * @param string[] $advertisedAddresses
	 * @param array{int, int}|null $icePortRange
	 */
	public function __construct(
		private \Logger $logger,
		private int $networkId,
		private ServerData $serverData,
		private string $bindAddress = "0.0.0.0",
		private int $port = self::DISCOVERY_PORT,
		private bool $requireIdentity = false,
		private ?Credentials $credentials = null,
		private ?string $endpointAddress = null,
		private ?string $identityKeyPath = null,
		private string $identityDomain = "self",
		private bool $relayOnly = false,
		private bool $requireEndpointIdentity = false,
		private array $iceInterfaces = [],
		array $advertisedAddresses = [],
		private ?array $icePortRange = null,
		private ?string $tlsCertificatePath = null,
		private ?string $tlsKeyPath = null,
		private TokenTrust $tokenTrust = TokenTrust::ANY,
		private ?TokenTrust $endpointTokenTrust = null,
		private bool $inferPeerCandidates = true,
		private bool $verboseLogging = false
	){
		$this->addressBook = new AddressBook();
		$this->advertised = new AdvertisedAddresses($advertisedAddresses);
	}

	/**
	 * Raises or lowers what an unauthenticated peer may ask of the transport: how many negotiations
	 * may be in flight in total, and how many of those one address may hold.
	 */
	public function setNegotiationLimits(int $maxPendingNegotiations, int $maxNegotiationsPerAddress) : void{
		if($maxPendingNegotiations < 1 || $maxNegotiationsPerAddress < 1){
			throw new \InvalidArgumentException("Negotiation limits must be positive");
		}
		$this->maxPendingNegotiations = $maxPendingNegotiations;
		$this->maxNegotiationsPerAddress = $maxNegotiationsPerAddress;
	}

	/**
	 * How many seconds a join may take from its offer until the session opens.
	 */
	public function setHandshakeTimeout(int $seconds) : void{
		if($seconds < 1){
			throw new \InvalidArgumentException("Handshake timeout must be positive");
		}
		$this->handshakeTimeout = $seconds;
	}

	/**
	 * The ICE servers a signalling service handed out, a TURN auth reply for example. They are used
	 * alongside the ones the transport was configured with, not instead of them, and dropped again
	 * once they expire.
	 */
	/**
	 * Has every player's media share one UDP port, instead of each getting one of their own, so
	 * that one port is all the firewall needs to let through. The port range is ignored while this
	 * is set. Has to be called before start().
	 *
	 * With $required off, a port something else already holds is only worth a warning, and every
	 * player gets a port of their own instead. That is for a port nobody asked for explicitly.
	 */
	public function setUdpPort(?int $port, bool $required = true) : void{
		if($port !== null && ($port < 1 || $port > 65535)){
			throw new \InvalidArgumentException("Invalid UDP port $port");
		}
		if($this->running){
			throw new TransportException("The UDP port cannot change while the transport is running");
		}
		$this->udpPort = $port;
		$this->udpPortRequired = $required;
	}

	/**
	 * Serves the join endpoint on one more address, usually an IPv6 one next to the IPv4 bind,
	 * since a socket only ever listens on one address family. When ICE is limited to the bound
	 * address, this one's host is gathered on too. Has to be called before start().
	 */
	public function addEndpointAddress(string $address) : void{
		if($this->running){
			throw new TransportException("Endpoint addresses cannot change while the transport is running");
		}
		$this->extraEndpointAddresses[] = $address;
	}

	public function setCredentials(?Credentials $credentials) : void{
		$this->signallingCredentials = $credentials;
	}

	public function getName() : string{
		return "nethernet";
	}

	public function getAddressBook() : AddressBook{
		return $this->addressBook;
	}

	public function getNetworkId() : int{
		return $this->networkId;
	}

	public function getServerData() : ServerData{
		return $this->serverData;
	}

	public function getServerId() : ?int{
		//NetherNet identifies servers by their network ID, it has no separate RakNet-style server GUID
		return null;
	}

	public function setName(string $name) : void{
		$this->discoveryResponse = null;
		$parts = explode(";", $name);
		if(count($parts) < 9 || $parts[0] !== "MCPE"){
			$this->serverData->serverName = $name;
			return;
		}
		$this->serverData->serverName = $parts[1];
		$this->serverData->protocol = (int) $parts[2];
		$this->serverData->gameVersion = $parts[3];
		//the level name is the title of the world card and the seventh field carries the software
		//name, which is not it - whatever the transport was given at construction stays
		$this->serverData->playerCount = (int) $parts[4];
		$this->serverData->maxPlayerCount = (int) $parts[5];
		$this->serverData->gameType = match($parts[8]){
			"Creative" => ServerData::GAME_TYPE_CREATIVE,
			"Adventure" => ServerData::GAME_TYPE_ADVENTURE,
			default => ServerData::GAME_TYPE_SURVIVAL
		};
	}

	public function start(TransportListener $listener) : void{
		if($this->running){
			throw new TransportException("NetherNet transport is already running");
		}
		$this->socket = $this->createDiscoverySocket();
		if($this->socket === null && $this->endpointAddress === null && $this->extraEndpointAddresses === []){
			throw new TransportException("NetherNet transport has no way to receive signalling: discovery is unavailable and no endpoint is configured");
		}
		try{
			$this->udpMux = $this->createUdpMux();
		}catch(TransportException $e){
			if($this->udpPortRequired){
				if($this->socket !== null){
					socket_close($this->socket);
					$this->socket = null;
				}
				throw $e;
			}
			$this->logger->warning($e->getMessage() . ", giving every player a media port of their own instead");
		}
		$this->listener = $listener;
		$this->identity = $this->makeIdentity();
		try{
			$this->certificateFiles = DtlsCertificateFiles::generate();
		}catch(\RuntimeException $e){
			//a per-connection key pair still works, it is just much more expensive to hand out
			$this->logger->warning("Failed to prepare a shared DTLS certificate: " . $e->getMessage());
		}
		$this->running = true;

		set_rejection_handler(function(\Throwable $reason) : void{
			$this->logger->debug("Ignoring unhandled WebRTC promise rejection: " . $reason->getMessage());
		});

		if($this->socket !== null){
			$this->logger->info("NetherNet transport listening for discovery on $this->bindAddress:$this->port");
		}

		$endpointAddresses = $this->extraEndpointAddresses;
		if($this->endpointAddress !== null){
			array_unshift($endpointAddresses, $this->endpointAddress);
		}
		try{
			foreach($endpointAddresses as $address){
				$this->startEndpoint($address);
			}
		}catch(TransportException $e){
			//hmm, half a set of listeners is worse than none, the operator asked for all of them
			$this->closeEndpoints();
			throw $e;
		}
	}

	/**
	 * Returns null if the discovery port is unusable. That only costs the server its LAN
	 * advertisement, so the transport carries on with whatever signalling is left.
	 *
	 * @throws TransportException
	 */
	private function createDiscoverySocket() : ?\Socket{
		$socket = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if($socket === false){
			throw new TransportException("Failed to create discovery socket: " . socket_strerror(socket_last_error()));
		}
		@socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
		@socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
		//an SDP offer is several kilobytes, so the default receive buffer only holds a couple of
		//dozen of them. A join burst overflows it, and since signalling has no retransmission of its
		//own every dropped offer stranded a connection until it timed out
		@socket_set_option($socket, SOL_SOCKET, SO_RCVBUF, self::SIGNAL_SOCKET_BUFFER);
		@socket_set_option($socket, SOL_SOCKET, SO_SNDBUF, self::SIGNAL_SOCKET_BUFFER);
		if(!@socket_bind($socket, $this->bindAddress, $this->port)){
			$error = socket_strerror(socket_last_error($socket));
			socket_close($socket);
			$this->logger->warning("Failed to bind NetherNet discovery socket to $this->bindAddress:$this->port: $error");
			$this->logger->warning("The server won't show up on the Friends tab. Free port $this->port and restart to fix it.");
			return null;
		}
		socket_set_nonblock($socket);
		return $socket;
	}

	/**
	 * Binds the shared media port up front when the addresses it is needed on are known, so a taken
	 * port is reported here rather than as joins that never find a path.
	 *
	 * @throws TransportException
	 */
	private function createUdpMux() : ?UdpMux{
		if($this->udpPort === null){
			return null;
		}
		$mux = new UdpMux($this->udpPort);
		$interfaces = $this->iceInterfaces !== [] ? $this->iceInterfaces : $this->boundInterface();
		if($interfaces === []){
			//bound to every address, which ICE would only bind one at a time as players show up, and
			//until then nothing else sharing the port (query) gets an answer
			$interfaces = self::localIpv4Addresses();
		}
		foreach($interfaces as $interface){
			try{
				$mux->listen($interface);
			}catch(\RuntimeException $e){
				$mux->close();
				throw new TransportException("Failed to bind the NetherNet media port on $interface:$this->udpPort: " . $e->getMessage(), 0, $e);
			}
		}
		$mux->onUnrouted(function(string $data, string $peer) : void{
			$this->handleUnrouted($data, $peer);
		});
		$this->logger->info("NetherNet media shares udp/$this->udpPort");
		return $mux;
	}

	private function makeIdentity() : ServerIdentity{
		if($this->identityKeyPath !== null){
			try{
				return ServerIdentity::loadOrCreate($this->identityKeyPath, $this->identityDomain);
			}catch(\RuntimeException $e){
				$this->logger->warning("Failed to use the NetherNet identity key at $this->identityKeyPath: " . $e->getMessage());
				$this->logger->warning("Falling back to a temporary identity, which changes on every restart.");
			}
		}
		return ServerIdentity::generate($this->identityDomain);
	}

	/**
	 * @throws TransportException
	 */
	private function startEndpoint(string $address) : void{
		//wait, Linux opens [::] dual stack, so it would also claim the IPv4 port the other listener
		//already holds. IPv4 has its own socket here, so the IPv6 one only takes IPv6
		$context = str_starts_with($address, "[") ? ["tcp" => ["ipv6_v6only" => true]] : [];
		try{
			$socket = new SocketServer($address, $context);
		}catch(\RuntimeException | \InvalidArgumentException $e){
			throw new TransportException("Failed to bind endpoint signalling socket to $address: " . $e->getMessage(), 0, $e);
		}
		//the body limit the handler enforces has to be applied while buffering as well, or React
		//would happily hold post_max_size worth of offer per request before the handler ever runs
		$server = new HttpServer(
			new LimitConcurrentRequestsMiddleware(self::ENDPOINT_MAX_CONCURRENT_REQUESTS),
			new RequestBodyBufferMiddleware(EndpointHandler::MAX_BODY_LENGTH),
			new EndpointHandler($this, $this->logger, $this->advertised->isEmpty() ? $this->makeResolver() : null)
		);
		$server->on("error", function(\Throwable $e) : void{
			$this->logger->debug("Endpoint signalling error: " . $e->getMessage());
		});
		$server->listen(new SignallingServer($socket, $this->tlsContext()));
		$this->endpointSockets[] = $socket;
	}

	private function closeEndpoints() : void{
		foreach($this->endpointSockets as $socket){
			$socket->close();
		}
		$this->endpointSockets = [];
	}

	/**
	 * Only needed to announce the host a player joined by when the operator named no addresses, so
	 * a machine without a usable resolver just announces the literal ones.
	 */
	private function makeResolver() : ?ResolverInterface{
		try{
			$nameserver = DnsConfig::loadSystemConfigBlocking()->nameservers[0] ?? null;
		}catch(\RuntimeException){
			$nameserver = null;
		}
		return $nameserver !== null ? (new DnsResolverFactory())->createCached($nameserver) : null;
	}

	/**
	 * The certificate the endpoint serves TLS with, if it was given one. Clients open with a
	 * handshake either way, so this only decides whether that handshake gets an answer or has to
	 * fall back to plaintext.
	 *
	 * @return mixed[]
	 */
	private function tlsContext() : array{
		if($this->tlsCertificatePath === null){
			return [];
		}
		if(!is_file($this->tlsCertificatePath)){
			$this->logger->warning("Cannot serve HTTPS signalling: no certificate at $this->tlsCertificatePath");
			$this->logger->warning("Clients will fall back to plaintext on the same port.");
			return [];
		}
		$context = ["local_cert" => $this->tlsCertificatePath];
		if($this->tlsKeyPath !== null){
			if(!is_file($this->tlsKeyPath)){
				$this->logger->warning("Cannot serve HTTPS signalling: no private key at $this->tlsKeyPath");
				return [];
			}
			$context["local_pk"] = $this->tlsKeyPath;
		}
		$this->logger->info("Serving HTTPS signalling with the certificate at $this->tlsCertificatePath");
		return $context;
	}

	public function tick() : void{
		if(!$this->running){
			return;
		}
		$this->reportStall();
		while($this->socket !== null){
			$buffer = "";
			$address = "";
			$port = 0;
			$received = @socket_recvfrom($this->socket, $buffer, 65535, 0, $address, $port);
			if($received === false || $received <= 0){
				break;
			}
			$this->handleDatagram($buffer, $address, $port);
		}

		Loop::futureTick(static function() : void{
			Loop::stop();
		});
		try{
			Loop::run();
		}catch(\Throwable $e){
			//an unhandled promise rejection from a dying peer connection must not kill the whole transport
			$this->logger->error("Error while running WebRTC event loop: " . $e->getMessage());
		}

		$now = time();
		if($now - $this->lastMaintenance >= self::MAINTENANCE_INTERVAL){
			$this->lastMaintenance = $now;
			$this->retransmitPendingSignals();
			$this->expireStalePending($now);
			$this->expireUnreadySessions($now);
			$this->expireIdleSessions($now);
			$this->expireRateLimits($now);
			$this->addressBook->expire($now);
			$this->reportBandwidth();
			$this->reportPings();
		}
	}

	/**
	 * NetherNet signalling is a bare datagram exchange, so a dropped offer or answer used to strand
	 * the negotiation until it timed out. Repeating the last thing sent, until the negotiation moves
	 * on or expires, is enough to recover: a repeated offer is answered from cache, and a repeated
	 * answer is applied to a connection that is still waiting for one.
	 */
	private function retransmitPendingSignals() : void{
		$now = microtime(true);
		foreach($this->pending as $connectionId => $entry){
			if($now - $entry["lastSignalAt"] < self::SIGNAL_RETRANSMIT_INTERVAL){
				continue;
			}
			$connectionId = (string) $connectionId;
			if($entry["outgoing"]){
				if($entry["offer"] === null){
					continue;
				}
				$this->logger->debug("Repeating offer for connection $connectionId, no answer yet");
				$this->repeatDescription($connectionId, $entry, Signal::TYPE_OFFER, $entry["offer"]);
			}else{
				if($entry["answer"] === null){
					continue;
				}
				$this->logger->debug("Repeating answer for connection $connectionId, the peer has not connected yet");
				$this->repeatDescription($connectionId, $entry, Signal::TYPE_ANSWER, $entry["answer"]);
			}
			$this->pending[$connectionId]["lastSignalAt"] = $now;
		}
	}

	/**
	 * A trickled description carries none of its candidates, so repeating it alone would leave the
	 * peer with nowhere to connect to if the candidates were what got lost.
	 *
	 * @param array{sink: SignalSink, candidates: list<string>} $entry
	 */
	private function repeatDescription(string $connectionId, array $entry, string $type, string $description) : void{
		$entry["sink"]->write(new Signal($type, $connectionId, $description));
		foreach($entry["candidates"] as $line){
			$entry["sink"]->write(new Signal(Signal::TYPE_CANDIDATE, $connectionId, $line));
		}
	}

	private function expireStalePending(int $now) : void{
		foreach($this->pending as $connectionId => $entry){
			if($now - $entry["createdAt"] >= $this->handshakeTimeout){
				$connectionId = (string) $connectionId;
				$this->logger->debug("Dropping stale pending negotiation $connectionId from " . $entry["address"] . ":" . $entry["port"]);
				$this->dropConnection($connectionId, "negotiation timed out", SignalErrorCode::NEGOTIATION_TIMEOUT_WAITING_FOR_ACCEPT);
			}
		}
	}

	/**
	 * Source addresses are trivially spoofed on the discovery socket, so the bookkeeping they create
	 * has to be thrown away as soon as it stops meaning anything - otherwise it is a memory leak with
	 * a remote trigger.
	 */
	private function expireRateLimits(int $now) : void{
		foreach($this->blockedAddresses as $address => $until){
			if($until <= $now){
				unset($this->blockedAddresses[$address]);
			}
		}
	}

	private function expireUnreadySessions(int $now) : void{
		foreach($this->sessions as $sessionId => $session){
			if(!$session->isOpenNotified() && $now - $session->getCreatedAt() >= $this->handshakeTimeout){
				$this->logger->debug("Dropping session $sessionId, its data channels did not open in time");
				$this->closeSession($sessionId, "data channels did not open in time");
			}
		}
	}

	private function expireIdleSessions(int $now) : void{
		foreach($this->sessions as $sessionId => $session){
			if($session->isOpenNotified() && $now - $session->getLastReceiveAt() >= self::SESSION_IDLE_TIMEOUT){
				$this->logger->debug("Dropping session $sessionId, nothing received for " . self::SESSION_IDLE_TIMEOUT . " seconds");
				$this->closeSession($sessionId, "timeout");
			}
		}
	}

	private function reportBandwidth() : void{
		$sent = 0;
		$received = 0;
		foreach($this->sessions as $session){
			[$sessionSent, $sessionReceived] = $session->collectBandwidthDelta();
			$sent += $sessionSent;
			$received += $sessionReceived;
		}
		if(($sent !== 0 || $received !== 0) && $this->listener !== null){
			$this->listener->onBandwidthUpdate($this, $sent, $received);
		}
	}

	/**
	 * Pushes each session's SCTP round trip estimate to the listener. RakNet reports pings on its own,
	 * nothing here does unless we ask, so the server would never learn a NetherNet player's ping.
	 */
	private function reportPings() : void{
		if($this->listener === null){
			return;
		}
		foreach($this->sessions as $session){
			$ping = $session->getPing();
			//hmm, -1 just means SCTP has not timed a chunk yet, nothing worth reporting
			if($ping >= 0){
				$this->listener->onPingUpdate($this, $session, $ping);
			}
		}
	}

	/**
	 * Says so when the transport did not get to run for a while.
	 *
	 * Everything here is driven from this one call: the discovery socket is read in it, the peer
	 * connections' timers fire in it, and packets from the server go out through it. A gap means
	 * none of that happened, which from the outside looks like a network that lost the packets - so
	 * it is worth being able to tell the two apart.
	 */
	private function reportStall() : void{
		$now = microtime(true);
		$previous = $this->lastTickAt;
		$this->lastTickAt = $now;
		if($previous !== null && ($now - $previous) > self::STALL_THRESHOLD){
			$this->logger->debug("The transport did not run for " . round(($now - $previous) * 1000) . "ms");
		}
	}

	public function isSelfPacing() : bool{
		//the UDP socket is non-blocking and the React loop returns immediately, so the driving loop must pace this
		return false;
	}

	public function isRunning() : bool{
		return $this->running;
	}

	public function shutdown() : void{
		$this->closeEndpoints();
		foreach($this->sessions as $session){
			$session->disconnect();
		}
		$this->sessions = [];
		foreach($this->pending as $entry){
			try{
				$entry["connection"]->close();
			}catch(\Throwable){

			}
		}
		$this->pending = [];

		//flush the teardown ticks the closes above scheduled (SCTP stream resets, DTLS close);
		//stray rejections during this are swallowed by the rejection handler set in start()
		Loop::futureTick(static function() : void{
			Loop::stop();
		});
		try{
			Loop::run();
		}catch(\Throwable $e){
			$this->logger->debug("Error while draining WebRTC loop on shutdown: " . $e->getMessage());
		}

		if($this->socket !== null){
			socket_close($this->socket);
			$this->socket = null;
		}
		$this->udpMux?->close();
		$this->udpMux = null;
		$this->certificateFiles?->delete();
		$this->certificateFiles = null;
		set_rejection_handler(null);
		$this->listener = null;
		$this->running = false;
	}

	public function getSession(int $connectionId) : ?NetherNetSession{
		return $this->sessions[$connectionId] ?? null;
	}

	public function addRawPacketFilter(string $regex) : void{
		$this->rawPacketFilters[] = $regex;
	}

	/**
	 * Only reaches anyone while the media port is shared, since that is the one socket a stranger
	 * can send arbitrary datagrams to. Query lives there that way when it is the server port.
	 */
	public function sendRaw(string $address, int $port, string $payload) : void{
		$this->udpMux?->sendUnrouted($payload, str_contains($address, ":") ? "[$address]:$port" : "$address:$port");
	}

	private function handleUnrouted(string $data, string $peer) : void{
		if($this->listener === null || $this->rawPacketFilters === []){
			return;
		}
		$colon = strrpos($peer, ":");
		if($colon === false){
			return;
		}
		//react hands IPv6 peers over as "[addr]:port", the listener wants the bare address
		$address = trim(substr($peer, 0, $colon), "[]");
		if($this->isBlocked($address)){
			return;
		}
		foreach($this->rawPacketFilters as $pattern){
			if(preg_match($pattern, $data) > 0){
				$this->listener->onRawPacketReceive($this, $address, (int) substr($peer, $colon + 1), $data);
				return;
			}
		}
	}

	public function blockAddress(string $address, int $timeout = 300) : void{
		if($address === ""){
			return;
		}
		$until = $timeout < 0 ? PHP_INT_MAX : time() + $timeout;
		if(($this->blockedAddresses[$address] ?? 0) >= $until){
			return;
		}
		$this->blockedAddresses[$address] = $until;
		$this->logger->notice("Blocked $address" . ($timeout < 0 ? " forever" : " for $timeout seconds"));

		foreach($this->pending as $connectionId => $entry){
			if($entry["address"] === $address){
				$this->dropConnection((string) $connectionId, "address blocked");
			}
		}
		foreach($this->sessions as $sessionId => $session){
			if($session->getAddress() === $address || $session->getSignallingAddress() === $address){
				$this->closeSession($sessionId, "address blocked");
			}
		}
	}

	public function unblockAddress(string $address) : void{
		unset($this->blockedAddresses[$address]);
		$this->logger->debug("Unblocked $address");
	}

	public function isBlocked(string $address) : bool{
		$until = $this->blockedAddresses[$address] ?? null;
		if($until === null){
			return false;
		}
		if($until > time()){
			return true;
		}
		unset($this->blockedAddresses[$address]);
		return false;
	}

	/**
	 * How many negotiations an address has open. A finished one is gone from here the moment its
	 * session opens, and an abandoned one when it times out.
	 */
	private function negotiationsFrom(string $address) : int{
		$count = 0;
		foreach($this->pending as $entry){
			if($entry["address"] === $address){
				$count++;
			}
		}
		return $count;
	}

	private function handleDatagram(string $buffer, string $address, int $port) : void{
		if($this->isBlocked($address)){
			return;
		}
		$result = DiscoveryCodec::unmarshal($buffer, $reason, $payload);
		if($result === null){
			$hexPrefix = bin2hex(substr($buffer, 0, 16));
			$this->logger->debug("Ignoring a datagram from $address:$port, it $reason (" . strlen($buffer) . " bytes, hex prefix $hexPrefix)");
			if($this->verboseLogging && $payload !== null){
				//it decrypted, so it is ours in everything but the part we could not read - the whole
				//of it is what says which part that is
				$this->logger->debug("The datagram decrypted to: " . bin2hex($payload));
			}
			return;
		}
		[$packet, $senderId] = $result;
		if($senderId === $this->networkId){
			return;
		}
		if(!$this->addressBook->remember($senderId, $address, $port, time())){
			$this->logger->debug("Not tracking network $senderId at $address:$port, the address book is full of live networks");
		}

		if($packet instanceof DiscoveryRequestPacket){
			//every client on the network asks for this every couple of seconds, and the answer only
			//changes when the server data does, so it is encoded once and kept
			$this->discoveryResponse ??= DiscoveryCodec::marshal(new DiscoveryResponsePacket($this->serverData->encode()), $this->networkId);
			$this->sendDatagram($this->discoveryResponse, $address, $port);
		}elseif($packet instanceof DiscoveryMessagePacket){
			//a recipient of zero addresses whoever picks the message up, which is how some clients
			//signal before they have learned the network ID they are talking to
			if($packet->recipientId !== 0 && $packet->recipientId !== $this->networkId){
				$this->logger->debug("Ignoring discovery message from $address:$port intended for network " . $packet->recipientId);
				return;
			}
			if($packet->data === "" || $packet->data === "Ping"){
				//keep-alive sent by clients scanning the network, it carries no signal
				return;
			}
			$signal = Signal::fromString($packet->data);
			if($signal === null){
				$this->logger->debug("Invalid signal from $address:$port: " . substr($packet->data, 0, 64));
				return;
			}
			$this->logger->debug("Signal " . $signal->type . " from $address:$port (connection " . $signal->connectionId . ")");
			$this->handleSignal($signal, $senderId, $address, $port);
		}
	}

	private function handleSignal(Signal $signal, int $senderNetworkId, string $address, int $port) : void{
		//an offer opens a connection, everything else acts on one that already exists - and since
		//signalling datagrams carry no authentication at all, anyone who has seen a connection ID on
		//the wire could otherwise tear down or redirect somebody else's connection
		if($signal->type !== Signal::TYPE_OFFER && !$this->signalCameFromPeer($signal->connectionId, $address, $port)){
			$this->logger->debug("Ignoring " . $signal->type . " for connection $signal->connectionId from unrelated address $address:$port");
			return;
		}

		switch($signal->type){
			case Signal::TYPE_OFFER:
				$this->handleOffer($signal, $senderNetworkId, $address, $port);
				break;
			case Signal::TYPE_ANSWER:
				$this->handleAnswer($signal);
				break;
			case Signal::TYPE_CANDIDATE:
				$this->handleCandidate($signal);
				break;
			case Signal::TYPE_ERROR:
				$code = ctype_digit($signal->data) ? SignalErrorCode::tryFrom((int) $signal->data) : null;
				$this->dropConnection($signal->connectionId, "remote error: " . ($code !== null ? $code->name : $signal->data));
				break;
		}
	}

	/**
	 * Whether a signalling datagram came from the peer the connection belongs to. Connections
	 * negotiated over the HTTP endpoint have no signalling address, so nothing on the discovery
	 * socket may speak for them.
	 */
	private function signalCameFromPeer(string $connectionId, string $address, int $port) : bool{
		$entry = $this->pending[$connectionId] ?? null;
		if($entry !== null){
			return $entry["address"] === $address && $entry["port"] === $port;
		}
		$session = $this->sessions[self::sessionIdOf($connectionId)] ?? null;
		return $session !== null && $session->getSignallingAddress() === $address && $session->getSignallingPort() === $port;
	}

	private function handleOffer(Signal $signal, int $senderNetworkId, string $address, int $port) : void{
		$this->acceptOffer($signal, $senderNetworkId, $address, $port, new DatagramSignalSink(
			fn(Signal $out, int $networkId, string $host, int $peerPort) => $this->sendSignal($out, $networkId, $host, $peerPort),
			$senderNetworkId,
			$address,
			$port
		));
	}

	/**
	 * Whether an offer that arrived over the signalling endpoint has to carry an identity assertion.
	 * A player who reaches the server through it is signed in and always presents one, unlike the
	 * clients that find the server by broadcasting on the local network.
	 */
	public function endpointRequiresIdentity() : bool{
		return $this->requireEndpointIdentity;
	}

	/**
	 * Who the signalling endpoint trusts to have issued the token in an offer. A player who reaches
	 * the server through it is signed in, so a server that authenticates its players can insist on
	 * a token the authorization service issued - one a peer signed for itself proves nothing but
	 * that it holds a key it made up.
	 */
	public function endpointTokenTrust() : TokenTrust{
		return $this->endpointTokenTrust ?? $this->tokenTrust;
	}

	/**
	 * @param bool|null       $requireIdentity overrides the policy the transport was configured with, for
	 *                                         signalling channels that hold their peers to a different one
	 * @param TokenTrust|null $trust           likewise for who the token has to have come from
	 */
	public function acceptOffer(Signal $signal, int $senderNetworkId, string $address, int $port, SignalSink $sink, ?bool $requireIdentity = null, ?TokenTrust $trust = null) : void{
		$connectionId = $signal->connectionId;
		$sessionId = self::sessionIdOf($connectionId);
		$existing = $this->pending[$connectionId] ?? null;
		if($existing !== null){
			//the peer only repeats an offer when it did not get the answer, so send that answer again
			//rather than dropping the retry on the floor
			if($existing["answer"] !== null){
				$this->logger->debug("Repeating answer for connection $connectionId, the peer re-sent its offer");
				$this->repeatDescription($connectionId, $existing, Signal::TYPE_ANSWER, $existing["answer"]);
			}
			return;
		}
		if(isset($this->sessions[$sessionId])){
			return;
		}
		if($this->isBlocked($address)){
			return;
		}
		if(count($this->pending) >= $this->maxPendingNegotiations){
			$this->logger->debug("Rejecting connection $connectionId from $address:$port: " . count($this->pending) . " negotiations already in flight");
			$sink->write(self::errorSignal($connectionId, SignalErrorCode::FAILED_TO_CREATE_PEER_CONNECTION));
			return;
		}
		if($address !== "" && $this->negotiationsFrom($address) >= $this->maxNegotiationsPerAddress){
			$this->logger->debug("Rejecting connection $connectionId from $address:$port: $address already has $this->maxNegotiationsPerAddress negotiations in flight");
			$sink->write(self::errorSignal($connectionId, SignalErrorCode::FAILED_TO_CREATE_PEER_CONNECTION));
			return;
		}

		try{
			$assertion = ClientIdentityAssertion::fromSdp($signal->data, $trust ?? $this->tokenTrust);
			$assertion?->verify($signal->data);
		}catch(IdentityException $e){
			$this->logger->info("Rejecting connection $connectionId from $address:$port: invalid identity assertion: " . $e->getMessage());
			$sink->write(self::errorSignal($connectionId, SignalErrorCode::IDENTITY_NOT_ALLOWED));
			return;
		}
		if($assertion === null){
			//worth seeing in a log: whether a client signs its offer decides whether requiring one is
			//safe to turn on, and the answer differs between joining by address and over the network
			$this->logger->debug("Connection $connectionId from $address:$port carries no identity assertion");
			if($requireIdentity ?? $this->requireIdentity){
				$this->logger->info("Rejecting connection $connectionId from $address:$port: identity assertion required but not provided");
				$sink->write(self::errorSignal($connectionId, SignalErrorCode::IDENTITY_NOT_ALLOWED));
				return;
			}
		}

		try{
			$connection = $this->createPeerConnection($connectionId);
		}catch(\Throwable $e){
			$this->logger->error("Failed to create peer connection: " . $e->getMessage());
			$sink->write(self::errorSignal($connectionId, SignalErrorCode::FAILED_TO_CREATE_PEER_CONNECTION));
			return;
		}
		$this->logger->debug("Incoming NetherNet connection $connectionId from $address:$port (network ID $senderNetworkId)");
		$this->pending[$connectionId] = [
			"connection" => $connection,
			"networkId" => $senderNetworkId,
			"address" => $address,
			"port" => $port,
			"publicKey" => $assertion?->getPublicKeyBase64(),
			"createdAt" => time(),
			"outgoing" => false,
			"sink" => $sink,
			"offer" => null,
			"answer" => null,
			"candidates" => [],
			"peerMaxMessageSize" => SessionDescription::maxMessageSize($signal->data),
			"lastSignalAt" => microtime(true)
		];

		$connection->on("datachannel", function(RTCDataChannel $channel) use ($connectionId, $sessionId, $address, $port, $connection) : void{
			$this->handleDataChannel($connection, $channel, $connectionId, $sessionId, $address, $port);
		});

		//the aggregate connection state covers both the ICE and the DTLS transports. Without this a
		//peer that goes away mid-handshake, or whose ICE later fails, would sit around until the
		//pending negotiation times out - and an established session would never be torn down at all
		$connection->on("connectionstatechange", function() use ($connection, $connectionId) : void{
			$this->handleConnectionStateChange($connection, $connectionId);
		});

		$offer = $signal->data;
		$connection->setRemoteDescription(new RTCSessionDescription($offer, "offer"))
			->then(function() use ($connection, $offer, $connectionId, $address) : void{
				$this->addOfferedCandidates($connection, $offer, $connectionId);
				$this->addInferredCandidates($connection, $offer, $connectionId, $address);
			})
			->then(fn() => $connection->createAnswer())
			->then(fn(RTCSessionDescription $answer) => $connection->setLocalDescription($answer))
			->then(function() use ($connection, $connectionId, $sink) : void{
				$this->publishDescription($connection, $connectionId, $sink, Signal::TYPE_ANSWER);
			})
			->catch(function(\Throwable $e) use ($connectionId) : void{
				$this->logger->error("NetherNet negotiation failed for connection $connectionId: " . $e->getMessage());
				$this->dropConnection($connectionId, "negotiation failed", SignalErrorCode::FAILED_TO_CREATE_ANSWER);
			});
	}

	private function addOfferedCandidates(RTCPeerConnection $connection, string $sdp, string $connectionId) : void{
		$added = 0;
		foreach(IceCandidate::parseAll($sdp) as $candidate){
			if($added >= self::MAX_REMOTE_CANDIDATES){
				$this->logger->debug("Ignoring the remaining candidates of connection $connectionId, it offered more than " . self::MAX_REMOTE_CANDIDATES);
				break;
			}
			if($this->addCandidate($connection, $candidate, $connectionId)){
				$added++;
			}
		}
	}

	/**
	 * Adds the candidates a peer would have offered if it had a way to learn its own public address.
	 * A client behind NAT that only ever gathered host candidates has none, and its checks stop at
	 * the first NAT between the two, so the only path left is the one its signalling came over.
	 */
	private function addInferredCandidates(RTCPeerConnection $connection, string $sdp, string $connectionId, string $address) : void{
		if(!$this->inferPeerCandidates || $address === ""){
			return;
		}
		foreach(CandidateInference::reflexiveFor($sdp, $address) as $candidate){
			if($this->addCandidate($connection, $candidate, $connectionId)){
				$this->logger->debug("Guessing $address:" . $candidate->port . " for connection $connectionId, its offer carries no reachable candidate");
			}
		}
	}

	private function connectionFor(string $connectionId) : ?RTCPeerConnection{
		$entry = $this->pending[$connectionId] ?? null;
		if($entry !== null){
			return $entry["connection"];
		}
		return ($this->sessions[self::sessionIdOf($connectionId)] ?? null)?->getConnection();
	}

	private function handleCandidate(Signal $signal) : void{
		$connection = $this->connectionFor($signal->connectionId);
		if($connection === null){
			return;
		}
		$candidate = IceCandidate::parse($signal->data);
		if($candidate === null){
			$this->logger->debug("Ignoring malformed ICE candidate for connection $signal->connectionId");
			return;
		}
		$this->addCandidate($connection, $candidate, $signal->connectionId);
	}

	private function addCandidate(RTCPeerConnection $connection, IceCandidate $candidate, string $connectionId) : bool{
		//a candidate names a host this server is about to send STUN checks to, and the peer that
		//offered it is not authenticated, so it may not point at the loopback or link-local ranges
		if(!$candidate->hasConnectableAddress()){
			$this->logger->debug("Ignoring candidate with unusable address " . $candidate->address . " for connection $connectionId");
			return false;
		}
		try{
			$parsed = RTCIceCandidate::parseSDP($candidate->toSdpValue());
			//a candidate signalled on its own carries no media section, but the library insists on
			//knowing which one it belongs to. NetherNet only ever negotiates the one, mid 0
			$parsed->setSdpMid(0);
			$parsed->setSdpMLineIndex(0);
			$connection->addIceCandidate($parsed);
			return true;
		}catch(\Throwable $e){
			$this->logger->debug("Ignoring invalid ICE candidate for connection $connectionId: " . $e->getMessage());
			return false;
		}
	}

	private function openSession(RTCPeerConnection $connection, string $connectionId, int $sessionId, string $address, int $port) : NetherNetSession{
		$session = $this->sessions[$sessionId] ?? null;
		if($session !== null){
			return $session;
		}

		$session = new NetherNetSession(
			$connection,
			$sessionId,
			$address,
			$port,
			function(string $payload) use ($sessionId) : void{
				$session = $this->sessions[$sessionId] ?? null;
				if($session !== null){
					$this->listener?->onPacketReceive($this, $session, $payload);
				}
			},
			function(string $reason) use ($sessionId) : void{
				$this->closeSession($sessionId, $reason);
			},
			function(int $receiptId) use ($sessionId) : void{
				$session = $this->sessions[$sessionId] ?? null;
				if($session !== null){
					$this->listener?->onPacketAck($this, $session, $receiptId);
				}
			}
		);
		$session->setAuthenticatedPublicKey($this->pending[$connectionId]["publicKey"] ?? null);
		$peerMaxMessageSize = $this->pending[$connectionId]["peerMaxMessageSize"] ?? null;
		if($peerMaxMessageSize !== null){
			$session->limitMessageSize($peerMaxMessageSize);
		}
		//an incoming connection only gets its session once the peer opens a channel, which is after
		//ICE connected, so the state change that would have picked the pair has already been and gone
		$this->useSelectedPair($connection, $session);
		$this->sessions[$sessionId] = $session;
		//an outgoing connection has its session before it has an answer, and the pending entry is
		//what routes that answer back to the right peer connection - it is dropped in handleAnswer()
		if(($this->pending[$connectionId]["outgoing"] ?? false) === false){
			unset($this->pending[$connectionId]);
		}
		return $session;
	}

	private function handleDataChannel(RTCPeerConnection $connection, RTCDataChannel $channel, string $connectionId, int $sessionId, string $address, int $port) : void{
		$session = $this->openSession($connection, $connectionId, $sessionId, $address, $port);
		$this->logger->debug("Data channel \"" . $channel->getLabel() . "\" received for connection $connectionId");
		if(!$session->bindChannel($channel)){
			$this->logger->debug("Rejecting unexpected data channel \"" . $channel->getLabel() . "\" for connection $connectionId");
			$this->dropConnection($connectionId, "unexpected data channel", SignalErrorCode::DATA_CHANNEL_CLOSED);
			return;
		}

		$notifyOpen = function() use ($sessionId) : void{
			$session = $this->sessions[$sessionId] ?? null;
			if($session !== null && $session->isReady() && $session->markOpenNotified()){
				$this->listener?->onSessionOpen($this, $session);
				$session->flushPendingPackets();
			}
		};
		//both channels have to be open before the session is usable, and either of them may already
		//be open by the time its datachannel event fires
		if($session->isReady()){
			$notifyOpen();
		}else{
			$channel->on("open", $notifyOpen);
		}
	}

	/**
	 * A connection that ends on its own says so here. One this server closed says it too, but by
	 * then the session is either already gone or on its way out with a reason of its own, and
	 * reporting this one over it would bury what actually happened.
	 */
	private function handleConnectionStateChange(RTCPeerConnection $connection, string $connectionId) : void{
		$state = $connection->getConnectionState();
		$session = $this->sessions[self::sessionIdOf($connectionId)] ?? null;
		if($state === ConnectionState::connected){
			if($session !== null){
				$this->useSelectedPair($connection, $session);
				if($this->isBlocked($session->getAddress())){
					$this->dropConnection($connectionId, "address blocked");
				}
			}
			return;
		}
		if($state !== ConnectionState::failed && $state !== ConnectionState::closed){
			return;
		}
		if($session !== null && !$session->isConnected()){
			return;
		}
		$this->dropConnection($connectionId, "peer connection " . $state->name);
	}

	/**
	 * Points the session at the address ICE actually settled on. The signalling address is only
	 * where the conversation started: an endpoint join reports the HTTP client, and a peer behind
	 * NAT may well reach us from somewhere else entirely.
	 *
	 * A relayed pair is left alone though, its address is the TURN server's and every player
	 * relayed through it would end up looking like the same one.
	 */
	private function useSelectedPair(RTCPeerConnection $connection, NetherNetSession $session) : void{
		$pairs = $connection->getSctp()?->getDtlsTransport()->getIceTransport()->getIceGatherer()->getIceConnection()->getNominated() ?? [];
		$pair = reset($pairs);
		if($pair === false || $pair->getRemoteCandidate()->getType() === CandidateType::relay){
			return;
		}
		[$address, $port] = $pair->getRemoteAddress();
		if($address === $session->getAddress() && $port === $session->getPort()){
			return;
		}
		$this->logger->debug("Session " . $session->getId() . " signalled from " . $session->getSignallingAddress() . ":" . $session->getSignallingPort() . ", media flows with $address:$port");
		$session->setRemoteAddress($address, $port);
	}

	private function closeSession(int $sessionId, string $reason) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session !== null){
			unset($this->sessions[$sessionId]);
			$this->logger->debug("Session $sessionId from " . $session->getAddress() . ":" . $session->getPort() . " closed after " . (time() - $session->getCreatedAt()) . "s: $reason");
			$session->disconnect();
			$this->listener?->onSessionClose($this, $session, $reason);
		}
	}

	private function dropConnection(string $connectionId, string $reason, ?SignalErrorCode $code = null) : void{
		$entry = $this->pending[$connectionId] ?? null;
		if($entry !== null){
			unset($this->pending[$connectionId]);
			if($code !== null){
				$entry["sink"]->write(self::errorSignal($connectionId, $code));
			}
			try{
				$entry["connection"]->close();
			}catch(\Throwable){

			}
		}
		$this->closeSession(self::sessionIdOf($connectionId), $reason);
	}

	/**
	 * The session a connection ID maps to. The ID is opaque on the wire, retail just happens to send
	 * a uint64 in decimal, so that is read as the number it is and anything else is hashed down to one.
	 */
	private static function sessionIdOf(string $connectionId) : int{
		if(ctype_digit($connectionId) && ($connectionId === "0" || $connectionId[0] !== "0")){
			try{
				return Uint64::toSignedInt($connectionId);
			}catch(\InvalidArgumentException){
				//too big for a uint64, so it gets hashed like any other opaque ID
			}
		}
		return Binary::readLong(substr(hash("sha256", $connectionId, true), 0, 8));
	}

	/**
	 * @throws TransportException
	 */
	public function dial(int $networkId) : NetherNetSession{
		$target = $this->addressBook->lookup($networkId);
		if($target === null){
			throw new TransportException("Network $networkId has not been discovered");
		}
		[$address, $port] = $target;

		return $this->startDial($networkId, $address, $port, fn() => new DatagramSignalSink(
			fn(Signal $out, int $recipient, string $host, int $peerPort) => $this->sendSignal($out, $recipient, $host, $peerPort),
			$networkId,
			$address,
			$port
		));
	}

	/**
	 * @throws TransportException
	 */
	public function dialEndpoint(string $baseUrl, ?EndpointClient $client = null) : NetherNetSession{
		$client ??= new EndpointClient($this->networkId);

		return $this->startDial($this->networkId, "", 0, fn(string $connectionId) => new CallbackSignalSink(
			function(Signal $signal) use ($client, $baseUrl, $connectionId) : void{
				if($signal->type !== Signal::TYPE_OFFER){
					//candidates already travel inside the offer, and there is nowhere to send an error
					return;
				}
				$client->offer($baseUrl, $signal->data)->then(
					function(string $answer) use ($connectionId) : void{
						$this->handleAnswer(new Signal(Signal::TYPE_ANSWER, $connectionId, $answer));
					},
					function(\Throwable $e) use ($connectionId) : void{
						$this->logger->error("Endpoint dial failed for connection $connectionId: " . $e->getMessage());
						$this->dropConnection($connectionId, "endpoint rejected the offer");
					}
				);
			},
			//an endpoint answers the request that carried the offer, so candidates have to be in it
			false
		));
	}

	/**
	 * @param \Closure(string) : SignalSink $makeSink
	 * @throws TransportException
	 */
	private function startDial(int $networkId, string $address, int $port, \Closure $makeSink) : NetherNetSession{
		if(!$this->running){
			throw new TransportException("NetherNet transport is not running");
		}

		//the dialling side owns the connection ID, and the session ID is derived from it exactly as
		//it is for an inbound connection so both directions agree on how sessions are keyed
		$connectionId = (string) random_int(0, PHP_INT_MAX);
		$sessionId = self::sessionIdOf($connectionId);

		try{
			$connection = $this->createPeerConnection($connectionId);
		}catch(\Throwable $e){
			throw new TransportException("Failed to create peer connection: " . $e->getMessage(), 0, $e);
		}

		$sink = $makeSink($connectionId);
		$this->pending[$connectionId] = [
			"connection" => $connection,
			"networkId" => $networkId,
			"address" => $address,
			"port" => $port,
			"publicKey" => null,
			"createdAt" => time(),
			"outgoing" => true,
			"sink" => $sink,
			"offer" => null,
			"answer" => null,
			"candidates" => [],
			//only known once the answer arrives
			"peerMaxMessageSize" => null,
			"lastSignalAt" => microtime(true)
		];
		$connection->on("connectionstatechange", function() use ($connection, $connectionId) : void{
			$this->handleConnectionStateChange($connection, $connectionId);
		});

		$session = $this->openSession($connection, $connectionId, $sessionId, $address, $port);
		//the remote peer never opens channels on a connection it did not dial, so both are created here
		foreach([
			new RTCDataChannelParameters(label: NetherNetSession::RELIABLE_CHANNEL, ordered: true),
			new RTCDataChannelParameters(label: NetherNetSession::UNRELIABLE_CHANNEL, maxRetransmits: 0, ordered: false)
		] as $parameters){
			$channel = $connection->createDataChannel($parameters);
			$session->bindChannel($channel);
			$channel->on("open", function() use ($sessionId) : void{
				$session = $this->sessions[$sessionId] ?? null;
				if($session !== null && $session->isReady() && $session->markOpenNotified()){
					$this->listener?->onSessionOpen($this, $session);
					$session->flushPendingPackets();
				}
			});
		}

		$connection->createOffer()
			->then(fn(RTCSessionDescription $offer) => $connection->setLocalDescription($offer))
			->then(function() use ($connection, $connectionId, $networkId, $sink) : void{
				$this->logger->debug("Offering connection $connectionId to network $networkId");
				$this->publishDescription($connection, $connectionId, $sink, Signal::TYPE_OFFER);
			})
			->catch(function(\Throwable $e) use ($connectionId) : void{
				$this->logger->error("NetherNet dial failed for connection $connectionId: " . $e->getMessage());
				$this->dropConnection($connectionId, "dial failed", SignalErrorCode::FAILED_TO_CREATE_OFFER);
			});

		return $session;
	}

	private function handleAnswer(Signal $signal) : void{
		$entry = $this->pending[$signal->connectionId] ?? null;
		if($entry === null || !$entry["outgoing"]){
			$this->logger->debug("Ignoring unsolicited answer for connection $signal->connectionId");
			return;
		}

		try{
			$assertion = ClientIdentityAssertion::fromSdp($signal->data);
			$assertion?->verify($signal->data);
		}catch(IdentityException $e){
			$this->logger->info("Rejecting answer for connection $signal->connectionId: invalid identity assertion: " . $e->getMessage());
			$this->dropConnection($signal->connectionId, "invalid server identity", SignalErrorCode::IDENTITY_NOT_ALLOWED);
			return;
		}
		if($assertion === null && $this->requireIdentity){
			$this->logger->info("Rejecting answer for connection $signal->connectionId: identity assertion required but not provided");
			$this->dropConnection($signal->connectionId, "missing server identity", SignalErrorCode::IDENTITY_NOT_ALLOWED);
			return;
		}
		$session = $this->sessions[self::sessionIdOf($signal->connectionId)] ?? null;
		$session?->setAuthenticatedPublicKey($assertion?->getPublicKeyBase64());
		$session?->limitMessageSize(SessionDescription::maxMessageSize($signal->data));

		$connection = $entry["connection"];
		$answer = $signal->data;
		//the negotiation is over; from here the session's own readiness deadline applies
		unset($this->pending[$signal->connectionId]);
		$connection->setRemoteDescription(new RTCSessionDescription($answer, "answer"))
			->then(function() use ($connection, $answer, $signal) : void{
				$this->addOfferedCandidates($connection, $answer, $signal->connectionId);
			})
			->catch(function(\Throwable $e) use ($signal) : void{
				$this->logger->error("Failed to apply answer for connection $signal->connectionId: " . $e->getMessage());
				$this->dropConnection($signal->connectionId, "invalid answer", SignalErrorCode::FAILED_TO_SET_REMOTE_DESCRIPTION);
			});
	}

	private function createPeerConnection(string $connectionId) : RTCPeerConnection{
		if($this->signallingCredentials !== null && $this->signallingCredentials->isExpired()){
			$this->logger->debug("Discarding expired NetherNet credentials");
			$this->signallingCredentials = null;
		}
		//without an explicit list the library falls back to a public STUN server, which means an
		//internet round trip per connection just to learn a reflexive candidate that a LAN peer can
		//never use. Signalling on the discovery socket is local, so host candidates are all it wants.
		$configuration = new RTCConfiguration($this->withSharedCertificate(
			["iceServers" => $this->iceServers()]
		));
		if($this->relayOnly){
			$configuration->iceSettings()->setTransportPolicy(TransportPolicyType::RELAY);
		}
		//every local address the ICE agent gathers on costs a socket per player, and a machine
		//usually has several no player can reach - container bridges, VPNs, a second segment
		$interfaces = $this->iceInterfaces !== [] ? $this->iceInterfaces : $this->boundInterface();
		if($interfaces !== []){
			$configuration->iceSettings()->setInterfaces($interfaces);
		}
		//a player's media leaves from a port of its own, and by default that is whatever the system
		//hands out, which cannot be forwarded through a firewall. A shared port or a range keeps it
		//to ports the operator has opened
		if($this->udpMux !== null){
			$configuration->iceSettings()->setUdpMux($this->udpMux);
		}elseif($this->icePortRange !== null){
			try{
				$configuration->iceSettings()->setIcePortRange($this->icePortRange[0], $this->icePortRange[1]);
			}catch(\Throwable $e){
				$this->logger->warning("Ignoring the configured NetherNet port range: " . $e->getMessage());
				$this->icePortRange = null;
			}
		}
		$connection = new RTCPeerConnection($configuration);
		//the layers below check whether they have a logger before they build a line, and building one
		//per datagram is not free, so a connection nobody is watching is given none at all. This has
		//to happen before the transports are built, they take the logger they are given at birth
		if($this->verboseLogging){
			$connection->setLogger(new WebrtcLogger($this->logger, "Connection $connectionId: "));
		}
		return $connection;
	}

	/**
	 * The configured ICE servers followed by whatever the signalling handed out, so neither side
	 * loses the other's.
	 *
	 * @return list<array{urls: string[], username?: string, credential?: string}>
	 */
	private function iceServers() : array{
		$servers = [];
		foreach([$this->credentials, $this->signallingCredentials] as $credentials){
			if($credentials !== null && !$credentials->isExpired()){
				foreach($credentials->toPeerConnectionConfiguration()["iceServers"] as $server){
					$servers[] = $server;
				}
			}
		}
		return $servers;
	}

	/**
	 * The address the transport was bound to, when that is an address rather than "everything". An
	 * operator who names one has already said which network the players are on.
	 *
	 * @return string[]
	 */
	/**
	 * Every IPv4 address on the machine, loopback too so query still answers locally. IPv6 stays
	 * out: hmm, ICE keys those by their bracketed form, so binding them here would take the port
	 * out from under the agent that asks for it later.
	 *
	 * @return string[]
	 */
	private static function localIpv4Addresses() : array{
		$addresses = [];
		foreach(net_get_interfaces() ?: [] as $interface){
			foreach($interface["unicast"] ?? [] as $unicast){
				$address = $unicast["address"] ?? null;
				if(is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false){
					$addresses[$address] = $address;
				}
			}
		}
		return array_values($addresses);
	}

	private function boundInterface() : array{
		if(self::isWildcard($this->bindAddress)){
			return [];
		}
		$interfaces = [$this->bindAddress];
		foreach($this->extraEndpointAddresses as $address){
			$colon = strrpos($address, ":");
			$host = trim($colon === false ? $address : substr($address, 0, $colon), "[]");
			if(self::isWildcard($host)){
				//yeah, one family on every address means ICE can't be narrowed down to a list anymore
				return [];
			}
			$interfaces[] = $host;
		}
		return $interfaces;
	}

	private static function isWildcard(string $address) : bool{
		return in_array($address, ["", "0.0.0.0", "::", "[::]"], true);
	}

	/**
	 * @param mixed[] $configuration
	 * @return mixed[]
	 */
	private function withSharedCertificate(array $configuration) : array{
		if($this->certificateFiles !== null){
			$configuration["certificatePath"] = $this->certificateFiles->certificatePath;
			$configuration["privateKeyPath"] = $this->certificateFiles->privateKeyPath;
		}
		return $configuration;
	}

	/**
	 * Broadcasts a discovery request so that the servers on this network answer with what they are
	 * hosting. Nothing on the server side needs this, it is what a client does to find them.
	 */
	public function broadcastDiscoveryRequest(string $address = "255.255.255.255", ?int $port = null) : void{
		$this->sendDatagram(
			DiscoveryCodec::marshal(new DiscoveryRequestPacket(), $this->networkId),
			$address,
			$port ?? self::DISCOVERY_PORT
		);
	}

	public function signalNetwork(Signal $signal, int $recipientNetworkId) : bool{
		$target = $this->addressBook->lookup($recipientNetworkId);
		if($target === null){
			return false;
		}
		[$address, $port] = $target;
		$this->sendSignal($signal, $recipientNetworkId, $address, $port);
		return true;
	}

	private static function errorSignal(string $connectionId, SignalErrorCode $code) : Signal{
		return new Signal(Signal::TYPE_ERROR, $connectionId, (string) $code->value);
	}

	private function sendSignal(Signal $signal, int $recipientNetworkId, string $address, int $port) : void{
		$datagram = DiscoveryCodec::marshal(new DiscoveryMessagePacket($recipientNetworkId, (string) $signal), $this->networkId);
		$this->sendDatagram($datagram, $address, $port);
	}

	private function sendDatagram(string $datagram, string $address, int $port) : void{
		if($this->socket !== null){
			$sent = @socket_sendto($this->socket, $datagram, strlen($datagram), 0, $address, $port);
			if($sent !== strlen($datagram)){
				//a short or failed write loses a whole signal, and the peer has no way to notice
				$this->logger->debug("Failed to send " . strlen($datagram) . " byte signalling datagram to $address:$port: " . socket_strerror(socket_last_error($this->socket)));
			}
		}
	}

	private function withIdentityAttribute(string $sdp) : string{
		$attribute = $this->identity?->createIdentityAttribute($sdp);
		if($attribute === null){
			return $sdp;
		}
		$position = strpos($sdp, "m=");
		if($position === false){
			return $sdp;
		}
		return substr($sdp, 0, $position) . "a=identity:$attribute\r\n" . substr($sdp, $position);
	}

	/**
	 * Signals the local description and the candidates that belong to it.
	 *
	 * A sink that can carry candidates on their own gets the description straight away and the
	 * candidates as they are gathered, which is what a LAN peer expects. One that cannot gets the
	 * description only once gathering has finished, since the candidates in it are the only ones the
	 * peer will ever see.
	 */
	private function publishDescription(RTCPeerConnection $connection, string $connectionId, SignalSink $sink, string $type) : void{
		$send = function() use ($connection, $connectionId, $sink, $type) : void{
			$local = $connection->getLocalDescription();
			if($local === null){
				$this->dropConnection($connectionId, "no local description", SignalErrorCode::FAILED_TO_SET_LOCAL_DESCRIPTION);
				return;
			}
			$sdp = AnswerRewriter::conform($local->getSdp());
			//a peer that gets its candidates trickled found this server on the local network, where
			//the addresses an operator advertises to the internet are not the ones that reach it
			if(!$sink->supportsTrickle()){
				$reached = $sink instanceof EndpointSignalSink ? $sink->getReachedAddress() : null;
				$sdp = !$this->advertised->isEmpty() || $reached === null
					? $this->advertised->filter($sdp, $this->logger)
					//nothing configured, so the address the player typed is the best guess at a public one
					: (new AdvertisedAddresses([$reached]))->extend($sdp, $this->logger);
			}
			//the candidates follow on their own, and sending them in the description as well would have
			//the peer check every one of them twice
			$description = $this->withIdentityAttribute($sink->supportsTrickle() ? SessionDescription::withoutCandidates($sdp) : $sdp);
			//kept so a repeated offer can be answered, and a lost offer repeated, without renegotiating
			if(isset($this->pending[$connectionId])){
				$this->pending[$connectionId][$type === Signal::TYPE_OFFER ? "offer" : "answer"] = $description;
				$this->pending[$connectionId]["lastSignalAt"] = microtime(true);
			}
			$this->logger->debug("Sending " . ($type === Signal::TYPE_OFFER ? "offer" : "answer") . " for connection $connectionId");
			$sink->write(new Signal($type, $connectionId, $description));
			if($sink->supportsTrickle()){
				$this->trickleCandidates($connection, $sdp, $connectionId, $sink);
			}
		};

		if($sink->supportsTrickle() || $connection->getIceGatheringState() === IceGatheringState::complete){
			$send();
			return;
		}

		$this->logger->debug("Holding the description of connection $connectionId until candidate gathering finishes");
		$sent = false;
		$timer = Loop::addTimer(self::GATHERING_TIMEOUT, function() use (&$sent, $send, $connectionId) : void{
			if($sent){
				return;
			}
			$sent = true;
			$this->logger->debug("Candidate gathering for connection $connectionId did not finish in time, sending what was gathered");
			$send();
		});
		$connection->on("icegatheringstatechange", function() use ($connection, &$sent, $send, $timer) : void{
			if($sent || $connection->getIceGatheringState() !== IceGatheringState::complete){
				return;
			}
			$sent = true;
			Loop::cancelTimer($timer);
			$send();
		});
	}

	/**
	 * Signals the candidates gathered so far and keeps signalling the ones that turn up later. A
	 * reflexive or relayed candidate costs a round trip to the STUN or TURN server, so it lands well
	 * after the description does.
	 */
	private function trickleCandidates(RTCPeerConnection $connection, string $sdp, string $connectionId, SignalSink $sink) : void{
		$ufrag = SessionDescription::attribute($sdp, "ice-ufrag");
		if($ufrag === null){
			$this->logger->debug("Local description for connection $connectionId has no ice-ufrag, not signalling candidates");
			return;
		}

		$signalled = [];
		$flush = function() use ($connection, $connectionId, $sink, $ufrag, &$signalled) : void{
			foreach(self::localCandidates($connection) as $networkId => $candidate){
				$line = $candidate->format($networkId, $ufrag);
				if(isset($signalled[$line])){
					continue;
				}
				$signalled[$line] = true;
				$sink->write(new Signal(Signal::TYPE_CANDIDATE, $connectionId, $line));
				if(isset($this->pending[$connectionId])){
					$this->pending[$connectionId]["candidates"][] = $line;
				}
			}
		};

		$flush();
		if($connection->getIceGatheringState() !== IceGatheringState::complete){
			$connection->on("icegatheringstatechange", $flush);
		}
	}

	/**
	 * The candidates the connection has gathered by now. The local description is a snapshot taken
	 * when it was set, so the gatherer is the only place a candidate found later shows up.
	 *
	 * @return IceCandidate[]
	 */
	private static function localCandidates(RTCPeerConnection $connection) : array{
		$gatherer = $connection->getSctp()?->getDtlsTransport()->getIceTransport()->getIceGatherer();
		if($gatherer === null){
			return [];
		}
		$candidates = [];
		foreach($gatherer->getLocalCandidates() as $candidate){
			$parsed = IceCandidate::parse("candidate:" . $candidate);
			if($parsed !== null){
				$candidates[] = $parsed;
			}
		}
		return $candidates;
	}
}
