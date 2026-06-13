<?php
/**
 * Special:Unsubscribe — one-click newsletter unsubscribe (RFC 8058).
 *
 * The email footer / List-Unsubscribe header points here:
 *   https://hitchwiki.org/wiki/Special:Unsubscribe?u=<userId>&t=<hmac>
 *
 *   <hmac> = HMAC-SHA256( $wgUnsubscribeSecret, userId . email )
 *
 * The HMAC token is what authenticates the request, so MediaWiki's CSRF token
 * is deliberately NOT used here — that is the RFC 8058 ("List-Unsubscribe-Post:
 * List-Unsubscribe=One-Click") correct approach: the mailbox provider POSTs
 * here directly, with no session and no opportunity to fetch an edit token.
 *
 * - GET  → render a "confirm unsubscribe" form that POSTs back to this URL
 *          (for a human who clicks the footer link in their mail client).
 * - POST → verify the HMAC, flip the user's newsletter option off, return 200.
 *          A bare 200 (no HTML) is returned for the RFC 8058 one-click body;
 *          a human who submitted the confirm form gets a friendly page.
 *
 * The option lives in user_properties, which Hitchwiki shares across every
 * language wiki ($wgSharedTables), so one unsubscribe propagates everywhere.
 *
 * @file
 */

namespace MediaWiki\Extension\Unsubscribe;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\UnlistedSpecialPage;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;

class SpecialUnsubscribe extends UnlistedSpecialPage {

	private UserFactory $userFactory;
	private UserOptionsManager $userOptionsManager;

	public function __construct(
		UserFactory $userFactory,
		UserOptionsManager $userOptionsManager
	) {
		parent::__construct( 'Unsubscribe' );
		$this->userFactory = $userFactory;
		$this->userOptionsManager = $userOptionsManager;
	}

	/** @inheritDoc */
	public function execute( $par ) {
		$req = $this->getRequest();
		$out = $this->getOutput();
		$out->setRobotPolicy( 'noindex,nofollow' );
		$out->setPageTitle( $this->msg( 'unsubscribe-title' )->text() );

		$userId = $req->getInt( 'u' );
		$token = $req->getText( 't' );

		$user = $userId > 0 ? $this->userFactory->newFromId( $userId ) : null;
		if ( $user ) {
			// Load from the (shared) user table so getEmail() is populated.
			$user->load();
		}

		if ( !$user || !$user->isRegistered() || !$this->isTokenValid( $user, $token ) ) {
			$out->setStatusCode( 403 );
			$out->addWikiMsg( 'unsubscribe-invalid' );
			return;
		}

		if ( $req->wasPosted() ) {
			$this->unsubscribe( $user );

			// RFC 8058 one-click POST body is "List-Unsubscribe=One-Click".
			// Mailbox providers want a bare 2xx with no body of interest.
			if ( $req->getText( 'List-Unsubscribe' ) === 'One-Click' ) {
				$out->disable();
				$resp = $req->response();
				$resp->statusHeader( 200 );
				$resp->header( 'Content-Type: text/plain; charset=utf-8' );
				echo "OK\n";
				return;
			}

			// A human submitted the confirmation form below.
			$out->addWikiMsg( 'unsubscribe-done' );
			return;
		}

		// GET → confirmation form that POSTs back to this same signed URL.
		$out->addWikiMsg( 'unsubscribe-confirm', $user->getEmail() );

		$action = $this->getPageTitle()->getLocalURL( [ 'u' => $userId, 't' => $token ] );
		$out->addHTML( Html::rawElement(
			'form',
			[ 'method' => 'post', 'action' => $action ],
			Html::submitButton(
				$this->msg( 'unsubscribe-button' )->text(),
				[ 'class' => 'mw-ui-button mw-ui-destructive' ]
			)
		) );
	}

	/**
	 * Flip the newsletter option off. We write an explicit 0 (rather than
	 * deleting the row) so the default-on value ($wgDefaultUserOptions) does
	 * not silently re-subscribe the user.
	 */
	private function unsubscribe( User $user ): void {
		$optionName = $this->getConfig()->get( 'UnsubscribeOptionName' );
		$this->userOptionsManager->setOption( $user, $optionName, 0 );
		$this->userOptionsManager->saveOptions( $user );
	}

	private function isTokenValid( User $user, string $token ): bool {
		$secret = (string)$this->getConfig()->get( 'UnsubscribeSecret' );
		$email = (string)$user->getEmail();
		// No secret, no token, or no email → nothing we can verify against.
		if ( $secret === '' || $token === '' || $email === '' ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $user->getId() . $email, $secret );
		return hash_equals( $expected, $token );
	}

	/** @inheritDoc */
	public function doesWrites() {
		return true;
	}
}
