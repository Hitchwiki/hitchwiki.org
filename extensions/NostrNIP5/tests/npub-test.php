<?php

require_once __DIR__ . '/../includes/Npub.php';

use NostrNIP5\Npub;

$zero = 'npub1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqzqujme';
if ( Npub::toHex( $zero ) !== str_repeat( '0', 64 ) ) {
	throw new RuntimeException( 'valid npub did not decode' );
}
if ( Npub::toHex( substr( $zero, 0, -1 ) . 'x' ) !== null ) {
	throw new RuntimeException( 'invalid checksum was accepted' );
}
echo "npub tests passed\n";
