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

namespace altay\network\nethernet\auth;

/**
 * Who a signalling channel trusts to have issued the token in an identity assertion.
 *
 * Whichever one applies, the detached signature over the DTLS fingerprints is still checked against
 * the token's 'cpk' claim, so the peer always has to hold the key its token names. The policy only
 * decides whether the claims around that key mean anything.
 */
enum TokenTrust{
	/**
	 * The token has to be one the authorization service issued, which is what a signed in client
	 * presents. A peer cannot name itself: 'xid' and 'xname' are attested, and the login chain can
	 * be held to the key the assertion carried.
	 */
	case MINECRAFT_AUTH;

	/**
	 * Any well formed token is taken as it comes, including one the peer signed for itself. The key
	 * binding still holds, but every claim built on it is whatever the peer chose to write, so this
	 * says nothing about who the peer is. It is what a client on the local network presents, and
	 * what a server running without authentication has to accept.
	 */
	case ANY;
}
