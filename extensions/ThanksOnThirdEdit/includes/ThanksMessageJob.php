<?php

namespace MediaWiki\Extension\ThanksOnThirdEdit;

use MediaWiki\Content\ContentHandler;
use MediaWiki\JobQueue\Job;
use MediaWiki\JobQueue\GenericParameterJob;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

/**
 * Appends the one-time "Thanks" message to a user's talk page.
 *
 * Enqueued by {@see Hooks::onPageSaveComplete} on the target wiki's queue so
 * that it runs in that wiki's context (the message is always posted to the
 * English wiki, regardless of where the triggering edit happened).
 */
class ThanksMessageJob extends Job implements GenericParameterJob {

	public function __construct( array $params ) {
		parent::__construct( 'thanksOnThirdEdit', $params );
		// Posting once per user; collapse duplicate jobs for the same user.
		$this->removeDuplicates = true;
	}

	public function getDeduplicationInfo() {
		$info = parent::getDeduplicationInfo();
		if ( isset( $info['params'] ) ) {
			$info['params'] = [ 'username' => $this->params['username'] ?? '' ];
		}
		return $info;
	}

	public function run() {
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$logger = \MediaWiki\Logger\LoggerFactory::getInstance( 'ThanksOnThirdEdit' );

		$username = $this->params['username'] ?? null;
		if ( !$username ) {
			return true;
		}

		$talkTitle = Title::makeTitle( NS_USER_TALK, $username );

		$systemUserName = $config->get( 'ThanksOnThirdEditSystemUser' );
		$poster = User::newSystemUser( $systemUserName, [ 'steal' => true ] );
		if ( !$poster ) {
			$logger->error( 'Could not obtain system user {name}', [ 'name' => $systemUserName ] );
			$this->setLastError( "Invalid system user '$systemUserName'" );
			return false;
		}

		$messageText = $this->buildMessage( $config );

		$wikiPage = $services->getWikiPageFactory()->newFromTitle( $talkTitle );
		$existing = $wikiPage->getContent();
		$existingText = $existing ? ( $existing->getWikitextForTransclusion() ?? '' ) : '';

		// Safety net beyond the user-option flag: never add the section twice.
		$heading = trim( $config->get( 'ThanksOnThirdEditHeading' ) );
		if ( $existingText !== '' && strpos( $existingText, "== $heading ==" ) !== false ) {
			return true;
		}

		$newText = $existingText === ''
			? $messageText
			: rtrim( $existingText ) . "\n\n" . $messageText;

		$content = ContentHandler::makeContent( $newText, $talkTitle );

		$status = $wikiPage->doUserEditContent(
			$content,
			$poster,
			'Welcome / thanks for your edits',
			$existingText === '' ? EDIT_NEW : EDIT_UPDATE
		);

		if ( !$status->isOK() ) {
			$logger->warning( 'Failed to post Thanks to {title}: {status}', [
				'title' => $talkTitle->getPrefixedText(),
				'status' => $status->getWikiText( false, false, 'en' ),
			] );
			$this->setLastError( 'Edit failed: ' . $status->getWikiText( false, false, 'en' ) );
			return false;
		}

		return true;
	}

	private function buildMessage( $config ): string {
		$heading = trim( $config->get( 'ThanksOnThirdEditHeading' ) );
		$body = trim( $config->get( 'ThanksOnThirdEditBody' ) );
		$signers = (array)$config->get( 'ThanksOnThirdEditSigners' );

		$sigs = [];
		foreach ( $signers as $name ) {
			$sigs[] = "[[User:$name|$name]] ([[User talk:$name|talk]])";
		}
		$signature = $this->joinSigners( $sigs );

		// Signatures are always in UTC, matching MediaWiki's ~~~~ timestamp,
		// e.g. "09:07, 14 June 2026 (UTC)".
		$timestamp = gmdate( 'H:i, j F Y' ) . ' (UTC)';

		$line = trim( $body . ' ' . $signature . ' ' . $timestamp );

		return "== $heading ==\n\n" . $line;
	}

	private function joinSigners( array $sigs ): string {
		if ( count( $sigs ) <= 1 ) {
			return implode( '', $sigs );
		}
		$last = array_pop( $sigs );
		return implode( ', ', $sigs ) . ' and ' . $last;
	}
}
