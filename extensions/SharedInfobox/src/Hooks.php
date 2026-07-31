<?php

namespace MediaWiki\Extension\SharedInfobox;

use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Output\Hook\OutputPageBeforeHTMLHook;
use ObjectCacheFactory;
use Wikimedia\ObjectCache\BagOStuff;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Prepends the English article's infobox to the body of its translations.
 *
 * The injection happens after the parser cache rather than inside it: the
 * infobox belongs to another wiki, so a change on the English side must not
 * depend on every translation's parser cache being invalidated. What is worth
 * living with instead is borrowed HTML held under a short TTL of its own.
 */
class Hooks implements OutputPageBeforeHTMLHook {

	private const CACHE_VERSION = 2;
	/** How long to leave a slow or unreachable source wiki alone after a failure. */
	private const RETRY_DELAY = 60;
	/**
	 * How long a fetched infobox is kept available. Far longer than the expiry
	 * that decides when to refetch: past that expiry the copy is merely stale,
	 * and a stale infobox is much better than none if the source wiki is having
	 * a bad minute.
	 */
	private const RETENTION = 30 * 24 * 3600;

	private Config $config;
	private IConnectionProvider $dbProvider;
	private BagOStuff $cache;
	private HttpRequestFactory $httpRequestFactory;

	public function __construct(
		Config $config,
		IConnectionProvider $dbProvider,
		ObjectCacheFactory $objectCacheFactory,
		HttpRequestFactory $httpRequestFactory
	) {
		$this->config = $config;
		$this->dbProvider = $dbProvider;
		$this->cache = $objectCacheFactory->getInstance(
			constant( $config->get( 'SharedInfoboxCacheType' ) )
		);
		$this->httpRequestFactory = $httpRequestFactory;
	}

	/**
	 * @param \MediaWiki\Output\OutputPage $out
	 * @param string &$text Article body HTML.
	 */
	public function onOutputPageBeforeHTML( $out, &$text ) {
		$lang = $this->config->get( 'LanguageCode' );
		if ( $lang === $this->config->get( 'SharedInfoboxSourceLanguage' ) ) {
			return;
		}

		$title = $out->getTitle();
		if ( !$title || !$title->inNamespace( NS_MAIN ) || !$title->exists() || $title->isRedirect() ) {
			return;
		}
		// Previews, diffs-only and history views are not article bodies.
		$action = $out->getRequest()->getRawVal( 'action' ) ?? 'view';
		if ( $action !== 'view' && $action !== 'purge' ) {
			return;
		}
		// A page that still carries its own infobox keeps it: that way the
		// wikitext migration can proceed article by article without any point
		// at which a reader sees two infoboxes.
		if ( str_contains( $text, 'class="infobox' ) ) {
			return;
		}

		$dbr = $this->dbProvider->getReplicaDatabase();
		// page_translations is shared, so this resolves to the English database.
		$concept = $dbr->newSelectQueryBuilder()
			->select( 'pt_concept' )
			->from( 'page_translations' )
			->where( [ 'pt_lang' => $lang, 'pt_title' => $title->getDBkey() ] )
			->caller( __METHOD__ )
			->fetchField();
		if ( $concept === false ) {
			return;
		}

		$infobox = $this->getInfobox( (string)$concept, $lang );
		if ( $infobox === '' ) {
			return;
		}
		$text = InfoboxHtml::insertIntoContent( $text, $infobox );
	}

	/**
	 * @param string $concept DB key of the source-wiki article.
	 * @param string $lang Language we are rendering for.
	 * @return string Ready-to-inject HTML, or '' if there is nothing to show.
	 */
	private function getInfobox( string $concept, string $lang ): string {
		$key = $this->cache->makeGlobalKey( 'sharedinfobox', self::CACHE_VERSION, $lang, $concept );
		$entry = $this->cache->get( $key );
		$stored = is_array( $entry ) ? $entry['html'] : null;

		$expiry = (int)$this->config->get( 'SharedInfoboxCacheExpiry' );
		if ( $stored !== null && time() - $entry['time'] < $expiry ) {
			return $stored;
		}
		// After a failure, stop asking for a while instead of making every
		// reader wait for the same timeout.
		if ( $this->cache->get( $key . ':retry-after' ) !== false ) {
			return $stored ?? '';
		}

		$html = $this->fetchLeadSection( $concept );
		if ( $html === null ) {
			$this->cache->set( $key . ':retry-after', 1, self::RETRY_DELAY );
			return $stored ?? '';
		}

		$table = InfoboxHtml::extractInfobox( $html );
		if ( $table === null ) {
			// The source article genuinely has no infobox; that answer is as
			// cacheable as any other.
			$infobox = '';
		} else {
			$localiser = new LinkLocaliser(
				$this->dbProvider->getReplicaDatabase(),
				$lang,
				$this->config->get( 'SharedInfoboxSourceArticlePath' ),
				$this->config->get( 'ArticlePath' )
			);
			$infobox = InfoboxHtml::annotate(
				$localiser->localise( $table ),
				$this->config->get( 'SharedInfoboxSourceLanguage' )
			);
		}
		$this->cache->set( $key, [ 'html' => $infobox, 'time' => time() ], self::RETENTION );
		return $infobox;
	}

	/**
	 * @param string $concept DB key of the source-wiki article.
	 * @return string|null Rendered lead section, or null if the source wiki
	 *   could not be asked or did not answer with a parse.
	 */
	private function fetchLeadSection( string $concept ): ?string {
		$url = wfAppendQuery( $this->config->get( 'SharedInfoboxSourceApi' ), [
			'action' => 'parse',
			'page' => strtr( $concept, '_', ' ' ),
			'prop' => 'text',
			'section' => '0',
			'redirects' => '1',
			'disablelimitreport' => '1',
			'disableeditsection' => '1',
			'disabletoc' => '1',
			'formatversion' => '2',
			'format' => 'json',
		] );

		// Generous, because the first request for a page pays for the source
		// wiki parsing it; every later one is served from cache.
		$response = $this->httpRequestFactory->get( $url, [ 'timeout' => 20 ], __METHOD__ );
		if ( $response === null ) {
			return null;
		}
		$data = json_decode( $response, true );
		return $data['parse']['text'] ?? null;
	}
}
