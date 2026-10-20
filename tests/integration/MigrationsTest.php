<?php
namespace BP\Core\Tests;

use BP\Core\Migrations;
use PHPUnit\Framework\TestCase;

final class MigrationsTest extends TestCase {

	private const OPTION = 'bptest_db_version';

	/** @var list<string> */
	private array $calls = [];

	protected function setUp(): void {
		delete_option( self::OPTION );
		delete_option( self::OPTION . '_lock' );
		$this->calls = [];
	}

	protected function tearDown(): void {
		delete_option( self::OPTION );
		delete_option( self::OPTION . '_lock' );
	}

	public function test_steps_run_once_in_version_order_up_to_the_plugin_version(): void {
		$ran = Migrations::run( self::OPTION, '0.3.0', $this->steps( '0.10.0', '0.2.0', '0.1.0', '0.3.0' ) );

		$this->assertSame( [ '0.1.0', '0.2.0', '0.3.0' ], $ran );
		$this->assertSame( [ '0.1.0', '0.2.0', '0.3.0' ], $this->calls );
		$this->assertSame( '0.3.0', get_option( self::OPTION ) );
		$this->assertSame( [], Migrations::run( self::OPTION, '0.3.0', $this->steps( '0.1.0', '0.2.0', '0.3.0' ) ) );
	}

	public function test_an_upgrade_runs_only_the_newer_steps(): void {
		update_option( self::OPTION, '0.2.0' );

		$this->assertSame( [ '0.3.0' ], Migrations::run( self::OPTION, '0.3.0', $this->steps( '0.1.0', '0.2.0', '0.3.0' ) ) );
	}

	public function test_a_version_with_no_step_is_still_recorded(): void {
		Migrations::run( self::OPTION, '0.4.1', $this->steps( '0.1.0' ) );

		$this->assertSame( '0.4.1', get_option( self::OPTION ) );
	}

	public function test_a_failing_step_stops_the_run_at_the_last_good_version_and_releases_the_lock(): void {
		$steps            = $this->steps( '0.1.0', '0.3.0' );
		$steps['0.2.0']   = static function (): void {
			throw new \RuntimeException( 'column already exists' );
		};

		try {
			Migrations::run( self::OPTION, '0.3.0', $steps );
			$this->fail( 'The failure must not be swallowed.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'column already exists', $e->getMessage() );
		}

		$this->assertSame( '0.1.0', get_option( self::OPTION ) );
		$this->assertSame( [ '0.1.0' ], $this->calls );
		$this->assertFalse( get_option( self::OPTION . '_lock' ) );
	}

	public function test_a_run_already_in_progress_is_not_started_twice(): void {
		add_option( self::OPTION . '_lock', time() );

		$this->assertSame( [], Migrations::run( self::OPTION, '0.1.0', $this->steps( '0.1.0' ) ) );
		$this->assertSame( [], $this->calls );
	}

	public function test_a_lock_left_by_a_crashed_run_is_taken_over(): void {
		add_option( self::OPTION . '_lock', time() - HOUR_IN_SECONDS );

		$this->assertSame( [ '0.1.0' ], Migrations::run( self::OPTION, '0.1.0', $this->steps( '0.1.0' ) ) );
	}

	/** @return array<string, callable> */
	private function steps( string ...$versions ): array {
		$steps = [];
		foreach ( $versions as $version ) {
			$steps[ $version ] = function () use ( $version ): void {
				$this->calls[] = $version;
			};
		}

		return $steps;
	}
}
