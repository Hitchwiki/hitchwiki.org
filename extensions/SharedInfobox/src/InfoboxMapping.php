<?php

namespace MediaWiki\Extension\SharedInfobox;

/**
 * How each translated wiki's infobox parameters correspond to the English ones.
 *
 * Each parameter carries a policy, because "the same fact" is not always
 * mechanically transferable:
 *
 *  - `auto`   the value means the same thing in English once links and number
 *             formatting have been converted, so it can be merged unattended;
 *  - `review` the value is real information but is written in the source
 *             language or in a different notation, so a human has to move it;
 *  - `skip`   the parameter is local plumbing (categories, breadcrumbs, a flag
 *             filename the English template derives itself) and carries no
 *             fact that the English infobox is missing.
 *
 * `emits` is the wikitext a template quietly produces besides drawing the box —
 * categorisation and breadcrumb navigation — with `%param%` standing for one of
 * the call's parameters. It has to be written back into the article when the
 * call is removed, or the article silently falls out of its categories. Where a
 * template produces nothing (or leans on a helper template that does not exist
 * on that wiki), `emits` is absent.
 */
class InfoboxMapping {

	public const AUTO = 'auto';
	public const REVIEW = 'review';
	public const SKIP = 'skip';

	/**
	 * Local infobox template name => merge rules.
	 *
	 * `english` lists the English templates that can hold the same concept, so
	 * the English side of an article can be located whichever variant it uses.
	 *
	 * @return array<string,array{english:string[],params:array<string,array{to:?string,policy:string,convert:?string,note:?string}>}>
	 */
	public static function forLanguage( string $lang ): array {
		switch ( $lang ) {
			case 'de':
				return self::german();
			case 'fi':
				return self::finnish();
			case 'fr':
				return self::french();
			case 'it':
				return self::italian();
			case 'pl':
				return self::polish();
			case 'pt':
				return self::portuguese();
			case 'ru':
				return self::russian();
			case 'uk':
				return self::ukrainian();
			case 'zh':
				return self::chinese();
			default:
				// A wiki with no entry has not been surveyed; the migration
				// scripts refuse to run rather than guess at its parameters.
				return [];
		}
	}

	/** Every English template that can hold a settlement. */
	private const EN_LOCATION = [
		'Infobox Location',
		// Per-country variants — "Infobox Dutch Location", "Infobox Italian
		// Location" and so on — all wrap Template:Infobox Location and take
		// the same parameters.
		'Infobox * Location',
		'Infobox City',
		'Infobox Region',
		'Infobox * County',
	];

