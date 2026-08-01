<?php

declare(strict_types=1);

namespace altay\network\ipc;

use altay\network\transport\AddressBlockingTransport;
use altay\network\transport\NameableTransport;
use altay\network\transport\RawPacketTransport;
use altay\network\transport\Transport;
use altay\network\transport\TunableTransport;
use pocketmine\utils\Binary;

final class MainToTransportThreadMessageReceiver{

	private const FLAG_IMMEDIATE = 0x01;
	private const FLAG_HAS_RECEIPT = 0x02;

	private bool $shutdownRequested = false;

	public function __construct(
		private InterThreadChannelReader $channel
	){}

	public function isShutdownRequested() : bool{
		return $this->shutdownRequested;
	}

	public function handle(Transport $transport) : bool{
		$message = $this->channel->read();
		if($message === null){
			return false;
		}

		$id = ord($message[0]);
		$offset = 1;
		switch($id){
			case TransportCommandId::SEND_PACKET:
				$sessionId = Binary::readLLong(substr($message, $offset, 8));
				$offset += 8;
				$flags = ord($message[$offset++]);
				$receiptId = null;
				if(($flags & self::FLAG_HAS_RECEIPT) !== 0){
					$receiptId = Binary::readLLong(substr($message, $offset, 8));
					$offset += 8;
				}
				$transport->getSession($sessionId)?->sendPacket(substr($message, $offset), ($flags & self::FLAG_IMMEDIATE) !== 0, $receiptId);
				break;
			case TransportCommandId::CLOSE_SESSION:
				$transport->getSession(Binary::readLLong(substr($message, $offset, 8)))?->disconnect();
				break;
			case TransportCommandId::SHUTDOWN:
				$this->shutdownRequested = true;
				break;
			case TransportCommandId::SET_NAME:
				if($transport instanceof NameableTransport){
					$transport->setName(substr($message, $offset));
				}
				break;
			case TransportCommandId::BLOCK_ADDRESS:
				if($transport instanceof AddressBlockingTransport){
					$transport->blockAddress(substr($message, $offset + 4), Binary::readLInt(substr($message, $offset, 4)));
				}
				break;
			case TransportCommandId::UNBLOCK_ADDRESS:
				if($transport instanceof AddressBlockingTransport){
					$transport->unblockAddress(substr($message, $offset));
				}
				break;
			case TransportCommandId::SET_PORT_CHECK:
				if($transport instanceof TunableTransport){
					$transport->setPortCheck(ord($message[$offset]) !== 0);
				}
				break;
			case TransportCommandId::SET_PACKET_LIMIT:
				if($transport instanceof TunableTransport){
					$transport->setPacketsPerTickLimit(Binary::readLInt(substr($message, $offset, 4)));
				}
				break;
			case TransportCommandId::ADD_RAW_PACKET_FILTER:
				if($transport instanceof RawPacketTransport){
					$transport->addRawPacketFilter(substr($message, $offset));
				}
				break;
			case TransportCommandId::SEND_RAW:
				$addressLength = ord($message[$offset++]);
				$address = substr($message, $offset, $addressLength);
				$offset += $addressLength;
				$port = Binary::readLShort(substr($message, $offset, 2));
				if($transport instanceof RawPacketTransport){
					$transport->sendRaw($address, $port, substr($message, $offset + 2));
				}
				break;
		}
		return true;
	}
}
