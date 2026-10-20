<?php
namespace BP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces secrets in a log context with `[redacted]` before anything is written
 * (DESIGN §4.7.3). Keys are matched at every depth; a project adds its own patterns
 * with the `bp/core/redacted_keys` filter.
 */
final class Redactor {

	private const PATTERNS = [ 'pass*', '*token*', '*secret*', 'nonce', '*nonce', 'authorization', 'cookie*', 'iban', 'card*', '*api_key*', 'apikey' ];

	/**
	 * @param array<mixed> $context
	 * @return array<mixed>
	 */
	public static function redact( array $context ): array {
		$patterns = (array) apply_filters( 'bp/core/redacted_keys', self::PATTERNS );

		return self::walk( $context, $patterns );
	}

	/**
	 * @param array<mixed>  $data
	 * @param list<string>  $patterns
	 * @return array<mixed>
	 */
	private static function walk( array $data, array $patterns ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && self::matches( strtolower( $key ), $patterns ) ) {
				$data[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = self::walk( $value, $patterns );
			} elseif ( is_object( $value ) ) {
				// Objects can hold anything; only their class is safe to keep.
				$data[ $key ] = '[object ' . get_class( $value ) . ']';
			}
		}

		return $data;
	}

	/**
	 * @param list<string> $patterns
	 */
	private static function matches( string $key, array $patterns ): bool {
		foreach ( $patterns as $pattern ) {
			if ( fnmatch( strtolower( $pattern ), $key ) ) {
				return true;
			}
		}

		return false;
	}
}
