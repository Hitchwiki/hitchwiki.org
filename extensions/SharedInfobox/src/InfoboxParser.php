<?php

namespace MediaWiki\Extension\SharedInfobox;

/**
 * Just enough wikitext parsing to find an article's leading infobox call and
 * take it apart into named parameters.
 *
 * This deliberately does not go through the real parser: the point is to edit
 * wikitext in place, keeping every byte the migration does not need to touch,
 * and to read the infobox of a *foreign* wiki whose templates the local parser
 * could not expand anyway.
 */
class InfoboxParser {

	/**
	 * Find the first infobox call in a page.
	 *
	 * The infobox is not always the very first thing on the page — English
	 * articles may open with a banner such as {{Record-ride}} — so every
	 * top-level template near the top is considered, and the first one whose
	 * name is recognised wins.
	 *
	 * @param string $text Page wikitext.
	 * @param string[] $names Accepted template names, e.g. [ 'Infobox Stadt' ].
	 *   A name may contain `*` to stand for any run of characters, which is how
	 *   the English wiki's per-country variants ("Infobox Dutch Location") are
	 *   caught without having to enumerate them.
	 * @return array{name:string,params:array<string,string>,order:string[],start:int,end:int}|null
	 *   `start`/`end` are byte offsets of the `{{` and of the position just
	 *   after the closing `}}`.
	 */
	public static function parseLeading( string $text, array $names ): ?array {
		$wanted = array_map( [ self::class, 'nameMatcher' ], $names );

		foreach ( self::topLevelTemplates( $text ) as [ $start, $end ] ) {
			$inner = substr( $text, $start + 2, $end - $start - 4 );
			$parts = self::splitOnTopLevelPipes( $inner );
			$name = trim( array_shift( $parts )[0] );
			if ( !self::matchesAny( $name, $wanted ) ) {
				continue;
			}

			$params = [];
			$order = [];
			$spans = [];
			$positional = 0;
			$innerStart = $start + 2;
			foreach ( $parts as [ $part, $partOffset ] ) {
				$eq = self::topLevelEqualsPosition( $part );
				if ( $eq === null ) {
					$key = (string)++$positional;
					$rawValue = $part;
					$rawOffset = 0;
				} else {
					$key = trim( substr( $part, 0, $eq ) );
					$rawValue = substr( $part, $eq + 1 );
					$rawOffset = $eq + 1;
				}
				if ( !isset( $params[$key] ) ) {
					$order[] = $key;
				}
				$params[$key] = trim( $rawValue );
				// Byte range of the value alone, so it can be replaced in place
				// without reformatting the rest of the call.
				$lead = strlen( $rawValue ) - strlen( ltrim( $rawValue ) );
				$valueStart = $innerStart + $partOffset + $rawOffset + $lead;
				$spans[$key] = [ $valueStart, $valueStart + strlen( trim( $rawValue ) ) ];
			}

			return [
				'name' => $name,
				'params' => $params,
				'order' => $order,
				'spans' => $spans,
				'start' => $start,
				'end' => $end,
			];
		}

		return null;
	}

	/**
	 * Give parameters a value, whether or not the call already mentions them.
	 *
	 * A parameter that is already written out — typically with a placeholder
	 * such as `pop = -` — is filled in where it stands, so the author's chosen
	 * parameter order survives and no duplicate keys appear.
	 *
	 * @param string $text Page wikitext.
	 * @param array $infobox A result of parseLeading() for that same text.
	 * @param array<string,string> $values Parameter name => value.
	 * @return string
	 */
	public static function setParams( string $text, array $infobox, array $values ): string {
		$append = [];
		$inPlace = [];
		foreach ( $values as $key => $value ) {
			if ( isset( $infobox['spans'][$key] ) ) {
				$inPlace[$key] = $value;
			} else {
				$append[$key] = $value;
			}
		}

		// Later offsets first, so earlier ones stay valid as the string changes.
		uksort( $inPlace, static fn ( $a, $b ) => $infobox['spans'][$b][0] <=> $infobox['spans'][$a][0] );
		foreach ( $inPlace as $key => $value ) {
			[ $from, $to ] = $infobox['spans'][$key];
			$text = substr( $text, 0, $from ) . $value . substr( $text, $to );
		}
		if ( !$inPlace ) {
			return self::addParams( $text, $infobox, $append );
		}
		// The offsets in $infobox no longer describe $text, so re-read the call.
		$reparsed = self::parseLeading( $text, [ $infobox['name'] ] );
		return $reparsed === null ? $text : self::addParams( $text, $reparsed, $append );
	}

	/**
	 * Add parameters to an existing template call without disturbing the rest.
	 *
	 * @param string $text Page wikitext.
	 * @param array $infobox A result of parseLeading() for that same text.
	 * @param array<string,string> $additions Parameter name => value.
	 * @return string
	 */
	public static function addParams( string $text, array $infobox, array $additions ): string {
		if ( !$additions ) {
			return $text;
		}
		$call = substr( $text, $infobox['start'], $infobox['end'] - $infobox['start'] );
		// Match the call's own layout: one parameter per line if it is already
		// written that way, inline if it is a one-liner.
		$multiline = str_contains( rtrim( substr( $call, 0, -2 ) ), "\n" );

		$added = '';
		foreach ( $additions as $key => $value ) {
			$added .= $multiline ? "\n|$key = $value" : "|$key=$value";
		}
		// Anything between the last parameter and the closing braces (typically
		// the newline of a multi-line call) has to stay after the additions.
		$body = substr( $call, 0, -2 );
		$trailing = '';
		if ( preg_match( '/\s+$/', $body, $m ) ) {
			$trailing = $m[0];
			$body = substr( $body, 0, -strlen( $trailing ) );
		}

		return substr( $text, 0, $infobox['start'] )
			. $body . $added . $trailing . '}}'
			. substr( $text, $infobox['end'] );
	}

