<?php

/**
 * Move facts that only exist in a translated wiki's infobox into the English
 * infobox, so that nothing is lost when the translated infoboxes are removed
 * in favour of the shared English one.
 *
 * Run on the English wiki — that is the side being written to; the source
 * language is read over the container-internal API, because its templates and
 * its database belong to a different wiki.
 *
 *   php maintenance/run.php \
 *     extensions/SharedInfobox/maintenance/mergeInfoboxes.php \
 *     --wiki=en --lang=de --report=/tmp/de-infoboxes.tsv
 *
 * Without --apply the script only writes the report.
 */

use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Extension\SharedInfobox\InfoboxMapping;
use MediaWiki\Extension\SharedInfobox\InfoboxParser;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

class MergeInfoboxes extends Maintenance {

	/** Titles per request to the source wiki's API. */
	private const BATCH = 50;

	/** @var array<string,string> Source-wiki DB key => English DB key. */
	private array $conceptOf = [];

	/** @var array<string,array<string,mixed>> Report rows, keyed for stable output. */
	private array $rows = [];

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'SharedInfobox' );
		$this->addDescription(
			'Merge infobox facts from a translated wiki into the English infobox.'
		);
		$this->addOption( 'lang', 'Source language wiki, e.g. "de".', true, true );
		$this->addOption( 'apply', 'Actually edit the English articles.' );
		$this->addOption( 'report', 'Write a TSV report to this path.', false, true );
		$this->addOption( 'page', 'Restrict to one English page title.', false, true );
		$this->setBatchSize( self::BATCH );
	}

	public function execute() {
		$lang = $this->getOption( 'lang' );
		$mappings = InfoboxMapping::forLanguage( $lang );
		if ( !$mappings ) {
			$this->fatalError( "No infobox mapping is defined for '$lang'." );
		}

		$pairs = $this->loadTranslationPairs( $lang );
		if ( !$pairs ) {
			$this->fatalError( "No page_translations rows for '$lang'." );
		}
		$this->output( count( $pairs ) . " translated pages to inspect.\n" );

		$stats = [ 'infoboxes' => 0, 'auto' => 0, 'review' => 0, 'conflict' => 0, 'edited' => 0 ];

		foreach ( array_chunk( $pairs, self::BATCH, true ) as $chunk ) {
			foreach ( $this->fetchSourceWikitext( $lang, $chunk ) as $sourceTitle => $wikitext ) {
				$concept = $this->conceptOf[strtr( $sourceTitle, ' ', '_' )] ?? null;
				if ( $concept === null ) {
					continue;
				}
				$this->processPage( $lang, $sourceTitle, $concept, $wikitext, $mappings, $stats );
			}
		}

		$this->writeReport();
		$this->output( sprintf(
			"\n%d infoboxes examined: %d values mergeable automatically, %d need review, "
				. "%d conflicting values left alone, %d English pages edited.\n",
			$stats['infoboxes'], $stats['auto'], $stats['review'], $stats['conflict'], $stats['edited']
		) );
		if ( !$this->hasOption( 'apply' ) ) {
			$this->output( "Dry run — re-run with --apply to write the automatic merges.\n" );
		}
	}

	/**
	 * @param string $lang
	 * @return array<string,string> English DB key => source-wiki DB key.
	 */
	private function loadTranslationPairs( string $lang ): array {
		$dbr = $this->getDB( DB_REPLICA );
		$query = $dbr->newSelectQueryBuilder()
			->select( [ 'pt_concept', 'pt_title' ] )
			->from( 'page_translations' )
			->where( [ 'pt_lang' => $lang ] )
			->caller( __METHOD__ );

		$onePage = $this->getOption( 'page' );
		if ( $onePage !== null ) {
			$title = Title::newFromText( $onePage );
			if ( !$title ) {
				$this->fatalError( "Invalid --page title: $onePage" );
			}
			$query->andWhere( [ 'pt_concept' => $title->getDBkey() ] );
		}

		$pairs = [];
		foreach ( $query->fetchResultSet() as $row ) {
			$pairs[$row->pt_concept] = $row->pt_title;
			$this->conceptOf[$row->pt_title] = $row->pt_concept;
		}
		return $pairs;
	}

	/**
	 * Read current wikitext from the source wiki over its API.
	 *
	 * @param string $lang
	 * @param array<string,string> $chunk English DB key => source DB key.
	 * @return array<string,string> Source title (as the API normalised it) => wikitext.
	 */
	private function fetchSourceWikitext( string $lang, array $chunk ): array {
		$api = str_replace(
			'/' . $this->getConfig()->get( 'SharedInfoboxSourceLanguage' ) . '/',
			"/$lang/",
			$this->getConfig()->get( 'SharedInfoboxSourceApi' )
		);
		$titles = array_map(
			static fn ( string $key ) => strtr( $key, '_', ' ' ),
			array_values( $chunk )
		);
		$url = wfAppendQuery( $api, [
			'action' => 'query',
			'prop' => 'revisions',
			'rvprop' => 'content',
			'rvslots' => 'main',
			'titles' => implode( '|', $titles ),
			'formatversion' => '2',
			'format' => 'json',
		] );

		$response = $this->getServiceContainer()->getHttpRequestFactory()
			->get( $url, [ 'timeout' => 30 ], __METHOD__ );
		if ( $response === null ) {
			$this->error( "Could not read from $api" );
			return [];
		}
		$data = json_decode( $response, true );

		$out = [];
		foreach ( $data['query']['pages'] ?? [] as $page ) {
			$content = $page['revisions'][0]['slots']['main']['content'] ?? null;
			if ( $content !== null ) {
				$out[$page['title']] = $content;
			}
		}
		return $out;
	}

	/**
	 * @param string $lang
	 * @param string $sourceTitle
	 * @param string $concept English DB key.
	 * @param string $wikitext Source-wiki wikitext.
	 * @param array $mappings
	 * @param array &$stats
	 */
	private function processPage(
		string $lang,
		string $sourceTitle,
		string $concept,
		string $wikitext,
		array $mappings,
		array &$stats
	) {
		$source = InfoboxParser::parseLeading( $wikitext, array_keys( $mappings ) );
		if ( $source === null ) {
			return;
		}
		$mapping = $this->mappingFor( $source['name'], $mappings );
		$stats['infoboxes']++;

		$enTitle = Title::makeTitleSafe( NS_MAIN, strtr( $concept, '_', ' ' ) );
		if ( !$enTitle || !$enTitle->exists() ) {
			$this->addRow( $sourceTitle, $concept, '-', 'missing', 'English page does not exist', '', '' );
			return;
		}
		$page = $this->getServiceContainer()->getWikiPageFactory()->newFromTitle( $enTitle );
		$content = $page->getContent();
		if ( !$content instanceof WikitextContent ) {
			return;
		}
		$enText = $content->getText();
		$target = InfoboxParser::parseLeading( $enText, $mapping['english'] );
		if ( $target === null ) {
			$this->addRow(
				$sourceTitle, $concept, '-', 'missing',
				'English article has no ' . implode( '/', $mapping['english'] ), '', ''
			);
			return;
		}

		$additions = [];
		foreach ( $source['params'] as $key => $value ) {
			if ( self::isBlank( $value ) ) {
				continue;
			}
			$rule = self::ruleFor( $mapping, $key );
			if ( $rule['policy'] === InfoboxMapping::SKIP ) {
				continue;
			}

			$converted = self::balanceQuotes(
				$this->convert( $value, $rule['convert'] ?? null, $lang )
			);
			$existing = $rule['to'] !== null ? ( $target['params'][$rule['to']] ?? '' ) : '';

			if ( !self::isBlank( $existing ) ) {
				if ( $this->comparable( $existing ) !== $this->comparable( $converted ) ) {
					$stats['conflict']++;
					$this->addRow(
						$sourceTitle, $concept, $key, 'conflict',
						$rule['note'] ?? '', $value, $existing
					);
				}
				continue;
			}

			$unknown = $this->unknownTemplates( $converted );
			if ( $unknown ) {
				// The value leans on a template this wiki does not have, so
				// merging it would put broken markup in the English infobox.
				$stats['review']++;
				$this->addRow(
					$sourceTitle, $concept, $key, 'review',
					'uses template(s) that do not exist here: ' . implode( ', ', $unknown ),
					$value, ''
				);
				continue;
			}

			if ( $rule['policy'] === InfoboxMapping::AUTO && $rule['to'] !== null ) {
				$stats['auto']++;
				$additions[$rule['to']] = $converted;
				$this->addRow( $sourceTitle, $concept, $key, 'merge', $rule['to'], $value, $converted );
			} else {
				$stats['review']++;
				$this->addRow( $sourceTitle, $concept, $key, 'review', $rule['note'] ?? '', $value, '' );
			}
		}

		if ( $additions && $this->hasOption( 'apply' ) ) {
			$this->saveMerge( $page, $enText, $target, $additions, $lang );
			$stats['edited']++;
		}
	}

	/**
	 * What to do with one source parameter.
	 *
	 * @param array $mapping
	 * @param string $key Parameter name as written in the source article.
	 * @return array{to:?string,policy:string,convert:?string,note?:string}
	 */
	private static function ruleFor( array $mapping, string $key ): array {
		return $mapping['params'][$key] ?? [
			'to' => null,
			'policy' => InfoboxMapping::REVIEW,
			'convert' => null,
			'note' => 'unmapped parameter',
		];
	}

	/**
	 * @param string $name Template name as written in the source article.
	 * @param array $mappings
	 * @return array The matching mapping entry.
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
		// parseLeading() only returns names it was asked for, so this is unreachable.
		return reset( $mappings );
	}

	/**
	 * @param string $value
	 * @param string|null $conversion
	 * @param string $lang
	 * @return string
	 */
	private function convert( string $value, ?string $conversion, string $lang ): string {
		switch ( $conversion ) {
			case 'number':
				// German groups thousands with dots and Swiss German with
				// apostrophes; English uses commas.
				return preg_replace_callback(
					"/\\d{1,3}(?:([.'\u{2019}\u{00A0} ])\\d{3})+/u",
					static fn ( array $m ) => strtr( $m[0], [ $m[1] => ',' ] ),
					$value
				);
			case 'links':
				return $this->translateLinks( $value, $lang );
			case 'linkify':
				// Some templates take a bare page name and add the brackets
				// themselves; the English box expects a finished link.
				$value = $this->translateLinks( $value, $lang );
				return str_contains( $value, '[[' ) ? $value : '[[' . $value . ']]';
			default:
				return $value;
		}
	}

	/**
	 * Point the wikilinks inside a value at the English articles.
	 *
	 * A value such as `[[A44 (Deutschland)|A44]]` is correct on the German wiki
	 * and a red link on the English one; page_translations knows the English
	 * title. Link labels are left alone — they are what the reader sees, and
	 * for road numbers and city names they are already language-neutral.
	 *
	 * @param string $value
	 * @param string $lang
	 * @return string
	 */
	private function translateLinks( string $value, string $lang ): string {
		$sourceLang = $this->getConfig()->get( 'SharedInfoboxSourceLanguage' );
		return preg_replace_callback(
			'/\[\[([^\]\|]+)(\|[^\]]*)?\]\]/',
			function ( array $m ) use ( $sourceLang ) {
				$raw = trim( $m[1] );
				$label = isset( $m[2] ) ? ltrim( $m[2], '|' ) : $raw;
				// The German wiki links some English articles through the `en:`
				// interwiki prefix; on the English wiki that is just a page.
				$raw = preg_replace( '/^:?' . preg_quote( $sourceLang, '/' ) . ':/i', '', $raw );

				$target = Title::newFromText( $raw );
				if ( !$target || $target->isExternal() || !$target->inNamespace( NS_MAIN ) ) {
					return $m[0];
				}

				$english = $this->englishTitleFor( $target );
				if ( $english === null ) {
					// No English article and no known translation: linking would
					// only produce a red link, so keep the information as text.
					return $label;
				}

				return $english === $label ? "[[$english]]" : "[[$english|$label]]";
			},
			$value
		);
	}

	/**
	 * Drop italic markers that do not pair up.
	 *
	 * Source values are occasionally written as ''X'' with one marker missing;
	 * carried over as-is, the stray pair italicises the rest of the infobox.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function balanceQuotes( string $value ): string {
		return substr_count( $value, "''" ) % 2 === 0
			? $value
			: str_replace( "''", '', $value );
	}

	/**
	 * Templates a value calls that do not exist on this wiki.
	 *
	 * @param string $value
	 * @return string[]
	 */
	private function unknownTemplates( string $value ): array {
		if ( !preg_match_all( '/\{\{\s*([^{}|#]+?)\s*[|}]/u', $value, $matches ) ) {
			return [];
		}
		$missing = [];
		foreach ( array_unique( $matches[1] ) as $name ) {
			$title = Title::makeTitleSafe( NS_TEMPLATE, $name );
			if ( $title && !$title->exists() ) {
				$missing[] = $name;
			}
		}
		return $missing;
	}

	/**
	 * Whether a parameter carries no information.
	 *
	 * English infoboxes routinely spell "we do not know this" as `-`, which
	 * has to count as an empty slot the German value can fill rather than as
	 * an existing value that disagrees with it.
	 *
	 * @param string $value
	 * @return bool
	 */
	private static function isBlank( string $value ): bool {
		return trim( $value ) === ''
			|| (bool)preg_match( '/^[\s\-–—?.]*$/u', $value )
			|| in_array(
				mb_strtolower( trim( $value ) ),
				[
					'n/a', 'na', 'none', 'unknown',
					'keine', 'kein', 'unbekannt',   // German
					'brak',                          // Polish
					'nessuna', 'nessuno',            // Italian
					'нет', 'немає',                  // Russian, Ukrainian
					'ei ole',                        // Finnish
					'aucune', 'aucun',               // French
				],
				true
			);
	}

	/**
	 * The English article a source-wiki link should point at.
	 *
	 * @param Title $target A title as written on the source wiki.
	 * @return string|null Prefixed English title, or null if there is none.
	 */
	private function englishTitleFor( Title $target ): ?string {
		$concept = $this->conceptOf[$target->getDBkey()] ?? null;
		if ( $concept !== null ) {
			return strtr( $concept, '_', ' ' );
		}
		if ( $target->exists() ) {
			return $target->getPrefixedText();
		}
		// Road articles are disambiguated by country — "A5 (Deutschland)" on the
		// German wiki, "A5 (Germany)" here — and the country itself is a page
		// whose translation is known, so the English title can be reconstructed.
		if ( preg_match( '/^(.*) \((.+)\)$/', $target->getText(), $m ) ) {
			$country = Title::newFromText( $m[2] );
			$countryConcept = $country ? ( $this->conceptOf[$country->getDBkey()] ?? null ) : null;
			if ( $countryConcept !== null ) {
				$candidate = Title::newFromText( $m[1] . ' (' . strtr( $countryConcept, '_', ' ' ) . ')' );
				if ( $candidate && $candidate->exists() ) {
					return $candidate->getPrefixedText();
				}
			}
		}
		return null;
	}

	/**
	 * Loose comparison, so that "3.421.000" and "3,421,000" or differing
	 * whitespace do not show up as disagreements about the facts.
	 *
	 * @param string $value
	 * @return string
	 */
	private function comparable( string $value ): string {
		$value = preg_replace( '/<small>.*?<\/small>/is', '', $value );
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', '', $value );
		return mb_strtolower( $value );
	}

	/**
	 * @param \WikiPage $page
	 * @param string $enText
	 * @param array $target parseLeading() result for $enText.
	 * @param array<string,string> $additions
	 * @param string $lang
	 */
	private function saveMerge( $page, string $enText, array $target, array $additions, string $lang ): void {
		$newText = InfoboxParser::setParams( $enText, $target, $additions );
		if ( $newText === $enText ) {
			return;
		}
		$user = User::newSystemUser( 'Maintenance script', [ 'steal' => true ] );
		$updater = $page->newPageUpdater( $user );
		$updater->setContent( SlotRecord::MAIN, new WikitextContent( $newText ) );
		$summary = 'Infobox: merge ' . implode( ', ', array_keys( $additions ) )
			. " from the $lang article, before its infobox is replaced by this one";
		$updater->saveRevision(
			CommentStoreComment::newUnsavedComment( $summary ),
			EDIT_UPDATE | EDIT_MINOR | EDIT_FORCE_BOT
		);
		if ( !$updater->getStatus()->isOK() ) {
			$this->error( 'Failed to save ' . $page->getTitle()->getPrefixedText() . ': '
				. $updater->getStatus()->getMessage()->text() );
		}
	}

	private function addRow(
		string $sourceTitle,
		string $concept,
		string $param,
		string $action,
		string $note,
		string $sourceValue,
		string $englishValue
	): void {
		$this->rows[] = [
			$sourceTitle,
			strtr( $concept, '_', ' ' ),
			$param,
			$action,
			$note,
			$this->oneLine( $sourceValue ),
			$this->oneLine( $englishValue ),
		];
	}

	private function oneLine( string $value ): string {
		return trim( preg_replace( '/\s+/', ' ', strtr( $value, [ "\t" => ' ' ] ) ) );
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
		fputcsv( $handle, [ 'source_page', 'english_page', 'param', 'action', 'note', 'source_value', 'english_value' ], "\t", '"', '' );
		foreach ( $this->rows as $row ) {
			fputcsv( $handle, $row, "\t", '"', '' );
		}
		fclose( $handle );
		$this->output( 'Report written to ' . $path . "\n" );
	}
}

$maintClass = MergeInfoboxes::class;
require_once RUN_MAINTENANCE_IF_MAIN;
