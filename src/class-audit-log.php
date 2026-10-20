<?php
namespace BP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The audit table `{$wpdb->prefix}<prefix>_log` (DESIGN §4.7.1, §4.7.4).
 *
 * Append-only. A daily cron deletes rows older than `<PREFIX>_AUDIT_RETENTION_DAYS` (365)
 * and truncates IPs to /24 or /48 after 30 days. Erasing a person through WordPress's
 * privacy tools pseudonymises their rows instead of deleting the evidence.
 * The client plugin's first migration calls create_table().
 */
final class Audit_Log {

	private const IP_FULL_DAYS = 30;

	private static string $prefix = 'bp';

	public static function register( string $prefix ): void {
		self::$prefix = $prefix;

		add_action( self::cron_hook(), [ self::class, 'purge' ] );
		add_action( 'init', [ self::class, 'schedule' ] );
		add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'add_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'add_eraser' ] );
	}

	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . self::$prefix . '_log';
	}

	public static function cron_hook(): string {
		return self::$prefix . '_cron_log_purge';
	}

	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				event varchar(100) NOT NULL,
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned DEFAULT NULL,
				actor varchar(64) DEFAULT NULL,
				ip varchar(45) DEFAULT NULL,
				request_id char(16) NOT NULL,
				context longtext NOT NULL,
				PRIMARY KEY  (id),
				KEY event (event),
				KEY user_id (user_id),
				KEY created_at (created_at)
			) {$wpdb->get_charset_collate()};"
		);
	}

	/**
	 * @param array{event: string, object_id: int, user_id: int, request_id: string, context: array<mixed>} $record
	 */
	public static function insert( array $record ): bool {
		global $wpdb;

		$context = $record['context'];
		$ip      = isset( $context['ip'] ) && is_string( $context['ip'] ) ? $context['ip'] : null;
		unset( $context['ip'] );

		return false !== $wpdb->insert(
			self::table(),
			[
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'event'      => substr( $record['event'], 0, 100 ),
				'object_id'  => $record['object_id'],
				'user_id'    => $record['user_id'] ?: null,
				'ip'         => $ip,
				'request_id' => $record['request_id'],
				'context'    => (string) wp_json_encode( $context ),
			]
		);
	}

	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::cron_hook() ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::cron_hook() );
		}
	}

	public static function purge(): void {
		global $wpdb;
		$start  = microtime( true );
		$table  = self::table();
		$const  = strtoupper( self::$prefix ) . '_AUDIT_RETENTION_DAYS';
		$days   = defined( $const ) ? (int) constant( $const ) : 365;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name comes from $wpdb->prefix and the project prefix, never from input
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );

		$old = gmdate( 'Y-m-d H:i:s', time() - self::IP_FULL_DAYS * DAY_IN_SECONDS );
		$ips = $wpdb->get_results( $wpdb->prepare( "SELECT id, ip FROM {$table} WHERE ip IS NOT NULL AND ip NOT LIKE %s AND ip NOT LIKE %s AND created_at < %s", '%.0', '%::', $old ) );
		// phpcs:enable

		foreach ( $ips as $row ) {
			$wpdb->update( $table, [ 'ip' => self::truncate_ip( $row->ip ) ], [ 'id' => $row->id ] );
		}

		Logger::info(
			'Audit log purge done',
			[
				'deleted'       => (int) $deleted,
				'ips_truncated' => count( $ips ),
				'duration_ms'   => (int) ( ( microtime( true ) - $start ) * 1000 ),
			]
		);
	}

	public static function truncate_ip( string $ip ): string {
		$bin = @inet_pton( $ip );
		if ( false === $bin ) {
			return '';
		}

		$keep = 4 === strlen( $bin ) ? 3 : 6;

		return (string) inet_ntop( substr( $bin, 0, $keep ) . str_repeat( "\0", strlen( $bin ) - $keep ) );
	}

	/**
	 * @param array<string, mixed> $exporters
	 * @return array<string, mixed>
	 */
	public static function add_exporter( array $exporters ): array {
		$exporters[ self::$prefix . '-audit-log' ] = [
			'exporter_friendly_name' => __( 'Activity log', 'bp-core' ),
			'callback'               => [ self::class, 'export' ],
		];

		return $exporters;
	}

	/**
	 * @param array<string, mixed> $erasers
	 * @return array<string, mixed>
	 */
	public static function add_eraser( array $erasers ): array {
		$erasers[ self::$prefix . '-audit-log' ] = [
			'eraser_friendly_name' => __( 'Activity log', 'bp-core' ),
			'callback'             => [ self::class, 'erase' ],
		];

		return $erasers;
	}

	/**
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export( string $email, int $page = 1 ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [ 'data' => [], 'done' => true ];
		}

		$per_page = 200;
		$table    = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is not input
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, event, object_id FROM {$table} WHERE user_id = %d ORDER BY id LIMIT %d OFFSET %d", $user->ID, $per_page, ( $page - 1 ) * $per_page ) );

		$data = [];
		foreach ( $rows as $row ) {
			$data[] = [
				'group_id'    => self::$prefix . '-audit-log',
				'group_label' => __( 'Activity log', 'bp-core' ),
				'item_id'     => 'audit-' . $row->id,
				'data'        => [
					[ 'name' => __( 'Date (UTC)', 'bp-core' ), 'value' => $row->created_at ],
					[ 'name' => __( 'Event', 'bp-core' ), 'value' => $row->event ],
					[ 'name' => __( 'Object', 'bp-core' ), 'value' => (string) $row->object_id ],
				],
			];
		}

		return [ 'data' => $data, 'done' => count( $rows ) < $per_page ];
	}

	/**
	 * Replaces the user ID with a keyed pseudonym, so the rows stay as evidence but no
	 * longer point to the person.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase( string $email ): array {
		global $wpdb;
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true ];
		}

		$key     = defined( 'BP_LOG_KEY' ) && BP_LOG_KEY ? (string) BP_LOG_KEY : wp_salt( 'auth' );
		$updated = $wpdb->update(
			self::table(),
			[ 'user_id' => null, 'actor' => hash_hmac( 'sha256', 'user:' . $user->ID, $key ) ],
			[ 'user_id' => $user->ID ]
		);

		return [
			'items_removed'  => $updated > 0,
			'items_retained' => $updated > 0,
			'messages'       => $updated > 0 ? [ __( 'Activity log entries were kept as evidence, without your account attached.', 'bp-core' ) ] : [],
			'done'           => true,
		];
	}
}
