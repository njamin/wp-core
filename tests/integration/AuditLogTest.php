<?php
namespace BP\Core\Tests;

use BP\Core\Audit_Log;
use BP\Core\Logger;
use PHPUnit\Framework\TestCase;

/**
 * Writes to the real audit table the bootstrap created (`wp_*_bptest_log`).
 */
final class AuditLogTest extends TestCase {

	/** @var list<int> */
	private static array $users = [];

	protected function setUp(): void {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . Audit_Log::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test table
	}

	public static function tearDownAfterClass(): void {
		foreach ( self::$users as $id ) {
			wp_delete_user( $id );
		}
		wp_set_current_user( 0 );
	}

	public function test_an_audit_event_is_written_with_its_ip_in_its_own_column(): void {
		Logger::audit( 'security.lockout', 0, [ 'user' => 'pseudonym', 'ip' => '203.0.113.9' ] );

		$row = $this->rows()[0];
		$this->assertSame( 'security.lockout', $row->event );
		$this->assertSame( '203.0.113.9', $row->ip );
		$this->assertSame( [ 'user' => 'pseudonym' ], json_decode( $row->context, true ) );
	}

	public function test_the_purge_deletes_old_rows_and_truncates_ips_after_thirty_days(): void {
		$this->insert_at( '-400 days', '198.51.100.7' );
		$this->insert_at( '-40 days', '198.51.100.8' );
		$this->insert_at( '-40 days', '2001:db8:1234:5678::1' );
		$this->insert_at( '-1 day', '198.51.100.9' );

		Audit_Log::purge();

		$this->assertEqualsCanonicalizing( [ '198.51.100.0', '2001:db8:1234::', '198.51.100.9' ], array_column( $this->rows(), 'ip' ) );
	}

	public function test_a_person_can_export_their_rows(): void {
		$user = $this->user();
		wp_set_current_user( $user->ID );
		Logger::audit( 'booking.cancelled', 1187 );
		wp_set_current_user( 0 );

		$export = Audit_Log::export( $user->user_email );

		$this->assertTrue( $export['done'] );
		$this->assertSame( 'booking.cancelled', $export['data'][0]['data'][1]['value'] );
	}

	public function test_erasing_a_person_keeps_the_rows_but_detaches_them(): void {
		$user = $this->user();
		wp_set_current_user( $user->ID );
		Logger::audit( 'booking.cancelled', 1187 );
		wp_set_current_user( 0 );

		$result = Audit_Log::erase( $user->user_email );

		$row = $this->rows()[0];
		$this->assertTrue( $result['items_retained'] );
		$this->assertNull( $row->user_id );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $row->actor );
		$this->assertNotSame( hash( 'sha256', 'user:' . $user->ID ), $row->actor, 'keyed, not a plain hash of the ID' );
		$this->assertSame( [], Audit_Log::export( $user->user_email )['data'] );
	}

	public function test_the_exporter_and_eraser_are_registered_with_wordpress(): void {
		$this->assertArrayHasKey( 'bptest-audit-log', apply_filters( 'wp_privacy_personal_data_exporters', [] ) );
		$this->assertArrayHasKey( 'bptest-audit-log', apply_filters( 'wp_privacy_personal_data_erasers', [] ) );
	}

	/** @return list<object> */
	private function rows(): array {
		global $wpdb;

		return $wpdb->get_results( 'SELECT * FROM ' . Audit_Log::table() . ' ORDER BY id' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- test table
	}

	private function insert_at( string $when, string $ip ): void {
		global $wpdb;
		$wpdb->insert( Audit_Log::table(), [ 'created_at' => gmdate( 'Y-m-d H:i:s', strtotime( $when ) ), 'event' => 'x', 'ip' => $ip, 'request_id' => str_repeat( '0', 16 ), 'context' => '{}' ] );
	}

	private function user(): \WP_User {
		$login         = 'bp-test-' . wp_generate_password( 8, false );
		$id            = wp_insert_user( [ 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => wp_generate_password( 24 ) ] );
		self::$users[] = $id;

		return get_userdata( $id );
	}
}
