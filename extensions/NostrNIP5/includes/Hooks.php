<?php

namespace NostrNIP5;

class Hooks {
	public static function onGetPreferences( $user, &$preferences ): void {
		$preferences['nostr-npub'] = [
			'type' => 'text',
			'section' => 'personal/info',
			'label-message' => 'nostrnip5-npub-label',
			'help-message' => 'nostrnip5-npub-help',
			'validation-callback' => [ self::class, 'validateNpub' ],
		];
	}

	public static function validateNpub( $value, $allData, $user ) {
		if ( trim( (string)$value ) === '' ) {
			return true;
		}
		return Npub::toHex( (string)$value ) !== null
			? true
			: wfMessage( 'nostrnip5-npub-invalid' )->text();
	}
}