	/**
	 * @return array
	 */
	private static function german(): array {
		return [
			'Infobox Stadt' => [
				'emits' => "{{IsIn|%in%}}\n[[Kategorie:%in%]]",
				'english' => self::EN_LOCATION,
				'params' => [
					'Kennzeichen' => [ 'to' => 'plate', 'policy' => self::AUTO, 'convert' => null ],
					'Einwohner' => [ 'to' => 'pop', 'policy' => self::AUTO, 'convert' => 'number' ],
					'Autobahnen' => [ 'to' => 'motorways', 'policy' => self::AUTO, 'convert' => 'links' ],
					'Name' => [
						'to' => 'name',
						'policy' => self::REVIEW,
						'convert' => null,
						'note' => 'German display name; the English infobox wants the English one',
					],
					'Karte' => [
						'to' => 'map',
						'policy' => self::REVIEW,
						'convert' => null,
						'note' => '<map> is not rendered by any installed extension',
					],
					// Template:Infobox Location does not render hitchbase, but
					// neither does the German template, and carrying the code
					// over is the only way it survives the migration.
					'hitchbase' => [ 'to' => 'hitchbase', 'policy' => self::AUTO, 'convert' => null ],
					'in' => [
						'to' => null,
						'policy' => self::SKIP,
						'convert' => null,
						'note' => 'local breadcrumb/category only; English derives these from country/state',
					],
				],
			],
			'Infobox Raste' => [
				'emits' => "{{IsIn|%in%}}\n[[Kategorie:%in%]]",
				'english' => [ 'Infobox ServiceStation' ],
				'params' => self::serviceStationParams(),
			],
			'Infobox Landkreis' => [
				'emits' => "{{IsIn|%in%}}\n[[Kategorie:%in%]]",
				'english' => [ 'Infobox District' ],
				'params' => [
					'Kennzeichen' => [ 'to' => 'plate', 'policy' => self::AUTO, 'convert' => null ],
					'Einwohner' => [ 'to' => 'pop', 'policy' => self::AUTO, 'convert' => 'number' ],
					'Autobahnen' => [ 'to' => 'motorways', 'policy' => self::AUTO, 'convert' => 'links' ],
					'Sitz' => [ 'to' => 'seat', 'policy' => self::AUTO, 'convert' => 'links' ],
					'Name' => [
						'to' => 'name',
						'policy' => self::REVIEW,
						'convert' => null,
						'note' => 'German display name',
					],
					'Karte' => [
						'to' => 'map',
						'policy' => self::REVIEW,
						'convert' => null,
						'note' => '<map> is not rendered by any installed extension',
					],
					'in' => [
						'to' => null,
						'policy' => self::SKIP,
						'convert' => null,
						'note' => 'local breadcrumb/category only',
					],
				],
			],
			'Infobox Land' => [
				'emits' => '{{IsIn|%in%}}',
				'english' => [ 'Infobox Country' ],
				'params' => [
					'Einwohner' => [ 'to' => 'pop', 'policy' => self::AUTO, 'convert' => 'number' ],
					'Hauptstadt' => [ 'to' => 'capital', 'policy' => self::AUTO, 'convert' => 'links' ],
					'hitchbase' => [ 'to' => 'hitchbase', 'policy' => self::AUTO, 'convert' => null ],
					'avp' => [ 'to' => 'avp', 'policy' => self::AUTO, 'convert' => null ],
					'Währung' => [
						'to' => 'currency',
						'policy' => self::REVIEW,
						'convert' => null,
						'note' => 'currency names are often written in German',
					],
					'Sprache' => [
						'to' => 'language',
						'policy' => self::REVIEW,
						'convert' => null,
						'note' => 'language names are written in German',
					],
					'hitch' => [
						'to' => 'hitch',
						'policy' => self::REVIEW,
						'convert' => null,
						'note' => 'German uses {{gut}}-style templates, English uses <rating country=".."/>',
					],
					'Karte' => [
						'to' => 'map',
						'policy' => self::REVIEW,
						'convert' => null,
						'note' => '<map> is not rendered by any installed extension',
					],
					'Land' => [
						'to' => null,
						'policy' => self::SKIP,
						'convert' => null,
						'note' => 'only feeds the flag filename; English derives it from the page name',
					],
					'in' => [
						'to' => null,
						'policy' => self::SKIP,
						'convert' => null,
						'note' => 'local breadcrumb only',
					],
				],
			],
		];
	}

	/**
	 * Finland's country box. Its capital and currency are bare page names that
	 * the template wraps in brackets itself, so they need linking on the way
	 * across.
	 *
	 * @return array
	 */
	private static function finnish(): array {
		return [
			'Valtion tiedot' => [
				'english' => [ 'Infobox Country' ],
				'params' => [
					'pääkaupunki' => [ 'to' => 'capital', 'policy' => self::AUTO, 'convert' => 'linkify' ],
					'väkiluku' => [ 'to' => 'pop', 'policy' => self::AUTO, 'convert' => 'number' ],
					'hitchbase' => [ 'to' => 'hitchbase', 'policy' => self::AUTO, 'convert' => null ],
					'viralliset-kielet' => [ 'to' => 'language', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'language names are written in Finnish' ],
					'valuutta' => [ 'to' => 'currency', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'currency names are written in Finnish' ],
					'liftattavuus' => [ 'to' => 'hitch', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'Finnish rating templates, English uses <rating country=".."/>' ],
					'kartta' => [ 'to' => 'map', 'policy' => self::REVIEW, 'convert' => null, 'note' => '<map> is not rendered by any installed extension' ],
					'nimi' => [ 'to' => null, 'policy' => self::REVIEW, 'convert' => null, 'note' => 'the country name in Finnish; English derives it from the page name' ],
					'country' => [ 'to' => null, 'policy' => self::SKIP, 'convert' => null, 'note' => 'only feeds the flag filename' ],
				],
			],
		];
	}

