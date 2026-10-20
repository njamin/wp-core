<?php
namespace BP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Version-gated migrations (DESIGN §4.6.1, §4.9.3).
 *
 *     Migrations::run( 'acme_db_version', ACME_VERSION, [
 *         '0.1.0' => [ Install::class, 'create_audit_table' ],
 *         '0.2.0' => [ Install::class, 'add_bookings_table' ],
 *     ] );
 *
 * Runs, in version order, every step newer than the stored version and not newer than
 * the plugin's. Each step must be idempotent: a step that throws stops the run, the
 * stored version stays at the last step that succeeded, and the next request retries.
 * Steps must also be backward compatible (expand, then contract in a later release), so
 * the previous release still runs on the new schema.
 */
final class Migrations {

	/**
	 * @param array<string, callable> $steps Version => step.
	 * @return list<string> The versions that ran.
	 */
	public static function run( string $option, string $target, array $steps ): array {
		$stored = (string) get_option( $option, '0' );
		if ( version_compare( $stored, $target, '>=' ) ) {
			return [];
		}

		// One request at a time: two concurrent first visits after a deploy must not both migrate.
		$lock = $option . '_lock';
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( time() - (int) get_option( $lock ) < 10 * MINUTE_IN_SECONDS ) {
				return [];
			}
			update_option( $lock, time(), false ); // A crashed run left it; take over.
		}

		uksort( $steps, 'version_compare' );
		$ran = [];
		try {
			foreach ( $steps as $version => $step ) {
				if ( version_compare( $version, $stored, '<=' ) || version_compare( $version, $target, '>' ) ) {
					continue;
				}

				$step();
				if ( ! update_option( $option, $version, true ) && get_option( $option ) !== $version ) {
					throw new \RuntimeException( "Could not record migration {$version}" );
				}
				$ran[] = $version;
			}

			// Versions without a step still count as migrated.
			if ( version_compare( (string) get_option( $option, '0' ), $target, '<' ) ) {
				update_option( $option, $target, true );
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'Migration failed', [ 'option' => $option, 'from' => $stored, 'ran' => $ran, 'exception' => $e->getMessage() ] );
			throw $e;
		} finally {
			delete_option( $lock );
		}

		Logger::info( 'Migrations ran', [ 'option' => $option, 'from' => $stored, 'to' => $target, 'steps' => $ran ] );

		return $ran;
	}
}
