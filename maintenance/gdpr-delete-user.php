<?php
/**
 * GDPR "right to erasure" helper for Hitchwiki.
 *
 * MediaWiki cannot truly delete a user (edits, logs and the actor table
 * reference the account), so GDPR compliance is achieved by anonymising:
 * all of the user's contributions are reassigned to a single sink account
 * ("DeletedUser" by default) and the original account — username, email,
 * real name, password hash — is then removed.
 *
 * IMPORTANT — Hitchwiki specifics:
 *   - The `user` table is SHARED (lives in hitchwiki_en via $wgSharedDB), so
 *     the account itself exists once.
 *   - Contributions/attribution (`actor`, `revision`, `logging`, ...) live in
 *     EACH per-language database. Reassignment must therefore be run on every
 *     wiki the person edited, BEFORE the shared account is deleted.
 *
 * Recommended flow (see maintenance/README-gdpr-delete-user.md):
 *   1. Run with --merge-only on every language wiki (reassigns contributions).
 *   2. Run once more on en WITHOUT --merge-only to delete the shared account.
 *
 * Usage:
 *   php run.php gdpr-delete-user.php --wiki=<lang> --olduser="Name" [options]
 *
 * Options:
 *   --olduser   (required) username to erase
 *   --newuser   sink account to reassign edits to (default: DeletedUser)
 *   --merge-only  reassign contributions but do NOT delete the account
 *   --dry-run   report what would happen, change nothing
 */

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = '/var/www/html';
}
require_once "$IP/maintenance/Maintenance.php";

class GdprDeleteUser extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Anonymise a user for a GDPR erasure request: reassign their '
			. 'contributions to a sink account and delete the original account.'
		);
		$this->requireExtension( 'UserMerge' );
		$this->addOption( 'olduser', 'Username to erase', true, true );
		$this->addOption( 'newuser', 'Sink account to reassign edits to (default: DeletedUser)', false, true );
		$this->addOption( 'merge-only', 'Reassign contributions but do not delete the account' );
		$this->addOption( 'dry-run', 'Report what would happen without changing anything' );
	}

	public function execute() {
		$services = $this->getServiceContainer();
		$userFactory = $services->getUserFactory();

		$oldName = $this->getOption( 'olduser' );
		$newName = $this->getOption( 'newuser', 'DeletedUser' );
		$wiki = WikiMap::getCurrentWikiId();

		$oldUser = $userFactory->newFromName( $oldName );
		if ( !$oldUser || $oldUser->getId() === 0 ) {
			$this->fatalError( "User '$oldName' does not exist (no account in the shared user table)." );
		}

		if ( $oldUser->getName() === $newName ) {
			$this->fatalError( "Old user and sink account are the same ('$newName')." );
		}

		// Count contributions on THIS wiki so the operator knows the impact.
		$dbr = $this->getReplicaDB();
		$editCount = (int)$dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'revision' )
			->join( 'actor', null, 'actor_id = rev_actor' )
			->where( [ 'actor_user' => $oldUser->getId() ] )
			->caller( __METHOD__ )
			->fetchField();

		$this->output( "Wiki:        $wiki\n" );
		$this->output( "Old user:    {$oldUser->getName()} (id {$oldUser->getId()})\n" );
		$this->output( "Email:       " . ( $oldUser->getEmail() ?: '(none)' ) . "\n" );
		$this->output( "Real name:   " . ( $oldUser->getRealName() ?: '(none)' ) . "\n" );
		$this->output( "Revisions on this wiki: $editCount\n" );
		$this->output( "Sink account: $newName\n" );

		// User: and User_talk: pages to delete on THIS wiki.
		$userPage = Title::makeTitleSafe( NS_USER, $oldUser->getName() );
		$talkPage = Title::makeTitleSafe( NS_USER_TALK, $oldUser->getName() );
		$pages = array_filter( [ $userPage, $talkPage ], static fn ( $t ) => $t && $t->exists() );
		foreach ( $pages as $t ) {
			$this->output( "Page to delete on this wiki: {$t->getPrefixedText()}\n" );
		}

		if ( $this->hasOption( 'dry-run' ) ) {
			$pageNote = $pages
				? " and delete " . count( $pages ) . " user page(s) on $wiki"
				: " (no user pages on $wiki)";
			$action = $this->hasOption( 'merge-only' )
				? "reassign $editCount revisions on $wiki to $newName$pageNote"
				: "reassign $editCount revisions on $wiki to $newName$pageNote AND delete the shared account";
			$this->output( "[dry-run] Would $action. Nothing changed.\n" );
			return;
		}

		// Ensure the sink account exists (created once in the shared user table).
		$newUser = User::newSystemUser( $newName, [ 'create' => true ] );
		if ( !$newUser ) {
			$this->fatalError( "Could not create or load sink account '$newName'." );
		}

		// Performer recorded in the UserMerge log.
		$performer = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );

		$um = new MergeUser(
			$oldUser,
			$newUser,
			new UserMergeLogger(),
			$services->getDatabaseBlockStore(),
			MergeUser::USE_MULTI_COMMIT
		);

		$this->output( "Reassigning contributions to {$newUser->getName()} (id {$newUser->getId()})...\n" );
		$um->merge( $performer, __METHOD__ );
		$this->output( "  done.\n" );

		// Delete the User: and User_talk: pages on THIS wiki (GDPR: remove the
		// person's profile/talk content rather than moving it to DeletedUser).
		$deletePageFactory = $services->getDeletePageFactory();
		$wikiPageFactory = $services->getWikiPageFactory();
		$reason = 'GDPR erasure request';
		foreach ( $pages as $t ) {
			$wp = $wikiPageFactory->newFromTitle( $t );
			$status = $deletePageFactory->newDeletePage( $wp, $performer )
				->deleteUnsafe( $reason );
			if ( $status->isOK() ) {
				$this->output( "Deleted page: {$t->getPrefixedText()}\n" );
			} else {
				$this->output( "WARNING: could not delete {$t->getPrefixedText()}: "
					. $status->getMessage()->text() . "\n" );
			}
		}

		if ( $this->hasOption( 'merge-only' ) ) {
			$this->output( "merge-only set: account NOT deleted. "
				. "Run again on the remaining wikis, then once without --merge-only to delete.\n" );
			return;
		}

		$this->output( "Deleting account '{$oldUser->getName()}' (removes username, email, real name, password)...\n" );
		$failed = $um->delete( $performer, static function ( $key ) {
			return wfMessage( $key );
		} );
		$this->output( "  done.\n" );

		if ( $failed ) {
			$this->output( "WARNING: some user pages could not be moved:\n" );
			foreach ( $failed as $old => $new ) {
				$this->output( "  - $old\n" );
			}
		}

		$this->output( "GDPR erasure complete for '$oldName'.\n" );
	}
}

$maintClass = GdprDeleteUser::class;
require_once RUN_MAINTENANCE_IF_MAIN;