	/**
	 * @return array
	 */
	private static function french(): array {
		return [
			'Infobox Pays' => [
				'english' => [ 'Infobox Country' ],
				'params' => self::englishParams() + [
					'capitale' => [ 'to' => 'capital', 'policy' => self::AUTO, 'convert' => 'links' ],
					'pop' => [ 'to' => 'pop', 'policy' => self::AUTO, 'convert' => 'number' ],
					'paved' => [ 'to' => 'paved', 'policy' => self::AUTO, 'convert' => null ],
					'hitchbase' => [ 'to' => 'hitchbase', 'policy' => self::AUTO, 'convert' => null ],
					'avp' => [ 'to' => 'avp', 'policy' => self::AUTO, 'convert' => null ],
					'langue' => [ 'to' => 'language', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'language names are written in French' ],
					'devise' => [ 'to' => 'currency', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'currency names are written in French' ],
					'pouce' => [ 'to' => 'hitch', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'French rating templates, English uses <rating country=".."/>' ],
					'carte' => [ 'to' => 'map', 'policy' => self::REVIEW, 'convert' => null, 'note' => '<map> is not rendered by any installed extension' ],
					'pays' => [ 'to' => null, 'policy' => self::SKIP, 'convert' => null, 'note' => 'only feeds the flag filename' ],
				],
			],
		];
	}

	/**
	 * @return array
	 */
	private static function russian(): array {
		return [
			'Инфо-врезка Страна' => [
				'english' => [ 'Infobox Country' ],
				'params' => [
					'столица' => [ 'to' => 'capital', 'policy' => self::AUTO, 'convert' => 'links' ],
					'население' => [ 'to' => 'pop', 'policy' => self::AUTO, 'convert' => 'number' ],
					'язык' => [ 'to' => 'language', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'language names are written in Russian' ],
					'валюта' => [ 'to' => 'currency', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'currency names are written in Russian' ],
					'стоп-рейтинг' => [ 'to' => 'hitch', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'Russian rating scale' ],
					'карта' => [ 'to' => null, 'policy' => self::REVIEW, 'convert' => null, 'note' => 'a bare image filename, not the <map> tag the English box expects' ],
					'страна' => [ 'to' => null, 'policy' => self::SKIP, 'convert' => null, 'note' => 'only feeds the flag alt text' ],
					'flag-fn' => [ 'to' => null, 'policy' => self::SKIP, 'convert' => null, 'note' => 'only feeds the flag filename' ],
				],
			],
			// Copied from the English wiki, parameter names and all. The
			// template itself was never created here, so the article shows no
			// infobox at present.
			'Infobox Romanian Location' => [
				'english' => self::EN_LOCATION,
				'params' => self::englishParams(),
			],
		];
	}

