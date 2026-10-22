<?php
namespace BP\Core\Tests;

use BP\Core\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! defined( 'BP_ENCRYPTION_KEY' ) ) {
			define( 'BP_ENCRYPTION_KEY', 'test-key-' . str_repeat( 'x', 40 ) );
		}
	}

	public function test_a_value_reads_back_and_is_not_stored_in_clear(): void {
		$stored = Crypto::encrypt( 'refresh-token-1234' );

		$this->assertTrue( Crypto::is_encrypted( $stored ) );
		$this->assertStringNotContainsString( 'refresh-token-1234', $stored );
		$this->assertSame( 'refresh-token-1234', Crypto::decrypt( $stored ) );
	}

	public function test_the_same_value_never_encrypts_the_same_twice(): void {
		$this->assertNotSame( Crypto::encrypt( 'token' ), Crypto::encrypt( 'token' ) );
	}

	public function test_an_altered_or_foreign_value_reads_as_null(): void {
		$stored = Crypto::encrypt( 'token' );

		$this->assertNull( Crypto::decrypt( substr( $stored, 0, -2 ) . ( str_ends_with( $stored, 'AA' ) ? 'BB' : 'AA' ) ) );
		$this->assertNull( Crypto::decrypt( 'plain-token' ) );
		$this->assertNull( Crypto::decrypt( 'bp1:not-base64!' ) );
	}
}
