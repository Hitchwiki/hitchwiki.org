<?php

/**
 * Export, as JSON, what the translation tooling needs to know from MediaWiki.
 *
 * That tooling runs outside MediaWiki, so it cannot read page_translations or
 * the infobox mappings itself; exporting them keeps one source of truth rather
 * than a second copy of the parameter tables in another language.
 *
 *   php maintenance/run.php extensions/SharedInfobox/maintenance/exportTitleMap.php \
 *       --wiki=en --lang=de --out=/tmp/de-titles.json --mapping=/tmp/de-mapping.json
 */

use MediaWiki\Extension\SharedInfobox\InfoboxMapping;
use MediaWiki\Maintenance\Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

class ExportTitleMap extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'SharedInfobox' );
		$this->addDescription(
			'Export a language wiki\'s title map, and its infobox mapping, as JSON.'
		);
		$this->addOption( 'lang', 'Language wiki, e.g. "de".', true, true );
		$this->addOption( 'out', 'Path to write the title map to.', true, true );
		$this->addOption( 'mapping', 'Path to write the infobox mapping to.', false, true );
	}

	public function execute() {
		$res = $this->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->select( [ 'pt_concept', 'pt_title' ] )
			->from( 'page_translations' )
			->where( [ 'pt_lang' => $this->getOption( 'lang' ) ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$map = [];
		$skipped = 0;
		foreach ( $res as $row ) {
			// A handful of rows point at Category: pages rather than articles.
			// Those are not usable as link targets in running text.
			if ( str_contains( $row->pt_concept, ':' ) ) {
				$skipped++;
				continue;
			}
			$map[strtr( $row->pt_title, '_', ' ' )] = strtr( $row->pt_concept, '_', ' ' );
		}

		file_put_contents(
			$this->getOption( 'out' ),
			json_encode( $map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
		);
		$this->output( count( $map ) . " titles written, $skipped non-article concepts skipped.\n" );

		$mappingPath = $this->getOption( 'mapping' );
		if ( $mappingPath !== null ) {
			$mapping = InfoboxMapping::forLanguage( $this->getOption( 'lang' ) );
			file_put_contents(
				$mappingPath,
				json_encode( $mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
			);
			$this->output( count( $mapping ) . " infobox templates written to $mappingPath.\n" );
		}
	}
}

$maintClass = ExportTitleMap::class;
require_once RUN_MAINTENANCE_IF_MAIN;