	/**
	 * @return array
	 */
	private static function polish(): array {
		$city = [
					'ludnosc' => [ 'to' => 'pop', 'policy' => self::AUTO, 'convert' => 'number' ],
					'tablice rejestracyjne' => [ 'to' => 'plate', 'policy' => self::AUTO, 'convert' => null ],
					'autostrady' => [ 'to' => 'motorways', 'policy' => self::AUTO, 'convert' => 'links' ],
					'mapa' => [ 'to' => 'map', 'policy' => self::REVIEW, 'convert' => null, 'note' => '<map> is not rendered by any installed extension' ],
					'drogi ekspresowe' => [ 'to' => null, 'policy' => self::REVIEW, 'convert' => null, 'note' => 'expressways; the English box has no separate parameter for them' ],
					'footnotes' => [ 'to' => null, 'policy' => self::REVIEW, 'convert' => null, 'note' => 'free text, written in Polish' ],
		];
		return [
			'Infobox Państwo' => [
				'emits' => '{{JestW|%w%}}',
				'english' => [ 'Infobox Country' ],
				'params' => [
					'stolica' => [ 'to' => 'capital', 'policy' => self::AUTO, 'convert' => 'links' ],
					'ludnosc' => [ 'to' => 'pop', 'policy' => self::AUTO, 'convert' => 'number' ],
					'jezyk' => [ 'to' => 'language', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'language names are written in Polish' ],
					'waluta' => [ 'to' => 'currency', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'currency names are written in Polish' ],
					'mozliwosc_stopowania' => [ 'to' => 'hitch', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'Polish rating templates' ],
					'mapa' => [ 'to' => 'map', 'policy' => self::REVIEW, 'convert' => null, 'note' => '<map> is not rendered by any installed extension' ],
					'panstwo' => [ 'to' => null, 'policy' => self::SKIP, 'convert' => null, 'note' => 'only feeds the flag filename' ],
					'country' => [ 'to' => null, 'policy' => self::SKIP, 'convert' => null, 'note' => 'only feeds the flag filename' ],
					'w' => [ 'to' => null, 'policy' => self::SKIP, 'convert' => null, 'note' => 'local breadcrumb only' ],
				],
			],
			'Infobox Polskie Miasto' => [
				'emits' => "{{JestW|%wojewodztwo%}}\n[[Kategoria:%wojewodztwo%]]",
				'english' => self::EN_LOCATION,
				'params' => self::englishParams() + $city + [
					'wojewodztwo' => [ 'to' => 'state', 'policy' => self::REVIEW, 'convert' => null,
						'note' => 'voivodeship name written in Polish' ],
				],
			],
			'Infobox Niemieckie Miasto' => [
				'emits' => "{{JestW|%kraj związkowy%}}\n[[Kategoria:%kraj związkowy%]]",
				'english' => self::EN_LOCATION,
				'params' => self::englishParams() + $city + [
					'kraj związkowy' => [ 'to' => 'state', 'policy' => self::AUTO, 'convert' => 'links' ],
					'state' => [ 'to' => 'state', 'policy' => self::AUTO, 'convert' => 'links' ],
					'panstwo' => [ 'to' => null, 'policy' => self::SKIP, 'convert' => null,
						'note' => 'only feeds the flag filename' ],
				],
			],
		];
	}

