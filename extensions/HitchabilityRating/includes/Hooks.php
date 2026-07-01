<?php
/**
 * HitchabilityRating — resolves the <rating country='xx'/> parser tag used in
 * country infoboxes into the matching hitchability sign template.
 *
 * The rating data lives in a CSV (columns: country_code,average_rating,ride_count)
 * that is regenerated from maps.hitchwiki.org. The average_rating is an integer 1–5
 * that maps onto the sign templates in [[:Category:Templates Hitchability]]:
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
		if ( is_string( $file ) && is_readable( $file ) ) {
			$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			foreach ( $lines as $line ) {
				$cols = explode( ',', $line );
				if ( count( $cols ) < 3 ) {
					continue;
				}
				$code = strtoupper( trim( $cols[0] ) );
				// Skip the header row and anything malformed.
				if ( $code === '' || $code === 'COUNTRY_CODE' ) {
					continue;
				}
				$data[$code] = [
					'rating' => (int)round( (float)$cols[1] ),
					'rides' => (int)$cols[2],
				];
			}
		}

		self::$data = $data;
		return $data;
	}
}
