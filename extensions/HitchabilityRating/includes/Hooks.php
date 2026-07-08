<?php
/**
 * HitchabilityRating — resolves the <rating country='xx'/> parser tag used in
 * country infoboxes into the matching hitchability sign template.
 *
 * The rating data lives in a CSV exported by maps.hitchwiki.org (see the
 * $wgHitchabilityRatingDataFile config / HITCHABILITY_RATINGS_CSV env var). The CSV has
 * a header row; the columns used here are looked up by name:
 *
 *   country_code  — ISO 3166-1 alpha-2 code
 *   hitchability  — average hitchability score (float), rounded here to the nearest 0–5
 *   ride_count    — number of recorded rides backing the score
 *
 * The rounded hitchability (1–5) maps onto the sign templates in
 * [[:Category:Templates Hitchability]]:
 *
 *   5 => {{very good}}   4 => {{good}}   3 => {{average}}   2 => {{bad}}   1 => {{senseless}}
 *
 * Countries with fewer than $wgHitchabilityRatingMinRides recorded rides (or with no
 * data at all) render {{Unvalued}} instead, so ratings backed by too little evidence
 * are not shown as if they were meaningful.
 */

namespace MediaWiki\Extension\HitchabilityRating;

use MediaWiki\Config\Config;
use MediaWiki\Hook\ParserFirstCallInitHook;

class Hooks implements ParserFirstCallInitHook {

	/** Maps the integer 1–5 average rating to the sign template name. */
	private const RATING_TEMPLATES = [
		5 => 'very good',
		4 => 'good',
		3 => 'average',
		2 => 'bad',
		1 => 'senseless',
	];

	private Config $config;

	/** @var array<string,array{rating:int,rides:int}>|null Lazily-loaded CSV, keyed by upper-case country code. */
	private static ?array $data = null;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * @param \Parser $parser
	 */
	public function onParserFirstCallInit( $parser ) {
		$parser->setHook( 'rating', [ $this, 'renderRating' ] );
	}

	/**
	 * Renders <rating country='xx'/>.
	 *
	 * @param string|null $input Tag contents (unused; the tag is self-closing).
	 * @param array $args Tag attributes; expects 'country'.
	 * @param \Parser $parser
	 * @param \PPFrame $frame
	 * @return string HTML
	 */
	public function renderRating( $input, array $args, $parser, $frame ) {
		$code = strtoupper( trim( $args['country'] ?? '' ) );
		if ( $code === '' ) {
			return '';
		}

		// Resolve wiki country codes to the ISO codes used in the data (e.g. uk -> GB).
		$aliases = array_change_key_case(
			(array)$this->config->get( 'HitchabilityRatingAliases' ),
			CASE_UPPER
		);
		if ( isset( $aliases[$code] ) ) {
			$code = strtoupper( (string)$aliases[$code] );
		}

		$minRides = (int)$this->config->get( 'HitchabilityRatingMinRides' );
		$row = $this->loadData()[$code] ?? null;

		// No data, too few rides, or an out-of-range rating -> not enough to judge.
		$template = null;
		if ( $row !== null && $row['rides'] >= $minRides ) {
			$template = self::RATING_TEMPLATES[$row['rating']] ?? null;
		}
		if ( $template === null ) {
			$template = 'Unvalued';
		}

		return $parser->recursiveTagParse( '{{' . $template . '}}', $frame );
	}

	/**
	 * Loads and caches the ratings CSV, keyed by upper-case country code.
	 *
	 * @return array<string,array{rating:int,rides:int}>
	 */
	private function loadData(): array {
		if ( self::$data !== null ) {
			return self::$data;
		}

		$data = [];
		$file = $this->config->get( 'HitchabilityRatingDataFile' );
		// The CSV is an optional maps.hitchwiki.org export that may be absent (e.g. maps
		// isn't deployed on this host). is_file() also guards against the path being a
		// directory, which is what Docker leaves behind when the bind-mount source is
		// missing. In every such case we fall through to an empty dataset -> {{Unvalued}}.
		if ( is_string( $file ) && is_file( $file ) && is_readable( $file ) ) {
			$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

			// The first row is a header; resolve the columns we need by name so the
			// parser is robust to maps.hitchwiki.org adding or reordering columns.
			$header = array_map(
				static fn ( $h ) => strtolower( trim( $h ) ),
				explode( ',', array_shift( $lines ) ?? '' )
			);
			$codeCol = array_search( 'country_code', $header, true );
			$hitchCol = array_search( 'hitchability', $header, true );
			$ridesCol = array_search( 'ride_count', $header, true );

			if ( $codeCol !== false && $hitchCol !== false && $ridesCol !== false ) {
				foreach ( $lines as $line ) {
					$cols = explode( ',', $line );
					if ( !isset( $cols[$codeCol], $cols[$hitchCol], $cols[$ridesCol] ) ) {
						continue;
					}
					$code = strtoupper( trim( $cols[$codeCol] ) );
					$hitch = trim( $cols[$hitchCol] );
					// Skip rows without a code or without a numeric hitchability score.
					if ( $code === '' || !is_numeric( $hitch ) ) {
						continue;
					}
					$rating = (int)round( (float)$hitch );
					// Hitchability scores must fall on the 0–5 scale; drop bad data.
					if ( $rating < 0 || $rating > 5 ) {
						continue;
					}
					$data[$code] = [
						'rating' => $rating,
						'rides' => (int)$cols[$ridesCol],
					];
				}
			}
		}

		self::$data = $data;
		return $data;
	}
}