	/**
	 * Ukraine's settlement box is a translation of Template:Infobox Location,
	 * so the parameters line up one for one once their names are read back.
	 *
	 * Its categorisation calls a template that was never created on this wiki,
	 * so the only thing it really emits is the breadcrumb.
	 *
	 * @return array
	 */
	private static function ukrainian(): array {
		return [
			'Місто' => [
				'emits' => '{{IsIn|%країна%}}',
				'english' => self::EN_LOCATION,
				'params' => self::englishParams() + [
					'країна' => [ 'to' => 'country', 'policy' => self::REVIEW, 'convert' => null,
						'note' => 'country name written in Ukrainian' ],
					'штат' => [ 'to' => 'state', 'policy' => self::REVIEW, 'convert' => null,
						'note' => 'region name written in Ukrainian' ],
					'регіон' => [ 'to' => 'region', 'policy' => self::AUTO, 'convert' => 'links' ],
					'населення' => [ 'to' => 'pop', 'policy' => self::AUTO, 'convert' => 'number' ],
					'номерні_знаки' => [ 'to' => 'plate', 'policy' => self::AUTO, 'convert' => null ],
					'дороги' => [ 'to' => 'roads', 'policy' => self::AUTO, 'convert' => 'links' ],
					'motorways' => [ 'to' => 'motorways', 'policy' => self::AUTO, 'convert' => 'links' ],
					'symbol' => [ 'to' => 'symbol', 'policy' => self::AUTO, 'convert' => null ],
					'герб_штату' => [ 'to' => 'state_symbol', 'policy' => self::AUTO, 'convert' => null ],
					'subdivision_symbol' => [ 'to' => 'subdivision_symbol', 'policy' => self::AUTO, 'convert' => null ],
					'subdivision2_symbol' => [ 'to' => 'subdivision2_symbol', 'policy' => self::AUTO, 'convert' => null ],
					'регіон_symbol' => [ 'to' => 'region_symbol', 'policy' => self::AUTO, 'convert' => null ],
					'subdivision_type' => [ 'to' => 'subdivision_type', 'policy' => self::AUTO, 'convert' => null ],
					'subdivision_назва' => [ 'to' => 'subdivision_name', 'policy' => self::AUTO, 'convert' => 'links' ],
					'тип_регіону' => [ 'to' => 'subdivision2_type', 'policy' => self::AUTO, 'convert' => null ],
					'назва_регіону' => [ 'to' => 'subdivision2_name', 'policy' => self::AUTO, 'convert' => 'links' ],
					'назва' => [ 'to' => 'name', 'policy' => self::REVIEW, 'convert' => null, 'note' => 'the display name in Ukrainian' ],
					'місцева_назва' => [ 'to' => 'name_native', 'policy' => self::AUTO, 'convert' => null ],
					'мапа' => [ 'to' => 'map', 'policy' => self::REVIEW, 'convert' => null, 'note' => '<map> is not rendered by any installed extension' ],
				],
			],
		];
	}

	/**
	 * Italy's templates carry English names but Italian parameters, and only
	 * Infobox Country was actually created here — the location variants are red
	 * links, so those articles show no infobox at all and can only gain one.
	 *
	 * @return array
	 */
	private static function italian(): array {
		$location = [
			'popolazione' => [ 'to' => 'pop', 'policy' => self::AUTO, 'convert' => 'number' ],
			'targa' => [ 'to' => 'plate', 'policy' => self::AUTO, 'convert' => null ],
			'autostrada' => [ 'to' => 'motorways', 'policy' => self::AUTO, 'convert' => 'links' ],
			'paese' => [ 'to' => 'country', 'policy' => self::REVIEW, 'convert' => null,
				'note' => 'country name written in Italian' ],
			'mappa' => [ 'to' => 'map', 'policy' => self::REVIEW, 'convert' => null,
				'note' => '<map> is not rendered by any installed extension' ],
		];
		return [
			'Infobox Country' => [
				'emits' => '{{IsIn|%in%}}',
				'english' => [ 'Infobox Country' ],
				'params' => self::englishParams() + [
					'capitale' => [ 'to' => 'capital', 'policy' => self::AUTO, 'convert' => 'links' ],
					'abitanti' => [ 'to' => 'pop', 'policy' => self::AUTO, 'convert' => 'number' ],
					'hitchbase' => [ 'to' => 'hitchbase', 'policy' => self::AUTO, 'convert' => null ],
					'avp' => [ 'to' => 'avp', 'policy' => self::AUTO, 'convert' => null ],
					'BW' => [ 'to' => 'BW', 'policy' => self::AUTO, 'convert' => null ],
					'lingua' => [ 'to' => 'language', 'policy' => self::REVIEW, 'convert' => null,
						'note' => 'language names are written in Italian' ],
					'moneta' => [ 'to' => 'currency', 'policy' => self::REVIEW, 'convert' => null,
						'note' => 'currency names are written in Italian' ],
					'autostoppabilità' => [ 'to' => 'hitch', 'policy' => self::REVIEW, 'convert' => null,
						'note' => 'Italian rating templates' ],
					'hitch' => [ 'to' => 'hitch', 'policy' => self::REVIEW, 'convert' => null,
						'note' => 'Italian rating templates' ],
					'asfaltate' => [ 'to' => 'paved', 'policy' => self::REVIEW, 'convert' => null,
						'note' => 'free text, written in Italian' ],
					'links' => [ 'to' => 'links', 'policy' => self::REVIEW, 'convert' => null,
						'note' => 'free text, written in Italian' ],
					'mappa' => [ 'to' => 'map', 'policy' => self::REVIEW, 'convert' => null,
						'note' => '<map> is not rendered by any installed extension' ],
					'map' => [ 'to' => 'map', 'policy' => self::REVIEW, 'convert' => null,
						'note' => '<map> is not rendered by any installed extension' ],
					'Paese' => [ 'to' => null, 'policy' => self::SKIP, 'convert' => null,
						'note' => 'only feeds the flag filename' ],
					'in' => [ 'to' => null, 'policy' => self::SKIP, 'convert' => null,
						'note' => 'local breadcrumb only' ],
				],
			],
			'Infobox Location' => [
				'english' => self::EN_LOCATION,
				'params' => $location + self::englishParams(),
			],
			'Infobox * Location' => [
				'english' => self::EN_LOCATION,
				'params' => $location + self::englishParams(),
			],
		];
	}

