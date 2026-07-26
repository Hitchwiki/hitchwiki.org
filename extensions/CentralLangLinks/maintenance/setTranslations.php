<?php

namespace MediaWiki\Extension\CentralLangLinks\Maintenance;

use MediaWiki\Extension\CentralLangLinks\TitleKey;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Set the central interlanguage translations for one concept.
 *
 * The concept is identified by its English page title (the ground truth).
 * Existing rows for the concept are replaced, so the script is idempotent.
 *
 * Always run against the shared wiki: --wiki=en
 *
 *   php maintenance/run.php \
 *     extensions/CentralLangLinks/maintenance/setTranslations.php \
 *     --wiki=en --concept Dresden \
 *     --link de:Dresden --link fr:Dresde --link tr:Dresden
 */
class SetTranslations extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'CentralLangLinks' );
		$this->addDescription(
			'Set the central interlanguage translations for one concept ' .
			'(English page title = concept key).'
		);
		$this->addOption( 'concept',
			'English page title identifying the concept, e.g. "Dresden".', true, true );
		$this->addOption( 'link',
			'A lang:title pair, e.g. de:Dresden or fr:Dresde. Repeatable.',
			false, true, false, true );
	}

	public function execute() {
		$concept = $this->titleKey( $this->getOption( 'concept' ) );
		$dbw = $this->getDB( DB_PRIMARY );

		// The English concept page is itself a translation entry.
		$rows = [ [ 'pt_concept' => $concept, 'pt_lang' => 'en', 'pt_title' => $concept ] ];
		foreach ( (array)$this->getOption( 'link', [] ) as $pair ) {
			$parts = explode( ':', $pair, 2 );
			if ( count( $parts ) !== 2 || $parts[0] === '' || trim( $parts[1] ) === '' ) {
				$this->fatalError( "Invalid --link value '$pair'; expected lang:Title." );
			}
			$rows[] = [
				'pt_concept' => $concept,
				'pt_lang' => $parts[0],
				'pt_title' => $this->titleKey( $parts[1] ),
			];
		}

		// Replace the whole set for this concept so re-runs are idempotent.
		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'page_translations' )
			->where( [ 'pt_concept' => $concept ] )
			->caller( __METHOD__ )
			->execute();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'page_translations' )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();

		$this->output( "Set " . count( $rows ) . " translation(s) for concept '$concept':\n" );
		foreach ( $rows as $r ) {
			$this->output( "  {$r['pt_lang']}: {$r['pt_title']}\n" );
		}
	}

	/**
	 * Normalise a page title to the exact DB key form the hook looks up.
	 */
	private function titleKey( string $title ): string {
		$key = TitleKey::normalize( $title );
		if ( $key === null ) {
			$this->fatalError( "Invalid page title '$title'." );
		}
		return $key;
	}
}

$maintClass = SetTranslations::class;
require_once RUN_MAINTENANCE_IF_MAIN;