	/**
	 * Remove a template call, and the blank space it leaves behind, from a page.
	 *
	 * @param string $text Page wikitext.
	 * @param array $infobox A result of parseLeading() for that same text.
	 * @return string
	 */
	public static function removeCall( string $text, array $infobox ): string {
		$before = substr( $text, 0, $infobox['start'] );
		$after = substr( $text, $infobox['end'] );
		// The call usually sits alone on its own line(s); dropping it should not
		// leave a blank line where the article now begins.
		return rtrim( $before ) === ''
			? ltrim( $after, "\n" )
			: rtrim( $before ) . "\n" . ltrim( $after, "\n" );
	}

	/**
	 * Template names are matched the way MediaWiki matches them: underscores
	 * and spaces are the same character, and the first letter is case-blind.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function normaliseName( string $name ): string {
		$name = trim( strtr( $name, '_', ' ' ) );
		$name = preg_replace( '/\s+/', ' ', $name );
		return ucfirst( $name );
	}

	/**
	 * @param string $name A template name, possibly containing `*`.
	 * @return string A regex matching it.
	 */
	private static function nameMatcher( string $name ): string {
		$quoted = preg_quote( self::normaliseName( $name ), '/' );
		return '/^' . str_replace( '\*', '.*', $quoted ) . '$/i';
	}

	/**
	 * @param string $name
	 * @param string[] $matchers
	 * @return bool
	 */
	private static function matchesAny( string $name, array $matchers ): bool {
		$name = self::normaliseName( $name );
		foreach ( $matchers as $matcher ) {
			if ( preg_match( $matcher, $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Byte offsets of every template call that is not nested inside another.
	 *
	 * @param string $text
	 * @return array<array{0:int,1:int}>
	 */
	private static function topLevelTemplates( string $text ): array {
		$found = [];
		$offset = 0;
		while ( ( $start = strpos( $text, '{{', $offset ) ) !== false ) {
			$end = self::matchBraces( $text, $start );
			if ( $end === null ) {
				break;
			}
			$found[] = [ $start, $end ];
			$offset = $end;
		}
		return $found;
	}

	/**
	 * @param string $text
	 * @param int $start Offset of the opening `{{`.
	 * @return int|null Offset just after the matching `}}`, or null if unbalanced.
	 */
	private static function matchBraces( string $text, int $start ): ?int {
		$depth = 0;
		$length = strlen( $text );
		for ( $i = $start; $i < $length - 1; $i++ ) {
			$pair = substr( $text, $i, 2 );
			if ( $pair === '{{' ) {
				$depth++;
				$i++;
			} elseif ( $pair === '}}' ) {
				$depth--;
				$i++;
				if ( $depth === 0 ) {
					return $i + 1;
				}
			}
		}
		return null;
	}

	/**
	 * Split a template body on the pipes that separate its parameters.
	 *
	 * Pipes inside nested templates and inside piped wikilinks
	 * (`[[A44 (Deutschland)|A44]]`) are part of a value, not separators.
	 *
	 * @param string $inner Template body, without the surrounding braces.
	 * @return array<array{0:string,1:int}> Each part with its offset in $inner.
	 */
	private static function splitOnTopLevelPipes( string $inner ): array {
		$parts = [];
		$current = '';
		$currentStart = 0;
		$braces = 0;
		$brackets = 0;
		$length = strlen( $inner );
		for ( $i = 0; $i < $length; $i++ ) {
			$pair = substr( $inner, $i, 2 );
			if ( $pair === '{{' || $pair === '[[' ) {
				$pair === '{{' ? $braces++ : $brackets++;
				$current .= $pair;
				$i++;
				continue;
			}
			if ( $pair === '}}' || $pair === ']]' ) {
				$pair === '}}' ? $braces-- : $brackets--;
				$current .= $pair;
				$i++;
				continue;
			}
			if ( $inner[$i] === '|' && $braces === 0 && $brackets === 0 ) {
				$parts[] = [ $current, $currentStart ];
				$current = '';
				$currentStart = $i + 1;
				continue;
			}
			$current .= $inner[$i];
		}
		$parts[] = [ $current, $currentStart ];
		return $parts;
	}

	/**
	 * Offset of the `=` that makes a parameter named, ignoring any `=` that is
	 * part of a nested template, a link or an HTML attribute.
	 *
	 * @param string $part One element of splitOnTopLevelPipes().
	 * @return int|null
	 */
	private static function topLevelEqualsPosition( string $part ): ?int {
		$braces = 0;
		$brackets = 0;
		$inTag = false;
		$length = strlen( $part );
		for ( $i = 0; $i < $length; $i++ ) {
			$pair = substr( $part, $i, 2 );
			if ( $pair === '{{' || $pair === '[[' ) {
				$pair === '{{' ? $braces++ : $brackets++;
				$i++;
				continue;
			}
			if ( $pair === '}}' || $pair === ']]' ) {
				$pair === '}}' ? $braces-- : $brackets--;
				$i++;
				continue;
			}
			if ( $part[$i] === '<' ) {
				$inTag = true;
			} elseif ( $part[$i] === '>' ) {
				$inTag = false;
			} elseif ( $part[$i] === '=' && !$braces && !$brackets && !$inTag ) {
				return $i;
			}
		}
		return null;
	}
}