	/**
	 * @return array
	 */
	private static function portuguese(): array {
		$emits = "{{Category|%country%|%state%|%subdivision_name%|%region%|%subdivision2_name%}}\n"
			. '{{IsIn|%country%}}';
		$params = [
			'pop' => [ 'to' => 'pop', 'policy' => self::AUTO, 'convert' => 'number' ],
			'plate' => [ 'to' => 'plate', 'policy' => self::AUTO, 'convert' => null ],
			'motorways' => [ 'to' => 'motorways', 'policy' => self::AUTO, 'convert' => 'links' ],
			'roads' => [ 'to' => 'roads', 'policy' => self::AUTO, 'convert' => 'links' ],
			'país' => [ 'to' => 'country', 'policy' => self::REVIEW, 'convert' => null,
				'note' => 'country name written in Portuguese' ],
			'country' => [ 'to' => 'country', 'policy' => self::REVIEW, 'convert' => null,
				'note' => 'country name may be written in Portuguese' ],
			'map' => [ 'to' => 'map', 'policy' => self::REVIEW, 'convert' => null,
				'note' => '<map> is not rendered by any installed extension' ],
		];
		return [
			'Infobox Location' => [
				'emits' => $emits,
				'english' => self::EN_LOCATION,
				'params' => self::englishParams() + $params,
			],
			'Infobox * Location' => [
				'emits' => $emits,
				'english' => self::EN_LOCATION,
				'params' => self::englishParams() + $params,
			],
		];
	}

	/**
	 * @return array
	 */
	private static function chinese(): array {
		return [
			'Infobox Country' => [
				'english' => [ 'Infobox Country' ],
				'params' => self::englishParams(),
			],
		];
	}

