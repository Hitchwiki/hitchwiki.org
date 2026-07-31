<?php

/**
 * Create English articles from translated wikitext, and record the pairing.
 *
 * Reads the JSONL that tools/translate_articles.py writes — one object per line
 * with source_title, en_title and wikitext — creates each English page, and
 * adds the page_translations row so the two articles are linked in both
 * directions from then on (interlanguage links, and the shared infobox).
 *
 *   php maintenance/run.php \
 *     extensions/SharedInfobox/maintenance/importTranslatedArticles.php \
 *     --wiki=en --lang=de --in=/tmp/raste.jsonl [--apply]
 *
 * Existing English pages are never overwritten: an article someone has already
 * written by hand is worth more than a translation of another wiki's.
 */

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

class ImportTranslatedArticles extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'SharedInfobox' );
		$this->addDescription( 'Create English articles from translated wikitext.' );
		$this->addOption( 'in', 'JSONL file to read.', true, true );
		$this->addOption( 'lang', 'Language the articles were translated from.', true, true );
		$this->addOption( 'apply', 'Actually create the pages.' );
		$this->addOption( 'summary', 'Edit summary.', false, true );
	}

	public function execute() {
		$lang = $this->getOption( 'lang' );
		$path = $this->getOption( 'in' );
		$handle = fopen( $path, 'r' );
		if ( !$handle ) {
			$this->fatalError( "Cannot read $path" );
		}
		$summary = $this->getOption(
			'summary',
			"Translated from the $lang article"
		);

		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		$stats = [ 'created' => 0, 'exists' => 0, 'linked' => 0, 'invalid' => 0 ];

		while ( ( $line = fgets( $handle ) ) !== false ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			$row = json_decode( $line, true );
			$title = Title::makeTitleSafe( NS_MAIN, $row['en_title'] ?? '' );
			$sourceTitle = $row['source_title'] ?? '';
			if ( !$title || $sourceTitle === '' || !isset( $row['wikitext'] ) ) {
				$stats['invalid']++;
				$this->error( 'Skipping unusable entry: ' . substr( $line, 0, 120 ) );
				continue;
			}

			if ( $title->exists() ) {
				// Still worth pairing the two articles, if they are not already.
				$stats['exists']++;
				if ( $this->recordTranslation( $title, $sourceTitle, $lang ) ) {
					$stats['linked']++;
				}
				continue;
			}

			$this->output( 'create ' . $title->getPrefixedText()
				. ' <- ' . $lang . ':' . $sourceTitle . "\n" );
			if ( !$this->hasOption( 'apply' ) ) {
				$stats['created']++;
				continue;
			}

			$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
			$updater = $page->newPageUpdater( $user );
			$updater->setContent( SlotRecord::MAIN, new WikitextContent( $row['wikitext'] ) );
			$updater->saveRevision(
				CommentStoreComment::newUnsavedComment( $summary . ' ' . $lang . ':' . $sourceTitle ),
				EDIT_NEW | EDIT_FORCE_BOT
			);
			if ( !$updater->getStatus()->isOK() ) {
				$this->error( 'Failed to create ' . $title->getPrefixedText() . ': '
					. $updater->getStatus()->getMessage()->text() );
				continue;
			}
			$stats['created']++;
			if ( $this->recordTranslation( $title, $sourceTitle, $lang ) ) {
				$stats['linked']++;
			}
		}
		fclose( $handle );

		$this->output( sprintf(
			"\n%d pages %s, %d already existed, %d translation links added, %d unusable entries.\n",
			$stats['created'],
			$this->hasOption( 'apply' ) ? 'created' : 'would be created',
			$stats['exists'],
			$stats['linked'],
			$stats['invalid']
		) );
		if ( !$this->hasOption( 'apply' ) ) {
			$this->output( "Dry run — re-run with --apply to create the pages.\n" );
		}
	}

	/**
	 * Pair the English article with its source, in the shared table that both
	 * the interlanguage links and the shared infobox read.
	 *
	 * @param Title $english
	 * @param string $sourceTitle
	 * @param string $lang
	 * @return bool Whether a row was added.
	 */
	private function recordTranslation( Title $english, string $sourceTitle, string $lang ): bool {
		if ( !$this->hasOption( 'apply' ) ) {
			return false;
		}
		$sourceKey = Title::makeTitleSafe( NS_MAIN, $sourceTitle );
		if ( !$sourceKey ) {
			return false;
		}
		$dbw = $this->getDB( DB_PRIMARY );
		$dbw->newInsertQueryBuilder()
			->insertInto( 'page_translations' )
			->ignore()
			->row( [
				'pt_concept' => $english->getDBkey(),
				'pt_lang' => $lang,
				'pt_title' => $sourceKey->getDBkey(),
			] )
			->caller( __METHOD__ )
			->execute();
		return (bool)$dbw->affectedRows();
	}
}

$maintClass = ImportTranslatedArticles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
