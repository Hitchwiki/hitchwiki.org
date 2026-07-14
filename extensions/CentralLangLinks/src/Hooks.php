<?php

namespace MediaWiki\Extension\CentralLangLinks;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\Hook\LanguageLinksHook;
use MediaWiki\Title\Title;

/**
 * Renders a page's interlanguage links from the central page_translations
 * table instead of from per-page [[xx:Title]] wikitext.
 */
class Hooks implements LanguageLinksHook, LoadExtensionSchemaUpdatesHook {

	/**
	 * Replace the effective language links for a page with the central set.
	 *
	 * If the current page has no entry in the central table, we leave the
	 * existing (wikitext-derived) links untouched, so migration can be
	 * incremental — a page is centrally managed only once it has a row.
	 *
	 * @param Title $title
	 * @param string[] &$links Elements of the form "lang:Title".
	 * @param array &$linkFlags
	 */
	public function onLanguageLinks( $title, &$links, &$linkFlags ) {
		// Only main-namespace content pages participate.
		if ( !$title->inNamespace( NS_MAIN ) ) {
			return;
		}

		$services = MediaWikiServices::getInstance();
		// Each Hitchwiki language runs as its own wiki with $wgLanguageCode = wiki id.
		$currentLang = $services->getMainConfig()->get( 'LanguageCode' );
		// page_translations is a shared table, so the replica connection resolves
		// it to the shared (English) DB automatically.
		$dbr = $services->getConnectionProvider()->getReplicaDatabase();

		$concept = $dbr->newSelectQueryBuilder()
			->select( 'pt_concept' )
			->from( 'page_translations' )
			->where( [ 'pt_lang' => $currentLang, 'pt_title' => $title->getDBkey() ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( $concept === false ) {
			// Not centrally managed yet; keep whatever the wikitext defined.
			return;
		}

		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'pt_lang', 'pt_title' ] )
			->from( 'page_translations' )
			->where( [ 'pt_concept' => $concept ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$central = [];
		foreach ( $res as $row ) {
			// Skip the current language — a page never links to itself.
			if ( $row->pt_lang === $currentLang ) {
				continue;
			}
			// Language links carry the human title (spaces, not underscores).
			$central[] = $row->pt_lang . ':' . strtr( $row->pt_title, '_', ' ' );
		}

		// The central table is authoritative for centrally managed pages.
		$links = $central;
	}

	/**
	 * Create page_translations. It is a shared table, so only create the
	 * physical copy in the shared database (the English wiki), and skip the
	 * per-language wikis to avoid redundant empty tables.
	 *
	 * @param \DatabaseUpdater $updater
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		// Runs in the installer/updater context, where the service container is
		// not fully bootstrapped — use the updater's own DB handle and globals.
		$sharedDB = $GLOBALS['wgSharedDB'] ?? null;
		if ( $sharedDB !== null && $updater->getDB()->getDBname() !== $sharedDB ) {
			// Only create the physical table in the shared (English) database.
			return;
		}
		$updater->addExtensionTable(
			'page_translations',
			__DIR__ . '/../sql/page_translations.sql'
		);
	}
}
