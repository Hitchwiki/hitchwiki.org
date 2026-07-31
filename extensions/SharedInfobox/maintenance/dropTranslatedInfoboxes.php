<?php

/**
 * Remove a translated wiki's own infoboxes, so its articles show the shared
 * English one instead.
 *
 * Run on the translated wiki:
 *
 *   php maintenance/run.php \
 *     extensions/SharedInfobox/maintenance/dropTranslatedInfoboxes.php \
 *     --wiki=de --report=/tmp/de-dropped.tsv
 *
 * An infobox is only removed once the English article has been confirmed —
 * over the same API call the reader's page view would make — to actually have
 * one to put in its place, so no article is ever left without an infobox.
 * Run mergeInfoboxes.php first: this script does not preserve values.
 *
 * Without --apply nothing is written.
 */

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Extension\SharedInfobox\InfoboxHtml;
use MediaWiki\Extension\SharedInfobox\InfoboxMapping;
use MediaWiki\Extension\SharedInfobox\InfoboxParser;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

class DropTranslatedInfoboxes extends Maintenance {

	/** @var array[] Report rows. */
	private array $rows = [];

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'SharedInfobox' );
		$this->addDescription(
			"Remove this wiki's own infoboxes from articles that have an English counterpart."
		);
		$this->addOption( 'apply', 'Actually edit the articles.' );
		$this->addOption( 'report', 'Write a TSV report to this path.', false, true );
		$this->addOption( 'page', 'Restrict to one page title on this wiki.', false, true );
		$this->addOption( 'limit', 'Stop after this many articles are changed.', false, true );
	}

	public function execute() {
		$lang = $this->getConfig()->get( 'LanguageCode' );
		if ( $lang === $this->getConfig()->get( 'SharedInfoboxSourceLanguage' ) ) {
			$this->fatalError( 'This is the wiki that owns the infoboxes; run it on a translation.' );
		}
		$mappings = InfoboxMapping::forLanguage( $lang );
		if ( !$mappings ) {
			$this->fatalError( "No infobox mapping is defined for '$lang'." );
		}

		$limit = (int)$this->getOption( 'limit', 0 );
		$stats = [ 'seen' => 0, 'dropped' => 0, 'no_english_infobox' => 0 ];

		foreach ( $this->translatedPages( $lang ) as $pageTitle => $concept ) {
			if ( $limit && $stats['dropped'] >= $limit ) {
				break;
			}
			$title = Title::makeTitleSafe( NS_MAIN, strtr( $pageTitle, '_', ' ' ) );
			if ( !$title || !$title->exists() ) {
				continue;
			}
			$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $title );
			$content = $page->getContent();
			if ( !$content instanceof WikitextContent ) {
				continue;
			}
			$text = $content->getText();
			$infobox = InfoboxParser::parseLeading( $text, array_keys( $mappings ) );
			if ( $infobox === null ) {
				continue;
			}
			$stats['seen']++;

			if ( !$this->englishHasInfobox( $concept ) ) {
				$stats['no_english_infobox']++;
				$this->rows[] = [ $title->getPrefixedText(), strtr( $concept, '_', ' ' ), 'kept',
					'the English article renders no infobox' ];
				continue;
			}

			$mapping = $this->mappingFor( $infobox['name'], $mappings );
			$newText = $this->rewrite( $text, $infobox, $mapping );
			if ( $newText === $text ) {
				continue;
			}
			$stats['dropped']++;
			$this->rows[] = [ $title->getPrefixedText(), strtr( $concept, '_', ' ' ), 'dropped',
				$infobox['name'] ];

			if ( $this->hasOption( 'apply' ) ) {
				$this->save( $page, $newText, $infobox['name'] );
			}
		}

		$this->writeReport();
		$this->output( sprintf(
			"\n%d articles carry their own infobox: %d %s, %d kept because the English article has none.\n",
			$stats['seen'],
			$stats['dropped'],
			$this->hasOption( 'apply' ) ? 'dropped' : 'would be dropped',
			$stats['no_english_infobox']
		) );
		if ( !$this->hasOption( 'apply' ) ) {
			$this->output( "Dry run — re-run with --apply to write the changes.\n" );
		}
	}

	/**
	 * @param string $lang
	 * @return array<string,string> Local DB key => English DB key.
	 */
	private function translatedPages( string $lang ): array {
		$query = $this->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->select( [ 'pt_concept', 'pt_title' ] )
			->from( 'page_translations' )
			->where( [ 'pt_lang' => $lang ] )
			->orderBy( 'pt_title' )
			->caller( __METHOD__ );

		$onePage = $this->getOption( 'page' );
		if ( $onePage !== null ) {
			$title = Title::newFromText( $onePage );
			if ( !$title ) {
				$this->fatalError( "Invalid --page title: $onePage" );
			}
			$query->andWhere( [ 'pt_title' => $title->getDBkey() ] );
		}

		$pages = [];
		foreach ( $query->fetchResultSet() as $row ) {
			$pages[$row->pt_title] = $row->pt_concept;
		}
		return $pages;
	}

	/**
	 * Ask the English wiki for exactly what a reader of this page would be
	 * shown, and check there is an infobox in it.
	 *
	 * @param string $concept English DB key.
	 * @return bool
	 */
	private function englishHasInfobox( string $concept ): bool {
		$url = wfAppendQuery( $this->getConfig()->get( 'SharedInfoboxSourceApi' ), [
			'action' => 'parse',
			'page' => strtr( $concept, '_', ' ' ),
			'prop' => 'text',
			'section' => '0',
			'redirects' => '1',
			'disablelimitreport' => '1',
			'formatversion' => '2',
			'format' => 'json',
		] );
		$response = $this->getServiceContainer()->getHttpRequestFactory()
			->get( $url, [ 'timeout' => 15 ], __METHOD__ );
		if ( $response === null ) {
			return false;
		}
		$html = json_decode( $response, true )['parse']['text'] ?? null;
		return $html !== null && InfoboxHtml::extractInfobox( $html ) !== null;
	}

	/**
	 * Take the infobox out and write back, in plain wikitext, whatever else the
	 * template was doing for the article.
	 *
	 * @param string $text
	 * @param array $infobox parseLeading() result for $text.
	 * @param array $mapping
	 * @return string
	 */
	private function rewrite( string $text, array $infobox, array $mapping ): string {
		$newText = InfoboxParser::removeCall( $text, $infobox );

		foreach ( $this->emitted( $infobox, $mapping ) as $line ) {
			if ( !$this->alreadyPresent( $newText, $line ) ) {
				$newText = str_starts_with( $line, '[[' )
					// Category links belong at the foot of the article.
					? rtrim( $newText ) . "\n\n" . $line . "\n"
					: $line . "\n" . $newText;
			}
		}

		return $newText;
	}

	/**
	 * The wikitext the removed template was quietly producing, with the call's
	 * own parameter values filled in.
	 *
	 * A line whose parameters are all empty produced nothing and is dropped.
	 *
	 * @param array $infobox parseLeading() result.
	 * @param array $mapping
	 * @return string[]
	 */
	private function emitted( array $infobox, array $mapping ): array {
		$template = $mapping['emits'] ?? null;
		if ( $template === null ) {
			return [];
		}

		$lines = [];
		foreach ( explode( "\n", $template ) as $line ) {
			$used = [];
			$filled = preg_replace_callback(
				'/%([^%]+)%/u',
				static function ( array $m ) use ( $infobox, &$used ) {
					$value = trim( $infobox['params'][$m[1]] ?? '' );
					$used[] = $value;
					return $value;
				},
				$line
			);
			if ( array_filter( $used ) ) {
				$lines[] = $filled;
			}
		}
		return $lines;
	}

	/**
	 * Whether the article already says this, so the migration does not add a
	 * second breadcrumb or a duplicate category.
	 *
	 * @param string $text
	 * @param string $line
	 * @return bool
	 */
	private function alreadyPresent( string $text, string $line ): bool {
		if ( preg_match( '/^\{\{\s*([^|}]+)/u', $line, $m ) ) {
			return (bool)preg_match(
				'/\{\{\s*' . preg_quote( trim( $m[1] ), '/' ) . '\s*[|}]/ui',
				$text
			);
		}
		return str_contains( $text, $line );
	}

	/**
	 * @param string $name
	 * @param array $mappings
	 * @return array
	 */
	private function mappingFor( string $name, array $mappings ): array {
		// An exact name wins over a wildcard entry such as "Infobox * Location".
		foreach ( [ false, true ] as $allowWildcards ) {
			foreach ( $mappings as $key => $mapping ) {
				if ( str_contains( $key, '*' ) !== $allowWildcards ) {
					continue;
				}
				if ( InfoboxParser::parseLeading( '{{' . $name . '}}', [ $key ] ) !== null ) {
					return $mapping;
				}
			}
		}
		return reset( $mappings );
	}

	/**
	 * @param \WikiPage $page
	 * @param string $newText
	 * @param string $templateName
	 */
	private function save( $page, string $newText, string $templateName ): void {
		$updater = $page->newPageUpdater( $this->editingUser() );
		$updater->setContent( SlotRecord::MAIN, new WikitextContent( $newText ) );
		$updater->saveRevision(
			CommentStoreComment::newUnsavedComment(
				"Infobox: remove {{{$templateName}}}; the article now shows the English article's "
					. 'infobox, so the facts are maintained in one place'
			),
			EDIT_UPDATE | EDIT_MINOR | EDIT_FORCE_BOT
		);
		if ( !$updater->getStatus()->isOK() ) {
			$this->error( 'Failed to save ' . $page->getTitle()->getPrefixedText() . ': '
				. $updater->getStatus()->getMessage()->text() );
		}
	}

	/**
	 * The user rows are shared with the English wiki but actors are per-wiki,
	 * so a system user that has never edited here has no local actor yet.
	 * newSystemUser() returns null in that case; the account still exists, so
	 * it can be looked up by name and the actor row created on first save.
	 *
	 * @return \MediaWiki\User\UserIdentity
	 */
	private function editingUser() {
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		if ( $user !== null ) {
			return $user;
		}
		$user = $this->getServiceContainer()->getUserFactory()
			->newFromName( 'Maintenance script' );
		if ( $user === null ) {
			$this->fatalError( 'Cannot resolve the "Maintenance script" user on this wiki.' );
		}
		return $user;
	}

	private function writeReport(): void {
		$path = $this->getOption( 'report' );
		if ( $path === null ) {
			return;
		}
		$handle = fopen( $path, 'w' );
		if ( !$handle ) {
			$this->error( "Cannot write report to $path" );
			return;
		}
		fputcsv( $handle, [ 'page', 'english_page', 'action', 'detail' ], "\t", '"', '' );
		foreach ( $this->rows as $row ) {
			fputcsv( $handle, $row, "\t", '"', '' );
		}
		fclose( $handle );
		$this->output( 'Report written to ' . $path . "\n" );
	}
}

$maintClass = DropTranslatedInfoboxes::class;
require_once RUN_MAINTENANCE_IF_MAIN;
