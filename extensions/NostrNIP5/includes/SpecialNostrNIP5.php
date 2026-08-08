<?php
/**
 * Special page for serving /.well-known/nostr.json
 *
 * @file
 * @ingroup Extensions
 */

namespace NostrNIP5;

use SpecialPage;

class SpecialNostrNIP5 extends SpecialPage {
	public function __construct() {
		parent::__construct( 'NostrNIP5' );
	}

	/**
	 * @param string|null $subPage
	 */
	public function execute( $subPage ) {
		global $wgNostrNIP5Enabled;

		$this->setHeaders();
		
		$out = $this->getOutput();
		$request = $this->getRequest();
		$response = $request->response();

		// Disable normal MediaWiki output
		$out->disable();
		
		// Cancel output buffering and gzipping if set
		if ( function_exists( 'wfResetOutputBuffers' ) ) {
			wfResetOutputBuffers();
		}

		// Set cache headers
		$response->header( 'Cache-Control: public, max-age=300' );
		$response->header( 'Content-Type: application/json; charset=utf-8' );
		$response->header( 'Access-Control-Allow-Origin: *' );

		if ( !$wgNostrNIP5Enabled ) {
			http_response_code( 404 );
			$data = json_encode( [ 'error' => 'NIP-5 endpoint disabled' ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$response->header( 'Content-length: ' . strlen( $data ) );
			print $data;
			return;
		}

		try {
			// Delegate to handler and emit JSON response
			$handler = new WellKnownHandler();
			$result = $handler->getResponse( $request );
			$code = (int)( $result['status'] ?? 200 );
			$body = $result['body'] ?? [ 'names' => [] ];

			$data = json_encode( $body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( $data === false ) {
				throw new \Exception( 'JSON encoding failed' );
			}
			http_response_code( $code );
			$response->header( 'Content-length: ' . strlen( $data ) );
			print $data;
		} catch ( \Throwable $e ) {
			// Log error but return JSON error response
			\MediaWiki\Logger\LoggerFactory::getInstance( 'NostrNIP5' )
				->error( 'Error in NIP-5 endpoint: {message} at {file}:{line}', [
					'message' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine()
				] );
			http_response_code( 500 );
			$data = json_encode( [
				'error' => 'Internal server error',
				'detail' => $e->getMessage()
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			$response->header( 'Content-length: ' . strlen( $data ) );
			print $data;
		}
	}
}

