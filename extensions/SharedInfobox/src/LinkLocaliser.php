<?php

namespace MediaWiki\Extension\SharedInfobox;

use MediaWiki\Title\Title;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * Repoints the links inside a borrowed infobox at the local wiki.
 *
 * The infobox is rendered on the English wiki, so every wikilink in it points
 * at an English article. Wherever page_translations knows a counterpart in the
 * language we are rendering for, the reader is better served by the local
 * article; links without a counterpart are left pointing at English, which is
 * still more useful than a red link.
 */
class LinkLocaliser {

	private IReadableDatabase $dbr;
	private string $lang;
	/** Prefix of source-wiki article URLs, e.g. "/en/". */
	private string $sourcePrefix;
	/** Local article path with the "$1" placeholder, e.g. "/de/$1". */
	private string $localPath;

	public function __construct(
		IReadableDatabase $dbr,
		string $lang,
		string $sourceArticlePath,
		string $localArticlePath
	) {
		$this->dbr = $dbr;
		$this->lang = $lang;
		$placeholder = strpos( $sourceArticlePath, '$1' );
		$this->sourcePrefix = $placeholder === false
			? $sourceArticlePath
			: substr( $sourceArticlePath, 0, $placeholder );
		$this->localPath = $localArticlePath;
	}

	/**
	 * @param string $html Infobox HTML as rendered on the source wiki.
	 * @return string The same HTML with translatable links pointing locally.
	 */
	public function localise( string $html ): string {
		$html = self::defuseRedLinks( $html );

		$anchors = [];
		if ( !preg_match_all( '/<a\b[^>]*>/i', $html, $matches ) ) {
			return $html;
		}

		// First pass: work out which source titles the infobox links to, so the
		// translations can be looked up in a single query.
		$wanted = [];
		foreach ( $matches[0] as $anchor ) {
			$key = $this->sourceTitleKey( $anchor );
			if ( $key !== null ) {
				$anchors[$anchor] = $key;
				$wanted[$key] = true;
			}
		}
		if ( !$wanted ) {
			return $html;
		}

		// Road articles are disambiguated by country ("A2 (Germany)"), and while
		// the road itself is rarely listed in page_translations, the country
		// always is — so ask about the disambiguators too.
		$lookup = $wanted;
		foreach ( array_keys( $wanted ) as $key ) {
			$country = self::disambiguator( $key );
			if ( $country !== null ) {
				$lookup[$country] = true;
			}
		}

		$translations = [];
		$res = $this->dbr->newSelectQueryBuilder()
			->select( [ 'pt_concept', 'pt_title' ] )
			->from( 'page_translations' )
			->where( [
				'pt_lang' => $this->lang,
				'pt_concept' => array_keys( $lookup ),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$translations[$row->pt_concept] = $row->pt_title;
		}
		$translations += $this->translateByDisambiguator( array_diff_key( $wanted, $translations ), $translations );
		if ( !$translations ) {
			return $html;
		}

		$replacements = [];
		foreach ( $anchors as $anchor => $key ) {
			if ( isset( $translations[$key] ) ) {
				$replacements[$anchor] = $this->rewriteAnchor( $anchor, $translations[$key] );
			}
		}
		return $replacements ? strtr( $html, $replacements ) : $html;
	}

	/**
	 * The parenthesised part of a title, which on road articles is a country.
	 *
	 * @param string $dbKey
	 * @return string|null DB key of the disambiguator, or null if there is none.
	 */
	private static function disambiguator( string $dbKey ): ?string {
		return preg_match( '/^.+_\((.+)\)$/', $dbKey, $m ) ? $m[1] : null;
	}

	/**
	 * Reconstruct local titles for pages that page_translations does not list,
	 * by translating only the country in their disambiguator, and keep the ones
	 * that turn out to exist here.
	 *
	 * @param array<string,true> $unresolved Source DB keys with no translation.
	 * @param array<string,string> $known Translations found so far.
	 * @return array<string,string> Source DB key => local DB key.
	 */
	private function translateByDisambiguator( array $unresolved, array $known ): array {
		$candidates = [];
		foreach ( array_keys( $unresolved ) as $key ) {
			$country = self::disambiguator( $key );
			if ( $country === null || !isset( $known[$country] ) ) {
				continue;
			}
			$base = substr( $key, 0, strrpos( $key, '_(' ) );
			$candidates[$key] = $base . '_(' . $known[$country] . ')';
		}
		if ( !$candidates ) {
			return [];
		}

		$existing = $this->dbr->newSelectQueryBuilder()
			->select( 'page_title' )
			->from( 'page' )
			->where( [ 'page_namespace' => NS_MAIN, 'page_title' => array_values( $candidates ) ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		return array_intersect( $candidates, $existing );
	}

	/**
	 * Turn the source wiki's red links into plain text.
	 *
	 * A red link is an invitation to write the missing article; on a borrowed
	 * infobox it would invite a reader of, say, the German wiki to go and
	 * create an English one. The label still carries the information, so only
	 * the link goes.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function defuseRedLinks( string $html ): string {
		return preg_replace(
			'/<a\b[^>]*\bclass\s*=\s*"[^"]*\bnew\b[^"]*"[^>]*>(.*?)<\/a>/is',
			'$1',
			$html
		);
	}

	/**
	 * The page_translations concept key an anchor points at, if any.
	 *
	 * @param string $anchor A complete `<a …>` opening tag.
	 * @return string|null DB key of the source article, or null if the anchor
	 *   is not a plain main-namespace link on the source wiki.
	 */
	private function sourceTitleKey( string $anchor ): ?string {
		if ( !preg_match( '/\bhref\s*=\s*"([^"]*)"/i', $anchor, $m ) ) {
			return null;
		}
		$href = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( !str_starts_with( $href, $this->sourcePrefix ) ) {
			return null;
		}
		$rest = substr( $href, strlen( $this->sourcePrefix ) );
		// Query strings and fragments mean this is not a bare article link.
		if ( $rest === '' || strcspn( $rest, '?#' ) !== strlen( $rest ) ) {
			return null;
		}
		$title = Title::newFromText( rawurldecode( $rest ) );
		if ( !$title || !$title->inNamespace( NS_MAIN ) ) {
			return null;
		}
		return $title->getDBkey();
	}

	/**
	 * @param string $anchor A complete `<a …>` opening tag.
	 * @param string $localDbKey DB key of the local counterpart.
	 * @return string
	 */
	private function rewriteAnchor( string $anchor, string $localDbKey ): string {
		$text = strtr( $localDbKey, '_', ' ' );
		$url = str_replace( '$1', wfUrlencode( $localDbKey ), $this->localPath );

		$anchor = preg_replace(
			'/\bhref\s*=\s*"[^"]*"/i',
			'href="' . htmlspecialchars( $url, ENT_QUOTES ) . '"',
			$anchor,
			1
		);
		// The tooltip names the target page, so it has to follow the href.
		return preg_replace(
			'/\btitle\s*=\s*"[^"]*"/i',
			'title="' . htmlspecialchars( $text, ENT_QUOTES ) . '"',
			$anchor,
			1
		);
	}
}
