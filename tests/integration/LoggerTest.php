<?php
namespace BP\Core\Tests;

use BP\Core\Logger;
use BP\Core\Memory_Sink;
use PHPUnit\Framework\TestCase;

final class LoggerTest extends TestCase {

	private Memory_Sink $sink;

	protected function setUp(): void {
		$this->sink = new Memory_Sink();
		Logger::use_sink( $this->sink );
	}

	protected function tearDown(): void {
		Logger::use_sink( null );
	}

	public function test_every_line_carries_request_user_environment_and_version(): void {
		Logger::warning( 'Webhook retried', [ 'status' => 503 ] );

		$line = $this->sink->lines[0];
		$this->assertSame( 'warning', $line['level'] );
		$this->assertSame( [ 'status' => 503 ], $line['context'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $line['request_id'] );
		$this->assertSame( 'local', $line['env'] );
		$this->assertSame( '0.0.0-test', $line['version'] );
		$this->assertArrayHasKey( 'user_id', $line );
	}

	public function test_one_request_keeps_one_request_id_across_streams(): void {
		Logger::info( 'a' );
		Logger::audit( 'thing.done', 7 );

		$this->assertSame( $this->sink->lines[0]['request_id'], $this->sink->audits[0]['request_id'] );
	}

	public function test_secrets_in_the_context_are_redacted_at_any_depth(): void {
		Logger::error(
			'Payment failed',
			[
				'order'   => 42,
				'api_key' => 'sk_live_x',
				'request' => [ 'headers' => [ 'Authorization' => 'Bearer y', 'Cookie' => 'z' ], 'card_number' => '4111' ],
				'Password' => 'p',
				'iban'    => 'FR76',
				'nonce'   => 'n',
			]
		);

		$context = $this->sink->lines[0]['context'];
		$this->assertSame( 42, $context['order'] );
		foreach ( [ $context['api_key'], $context['request']['headers']['Authorization'], $context['request']['headers']['Cookie'], $context['request']['card_number'], $context['Password'], $context['iban'], $context['nonce'] ] as $value ) {
			$this->assertSame( '[redacted]', $value );
		}
	}

	public function test_a_project_can_add_its_own_redacted_keys(): void {
		$add = static fn( array $keys ): array => [ ...$keys, 'siret' ];
		add_filter( 'bp/core/redacted_keys', $add );

		try {
			Logger::info( 'x', [ 'siret' => '123', 'name' => 'kept' ] );
		} finally {
			remove_filter( 'bp/core/redacted_keys', $add );
		}

		$this->assertSame( [ 'siret' => '[redacted]', 'name' => 'kept' ], $this->sink->lines[0]['context'] );
	}

	public function test_objects_in_the_context_are_reduced_to_their_class(): void {
		Logger::info( 'x', [ 'user' => wp_get_current_user() ] );

		$this->assertSame( '[object WP_User]', $this->sink->lines[0]['context']['user'] );
	}

	public function test_audit_records_are_redacted_too(): void {
		Logger::audit( 'settings.changed', 0, [ 'key' => 'smtp', 'old' => [ 'password' => 'a' ], 'new' => [ 'password' => 'b' ] ] );

		$this->assertSame( '[redacted]', $this->sink->audits[0]['context']['old']['password'] );
	}

	public function test_hardening_events_land_in_the_audit_stream(): void {
		do_action( 'bp/hardening/event', 'lockout', [ 'user' => 'pseudonym', 'ip' => '203.0.113.9' ] );

		$this->assertSame( 'security.lockout', $this->sink->audits[0]['event'] );
	}

	/**
	 * Last in the class: the constant stays defined for the rest of the process.
	 */
	public function test_the_log_level_constant_drops_lines_below_it(): void {
		Logger::debug( 'before the constant' );
		define( 'BPTEST_LOG_LEVEL', 'info' );

		Logger::debug( 'investigating' );
		Logger::info( 'cron done' );

		$this->assertSame( [ 'before the constant', 'cron done' ], array_column( $this->sink->lines, 'message' ) );
	}
}
