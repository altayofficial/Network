# Network

[![CI](https://github.com/altayofficial/Network/actions/workflows/ci.yml/badge.svg)](https://github.com/altayofficial/Network/actions/workflows/ci.yml)

Central network library for Altay written in PHP

## Usage
```php
use altay\network\Network;
use altay\network\raknet\RakNetTransport;
use altay\network\nethernet\NetherNetTransport;
use altay\network\nethernet\ServerData;

$network = new Network($myTransportListener);
$network->registerTransport(new RakNetTransport($logger, "0.0.0.0", 19132));
$network->registerTransport(new NetherNetTransport($logger, $networkId, new ServerData("Altay", "My World")));
$network->start();

while($running){
    $network->tick();
}

$network->shutdown();
```

To add another network system, implement `Transport` and register it on the same `Network` instance.

## Requirements
- PHP 8.1+ with `ext-sockets` and `ext-openssl`
- The NetherNet transport additionally needs `ext-gmp`. Its DTLS layer is [altayofficial/dtls](https://github.com/altayofficial/dtls), which is pure PHP - there is no `ext-ffi` requirement and no native library is loaded at runtime
