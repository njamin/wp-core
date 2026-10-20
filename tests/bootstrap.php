<?php
/**
 * Loads the sandbox site (BP_WP_LOAD overrides the path), then configures the Logger for
 * a test prefix whose audit table the suite creates and drops.
 */

require dirname( __DIR__ ) . '/vendor/autoload.php';

$_SERVER['HTTP_HOST']      = 'bp-sandbox.ddev.site';
$_SERVER['SERVER_NAME']    = 'bp-sandbox.ddev.site';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS']          = 'on';

$wp_load = getenv( 'BP_WP_LOAD' ) ?: '/var/www/html/sandbox/web/wp/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found at {$wp_load}. Run the suite inside DDEV, or set BP_WP_LOAD.\n" );
	exit( 1 );
}

require $wp_load;
require_once ABSPATH . 'wp-admin/includes/user.php';

\BP\Core\Logger::configure( 'BPTEST', '0.0.0-test' );
\BP\Core\Audit_Log::create_table();

register_shutdown_function(
	static function (): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . \BP\Core\Audit_Log::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed test table name
		wp_clear_scheduled_hook( \BP\Core\Audit_Log::cron_hook() );
	}
);
