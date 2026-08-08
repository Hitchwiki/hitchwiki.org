<?php

namespace NostrNIP5;

use MediaWiki\MediaWikiServices;
use User;
use WebRequest;

class WellKnownHandler {
	/**
	 * @return array{status:int,body:array}
	 */
	public function getResponse( WebRequest $request ): array {
		$requestedName = strtolower( trim( (string)$request->getVal( 'name' ) ) );
		if ( !preg_match( '/^[a-z0-9._-]{1,64}$/', $requestedName ) ) {
			return self::emptyResponse();
		}

		$user = User::newFromName( str_replace( '_', ' ', $requestedName ) );
		if ( !$user || !$user->getId() ) {
			return self::emptyResponse();
		}

		$options = MediaWikiServices::getInstance()->getUserOptionsLookup();
		$npub = (string)$options->getOption( $user, 'nostr-npub' );
		$pubkey = Npub::toHex( $npub );
		if ( $pubkey === null ) {
			return self::emptyResponse();
		}

		return [
			'status' => 200,
			'body' => [ 'names' => [ $requestedName => $pubkey ] ],
		];
	}

	private static function emptyResponse(): array {
		return [ 'status' => 200, 'body' => [ 'names' => (object)[] ] ];
	}
}
