<?php

namespace MediaWiki\Extension\ThanksOnThirdEdit;

use MediaWiki\Config\Config;
use MediaWiki\JobQueue\JobQueueGroupFactory;
use MediaWiki\JobQueue\JobSpecification;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\UserFactory;

/**
 * Posts a one-time "Thanks" message to a user's talk page when they reach a
 * configured edit count (their 3rd edit by default).
 *
 * Edit counts are global here because the `user` table is shared across all
 * language wikis ($wgSharedDB), so this fires on the user's Nth edit anywhere.
 */
class Hooks implements PageSaveCompleteHook {

	private UserFactory $userFactory;
	private UserOptionsManager $userOptionsManager;
	private JobQueueGroupFactory $jobQueueGroupFactory;
	private Config $config;

	public function __construct(
		UserFactory $userFactory,
		UserOptionsManager $userOptionsManager,
		JobQueueGroupFactory $jobQueueGroupFactory,
		Config $config
	) {
		$this->userFactory = $userFactory;
		$this->userOptionsManager = $userOptionsManager;
		$this->jobQueueGroupFactory = $jobQueueGroupFactory;
		$this->config = $config;
	}

	/**
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags,
		$revisionRecord, $editResult
	) {
		// Only registered humans; anonymous edits have no stable talk target.
		if ( !$user->isRegistered() ) {
			return;
		}

		$flagOption = $this->config->get( 'ThanksOnThirdEditFlagOption' );

		// Already thanked? The flag lives in the shared user_properties table,
		// so this is checked once globally across all wikis.
		if ( $this->userOptionsManager->getOption( $user, $flagOption ) ) {
			return;
		}

		$threshold = (int)$this->config->get( 'ThanksOnThirdEditThreshold' );

		// The edit-count increment for THIS save is deferred to POSTSEND
		// (UserEditTracker::incrementUserEditCount), i.e. it runs after this
		// hook. So getEditCount() here is the count *before* the current edit;
		// the user's Nth edit is detected when it equals N - 1.
		$userObj = $this->userFactory->newFromUserIdentity( $user );
		if ( (int)$userObj->getEditCount() !== $threshold - 1 ) {
			return;
		}

		// Mark as thanked first so a re-save or a near-simultaneous edit on
		// another wiki can't queue a second message.
		$this->userOptionsManager->setOption( $user, $flagOption, '1' );
		$this->userOptionsManager->saveOptions( $user );

		// The talk page and posting must happen in the target (English) wiki's
		// context, which an in-process edit from another wiki can't do, so hand
		// it off to a job on that wiki's queue.
		$targetWiki = $this->config->get( 'ThanksOnThirdEditTargetWiki' );
		$talkPage = Title::makeTitle( NS_USER_TALK, $user->getName() );
		$job = new JobSpecification(
			'thanksOnThirdEdit',
			[ 'username' => $user->getName() ],
			[],
			$talkPage
		);
		$this->jobQueueGroupFactory->makeJobQueueGroup( $targetWiki )->push( $job );
	}
}
