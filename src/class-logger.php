<?php
namespace BP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one way code writes a log line (DESIGN §4.7).
 *
 *     Logger::warning( 'Payment webhook retried', [ 'order' => 42, 'status' => 503 ] );
 *     Logger::audit( 'booking.cancelled', 1187, [ 'reason' => 'client' ] );
 *
 * debug/info/warning/error go to the diagnostic log (PHP's error log); error also fires
 * `bp/core/error` for the project's error tracker, if it has one. audit goes to the
 * audit table. Every line carries the request ID, user ID, environment and plugin version,
 * and the context is redacted before anything is written.
 */
final class Logger {

	private const LEVELS = [ 'debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3 ];

	private static string $prefix = 'BP';

	private static string $version = '';

	private static ?string $request_id = null;

	private static ?Log_Sink $sink = null;

	/**
	 * Called once by the client plugin's Core::boot().
	 *
	 * @param string $prefix  The project prefix in capitals, e.g. `ACME`.
	 * @param string $version The plugin version.
	 */
	public static function configure( string $prefix, string $version ): void {
		self::$prefix  = $prefix;
		self::$version = $version;

		Audit_Log::register( strtolower( $prefix ) );
		add_action( 'bp/hardening/event', [ self::class, 'record_security_event' ], 10, 2 );
	}

	/**
	 * Replaces the destinations, e.g. with a Memory_Sink in tests. Null restores them.
	 */
	public static function use_sink( ?Log_Sink $sink ): void {
		self::$sink = $sink;
	}

	/** @param array<string, mixed> $context */
	public static function debug( string $message, array $context = [] ): void {
		self::write( 'debug', $message, $context );
	}

	/** @param array<string, mixed> $context */
	public static function info( string $message, array $context = [] ): void {
		self::write( 'info', $message, $context );
	}

	/** @param array<string, mixed> $context */
	public static function warning( string $message, array $context = [] ): void {
		self::write( 'warning', $message, $context );
	}

	/** @param array<string, mixed> $context */
	public static function error( string $message, array $context = [] ): void {
		self::write( 'error', $message, $context );
	}

	/**
	 * Who did what to which object. Always on, in every environment.
	 *
	 * @param array<string, mixed> $context
	 */
	public static function audit( string $event, int $object_id, array $context = [] ): void {
		$record = [
			'event'      => $event,
			'object_id'  => $object_id,
			'user_id'    => self::user_id(),
			'request_id' => self::request_id(),
			'context'    => Redactor::redact( $context ),
		];

		if ( self::$sink ) {
			self::$sink->audit( $record );
			return;
		}

		if ( ! Audit_Log::insert( $record ) ) {
			// Failures are loud: the audit row is evidence, so its loss must at least be on record.
			self::write( 'error', 'Audit row not written', [ 'event' => $event, 'object_id' => $object_id ] );
		}
	}

	/**
	 * Receives the hardening package's events (failed logins, lockouts, throttles…).
	 *
	 * @param array<string, mixed> $context
	 */
	public static function record_security_event( string $event, array $context ): void {
		self::audit( 'security.' . $event, 0, $context );
	}

	private static function user_id(): int {
		// Before plugins_loaded the pluggable functions don't exist yet.
		return function_exists( 'wp_get_current_user' ) ? get_current_user_id() : 0;
	}

	public static function request_id(): string {
		self::$request_id ??= bin2hex( random_bytes( 8 ) );

		return self::$request_id;
	}

	/** @param array<string, mixed> $context */
	private static function write( string $level, string $message, array $context ): void {
		if ( self::LEVELS[ $level ] < self::LEVELS[ self::threshold() ] ) {
			return;
		}

		$record = [
			'level'      => $level,
			'message'    => $message,
			'context'    => Redactor::redact( $context ),
			'request_id' => self::request_id(),
			'user_id'    => self::user_id(),
			'env'        => wp_get_environment_type(),
			'version'    => self::$version,
		];

		if ( self::$sink ) {
			self::$sink->line( $record );
			return;
		}

		$tag = 'debug' === $level ? '[' . self::$prefix . ' DEBUG]' : '[' . self::$prefix . ']';
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the Logger is the one place allowed to write it
			sprintf(
				'%s %s %s %s',
				$tag,
				strtoupper( $level ),
				$message,
				wp_json_encode( array_diff_key( $record, [ 'level' => 1, 'message' => 1 ] ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			)
		);

		if ( 'error' === $level ) {
			do_action( 'bp/core/error', $record );
		}
	}

	/**
	 * `<PREFIX>_LOG_LEVEL`, or the default for the environment: debug except in production.
	 */
	private static function threshold(): string {
		$constant = self::$prefix . '_LOG_LEVEL';
		if ( defined( $constant ) && isset( self::LEVELS[ constant( $constant ) ] ) ) {
			return constant( $constant );
		}

		return 'production' === wp_get_environment_type() ? 'info' : 'debug';
	}
}
