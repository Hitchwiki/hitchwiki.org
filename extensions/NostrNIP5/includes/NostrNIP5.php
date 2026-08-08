<?php
/**
 * NostrNIP5 - NIP-5 verification for MediaWiki
 *
 * @file
 * @ingroup Extensions
 */

if ( function_exists( 'wfLoadExtension' ) ) {
	wfLoadExtension( 'NostrNIP5' );
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['NostrNIP5'] = __DIR__ . '/../i18n';
	wfWarn(
		'Deprecated PHP entry point used for NostrNIP5 extension. ' .
		'Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of the NostrNIP5 extension requires MediaWiki 1.42+' );
}

