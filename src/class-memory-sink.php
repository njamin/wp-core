<?php
namespace BP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps log records in memory, so a test can assert that an audit event was written or
 * that a secret was redacted.
 *
 *     $sink = new Memory_Sink();
 *     Logger::use_sink( $sink );
 *     … the code under test …
 *     Logger::use_sink( null );
 */
final class Memory_Sink implements Log_Sink {

	/** @var list<array<string, mixed>> */
	public array $lines = [];

	/** @var list<array<string, mixed>> */
	public array $audits = [];

	public function line( array $record ): void {
		$this->lines[] = $record;
	}

	public function audit( array $record ): void {
		$this->audits[] = $record;
	}
}
