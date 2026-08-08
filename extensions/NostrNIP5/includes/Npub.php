<?php

namespace NostrNIP5;

/** Strict NIP-19 npub decoder. */
class Npub {
	private const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
	private const GENERATORS = [ 0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3 ];

	public static function toHex( string $npub ): ?string {
		$npub = strtolower( trim( $npub ) );
		if ( strlen( $npub ) !== 63 || substr( $npub, 0, 5 ) !== 'npub1' ) {
			return null;
		}
		$values = [];
		foreach ( str_split( substr( $npub, 5 ) ) as $character ) {
			$value = strpos( self::CHARSET, $character );
			if ( $value === false ) {
				return null;
			}
			$values[] = $value;
		}
		if ( self::polymod( array_merge( self::expandHrp( 'npub' ), $values ) ) !== 1 ) {
			return null;
		}

		$data = array_slice( $values, 0, -6 );
		$accumulator = 0;
		$bits = 0;
		$bytes = [];
		foreach ( $data as $value ) {
			$accumulator = ( $accumulator << 5 ) | $value;
			$bits += 5;
			while ( $bits >= 8 ) {
				$bits -= 8;
				$bytes[] = ( $accumulator >> $bits ) & 0xff;
			}
			$accumulator &= ( 1 << $bits ) - 1;
		}
		if ( count( $bytes ) !== 32 || $bits >= 5 || ( $accumulator << ( 8 - $bits ) ) !== 0 ) {
			return null;
		}
		return implode( '', array_map( static fn ( int $byte ): string => sprintf( '%02x', $byte ), $bytes ) );
	}

	private static function expandHrp( string $hrp ): array {
		$characters = array_map( 'ord', str_split( $hrp ) );
		return array_merge(
			array_map( static fn ( int $value ): int => $value >> 5, $characters ),
			[ 0 ],
			array_map( static fn ( int $value ): int => $value & 31, $characters )
		);
	}

	private static function polymod( array $values ): int {
		$checksum = 1;
		foreach ( $values as $value ) {
			$top = $checksum >> 25;
			$checksum = ( ( $checksum & 0x1ffffff ) << 5 ) ^ $value;
			foreach ( self::GENERATORS as $index => $generator ) {
				if ( ( $top >> $index ) & 1 ) {
					$checksum ^= $generator;
				}
			}
		}
		return $checksum;
	}
}
