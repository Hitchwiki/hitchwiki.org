<?php

namespace MediaWiki\Extension\CentralLangLinks;

use MediaWiki\Title\Title;

/**
 * Normalisation of page titles into the exact DB key form stored in
 * page_translations.
 *
 * pt_concept and pt_title are varbinary, so lookups are byte-exact: the hook
 * matches pt_title against Title::getDBkey(), and a row that differs only in
 * case ("interchange" vs "Interchange") never matches and silently renders no
 * sidebar links. Every write path must therefore store the same DB key form
 * MediaWiki itself would produce — including $wgCapitalLinks first-letter
 * capitalisation, which a plain spaces-to-underscores conversion misses.
 */
class TitleKey {

	/**
	 * @param string $text A user- or CLI-supplied page title.
	 * @return string|null The DB key, or null if $text is not a valid title.
	 */
	public static function normalize( string $text ): ?string {
		$title = Title::makeTitleSafe( NS_MAIN, trim( $text ) );
		return $title ? $title->getDBkey() : null;
	}
}
