<?php
namespace BP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption at rest for values a leak would hurt (GUIDELINES « Sensitive data »): XChaCha20-Poly1305
 * from sodium, keyed by `BP_ENCRYPTION_KEY` from config/secrets.php, so a database dump alone reads
 * nothing. A stored value is `bp1:` then base64 of nonce and ciphertext.
 */
final class Crypto {

	private const PREFIX = 'bp1:';

	private const NONCE_BYTES = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

	/**
	 * @throws \RuntimeException When `BP_ENCRYPTION_KEY` is not set.
	 */
	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( self::NONCE_BYTES );

		return self::PREFIX . base64_encode( $nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt( $plain, self::PREFIX, $nonce, self::key() ) );
	}

	/**
	 * Null when the value is not one of ours, was altered, or was encrypted under another key.
	 *
	 * @throws \RuntimeException When `BP_ENCRYPTION_KEY` is not set.
	 */
	public static function decrypt( string $stored ): ?string {
		if ( ! self::is_encrypted( $stored ) ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= self::NONCE_BYTES ) {
			return null;
		}
		$plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt( substr( $raw, self::NONCE_BYTES ), self::PREFIX, substr( $raw, 0, self::NONCE_BYTES ), self::key() );

		return false === $plain ? null : $plain;
	}

	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	/**
	 * @throws \RuntimeException When `BP_ENCRYPTION_KEY` is not set.
	 */
	private static function key(): string {
		if ( ! defined( 'BP_ENCRYPTION_KEY' ) || '' === (string) BP_ENCRYPTION_KEY ) {
			throw new \RuntimeException( 'BP_ENCRYPTION_KEY is not set in config/secrets.php' );
		}

		return sodium_crypto_generichash( (string) BP_ENCRYPTION_KEY, '', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES );
	}
}