	/**
	 * Policies for parameters that already carry their English names.
	 *
	 * Several wikis copied the English templates, and their articles mix the
	 * English parameter names with translated ones. A name being English says
	 * nothing about the *value*: a country or region is still written in the
	 * local language and has to be looked at, while a population figure or a
	 * licence plate means the same thing everywhere.
	 *
	 * @return array<string,array{to:?string,policy:string,convert:?string,note?:string}>
	 */
	private static function englishParams(): array {
		$auto = static fn ( string $convert = null ) =>
			[ 'to' => null, 'policy' => self::AUTO, 'convert' => $convert ];
		$review = static fn ( string $note ) =>
			[ 'to' => null, 'policy' => self::REVIEW, 'convert' => null, 'note' => $note ];

		$params = [
			'pop' => $auto( 'number' ),
			'plate' => $auto(),
			'motorways' => $auto( 'links' ),
			'roads' => $auto( 'links' ),
			'capital' => $auto( 'links' ),
			'hitchbase' => $auto(),
			'avp' => $auto(),
			'BW' => $auto(),
			'symbol' => $auto(),
			'state_symbol' => $auto(),
			'subdivision_symbol' => $auto(),
			'subdivision2_symbol' => $auto(),
			'region_symbol' => $auto(),
			'country' => $review( 'country name may be written in the local language' ),
			'state' => $review( 'region name may be written in the local language' ),
			'region' => $review( 'region name may be written in the local language' ),
			'district' => $review( 'region name may be written in the local language' ),
			'neighbour' => $review( 'free text, may be written in the local language' ),
			'highways' => $review( 'not a parameter of the English infobox' ),
			'subdivision_name' => $review( 'region name may be written in the local language' ),
			'subdivision2_name' => $review( 'region name may be written in the local language' ),
			'subdivision_type' => $review( 'may be written in the local language' ),
			'subdivision2_type' => $review( 'may be written in the local language' ),
			'name' => $review( 'the display name in the local language' ),
			'name_native' => $review( 'the display name in the local language' ),
			'language' => $review( 'language names may be written in the local language' ),
			'currency' => $review( 'currency names may be written in the local language' ),
			'paved' => $review( 'free text, may be written in the local language' ),
			'links' => $review( 'free text, may be written in the local language' ),
			'hitch' => $review( 'local rating templates' ),
			'map' => $review( '<map> is not rendered by any installed extension' ),
			'in' => [ 'to' => null, 'policy' => self::SKIP, 'convert' => null,
				'note' => 'local breadcrumb only' ],
		];
		// The English name is its own target.
		foreach ( $params as $name => &$rule ) {
			if ( $rule['policy'] !== self::SKIP ) {
				$rule['to'] = $name;
			}
		}
		return $params;
	}

	/**
	 * The service-station box lists up to ten approaches from each direction,
	 * as numbered parameter pairs, so its mapping is generated rather than
	 * written out forty times.
	 *
	 * @return array<string,array{to:?string,policy:string,convert:?string,note?:string}>
	 */
	private static function serviceStationParams(): array {
		$params = [
			'Autobahn' => [ 'to' => 'highway', 'policy' => self::AUTO, 'convert' => null ],
			'Einrichtungen' => [ 'to' => 'facilities', 'policy' => self::REVIEW, 'convert' => null,
				'note' => 'free text, written in German' ],
			'Übergang' => [ 'to' => 'crossing', 'policy' => self::REVIEW, 'convert' => null,
				'note' => 'free text, written in German' ],
			'hitch' => [ 'to' => 'hitch', 'policy' => self::REVIEW, 'convert' => null,
				'note' => 'German {{gut}}-style rating templates' ],
			'Karte' => [ 'to' => 'map', 'policy' => self::REVIEW, 'convert' => null,
				'note' => '<map> is not rendered by any installed extension' ],
			'in' => [ 'to' => null, 'policy' => self::SKIP, 'convert' => null,
				'note' => 'local breadcrumb/category only' ],
		];
		foreach ( array_merge( [ '' ], range( 1, 9 ) ) as $n ) {
			$params["von$n"] = [ 'to' => "from$n", 'policy' => self::AUTO, 'convert' => 'links' ];
			$params["von Richtung$n"] =
				[ 'to' => "from direction$n", 'policy' => self::AUTO, 'convert' => 'links' ];
			$params["nach$n"] = [ 'to' => "towards$n", 'policy' => self::AUTO, 'convert' => 'links' ];
			$params["nach Richtung$n"] =
				[ 'to' => "towards direction$n", 'policy' => self::AUTO, 'convert' => 'links' ];
		}
		return $params;
	}
}
