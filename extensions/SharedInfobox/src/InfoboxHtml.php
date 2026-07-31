<?php

namespace MediaWiki\Extension\SharedInfobox;

/**
 * String surgery on the HTML the source wiki hands us.
 *
 * The source wiki is asked for the rendered lead section rather than for
 * wikitext, because the infobox templates (and the templates they call in
 * turn) only exist on the English wiki — a foreign wiki has no way to expand
 * them locally. So the only thing that travels between wikis is finished HTML.
 */
class InfoboxHtml {

	/**
	 * Cut the leading infobox table out of a rendered lead section.
	 *
	 * Infoboxes may legitimately contain nested tables, so the closing tag is
	 * found by counting depth rather than by taking the first `</table>`.
	 *
	 * @param string $html Rendered HTML of the source article's lead section.
	 * @return string|null The `<table>…</table>` element, or null if there is none.
	 */
	public static function extractInfobox( string $html ): ?string {
		$openTag = '/<table\b[^>]*\bclass\s*=\s*"[^"]*\binfobox\b[^"]*"[^>]*>/i';
		if ( !preg_match( $openTag, $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$start = $m[0][1];

		$depth = 0;
		$offset = $start;
		$length = strlen( $html );
		while ( $offset < $length ) {
			if ( !preg_match( '/<(\/?)table\b/i', $html, $tag, PREG_OFFSET_CAPTURE, $offset ) ) {
				break;
			}
			$tagStart = $tag[0][1];
			if ( $tag[1][0] === '/' ) {
				$depth--;
				if ( $depth === 0 ) {
					$tagEnd = strpos( $html, '>', $tagStart );
					if ( $tagEnd === false ) {
						break;
					}
					return substr( $html, $start, $tagEnd + 1 - $start );
				}
			} else {
				$depth++;
			}
			$offset = $tagStart + strlen( $tag[0][0] );
		}

		// Unbalanced markup — better to show no infobox than a truncated one.
		return null;
	}

	/**
	 * Mark the table as foreign content so it can be styled and so screen
	 * readers and hyphenation do not treat English prose as local-language text.
	 *
	 * @param string $table The extracted `<table>…</table>`.
	 * @param string $sourceLang Language code of the wiki the table came from.
	 * @return string
	 */
	public static function annotate( string $table, string $sourceLang ): string {
		return preg_replace_callback(
			'/^<table\b([^>]*)>/i',
			static function ( array $m ) use ( $sourceLang ) {
				$attrs = $m[1];
				if ( preg_match( '/\bclass\s*=\s*"([^"]*)"/i', $attrs ) ) {
					$attrs = preg_replace(
						'/\bclass\s*=\s*"([^"]*)"/i',
						'class="$1 shared-infobox"',
						$attrs,
						1
					);
				} else {
					$attrs .= ' class="shared-infobox"';
				}
				return '<table' . $attrs . ' lang="' . htmlspecialchars( $sourceLang ) . '" dir="ltr">';
			},
			$table,
			1
		);
	}

	/**
	 * Put the infobox at the very top of the article body.
	 *
	 * It has to land *inside* the `mw-parser-output` wrapper: much of the
	 * infobox styling in MediaWiki:Common.css is scoped to that class, and a
	 * table placed before the wrapper would also stop the article text from
	 * flowing around the float.
	 *
	 * @param string $content The page content HTML passed to OutputPageBeforeHTML.
	 * @param string $infobox The annotated infobox table.
	 * @return string
	 */
	public static function insertIntoContent( string $content, string $infobox ): string {
		if ( preg_match( '/^\s*<div\b[^>]*\bmw-parser-output\b[^>]*>/', $content, $m ) ) {
			$after = strlen( $m[0] );
			return substr( $content, 0, $after ) . $infobox . substr( $content, $after );
		}
		return $infobox . $content;
	}
}
