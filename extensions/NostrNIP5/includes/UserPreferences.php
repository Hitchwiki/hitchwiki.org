<?php
/**
 * User preferences for NostrNIP5
 *
 * @file
 * @ingroup Extensions
 */

namespace NostrNIP5;

use MediaWiki\Hook\GetPreferencesHook;
use User;

class UserPreferences implements GetPreferencesHook {
	/**
	 * Add npub preference field
	 *
	 * @param User $user
	 * @param array &$preferences
	 * @return bool|void
	 */
	public function onGetPreferences( $user, &$preferences ) {
		$preferences['nostr-npub'] = [
			'type' => 'text',
			'section' => 'personal/info',
			'label-message' => 'nostrnip5-npub-label',
			'help-message' => 'nostrnip5-npub-help',
			'validation-callback' => [ $this, 'validateNpub' ]
		];
	}

	/**
	 * Validate npub format
	 *
	 * @param string $value
	 * @param array $alldata
	 * @param User $user
	 * @return bool|string True on success, error message on failure
	 */
	public function validateNpub( $value, $alldata, $user ) {
		if ( empty( $value ) ) {
			return true; // Optional field
		}

		if ( Npub::toHex( (string)$value ) === null ) {
			return wfMessage( 'nostrnip5-npub-invalid' )->text();
		}

		return true;
	}
}
