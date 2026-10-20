<?php
namespace BP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the Logger writes instead of its real destinations (see Logger::use_sink()).
 */
interface Log_Sink {

	/** @param array<string, mixed> $record A diagnostic or error line. */
	public function line( array $record ): void;

	/** @param array<string, mixed> $record An audit row. */
	public function audit( array $record ): void;
}
