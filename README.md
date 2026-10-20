# njamin/wp-core

Shared machinery for WordPress plugins: structured logging with an audit trail, a versioned
migration runner, and an asset registry.

- **`Logger`:** `debug`, `info`, `warning` and `error` to the PHP error log as one JSON line per
  event, with a request id and secrets redacted; `audit()` for the events someone must be able to
  answer for later (who changed what), written to the audit table.
- **`Audit_Log`:** that table, its retention purge, IPs truncated to their network, and the
  personal-data exporter and eraser WordPress's privacy tools call (GDPR).
- **`Migrations`:** schema changes as named, versioned steps, run once each under a lock; the
  stored version only moves past a step that succeeded.
- **`Assets`:** scripts and styles registered by name from a plugin's `assets/` folder, versioned
  for cache busting.

## Requirements

WordPress 7.0 or later, PHP 8.3 or later.

## Install

```bash
composer require njamin/wp-core
```

```php
use BP\Core\Logger;
use BP\Core\Migrations;

Logger::configure( 'ACME', ACME_VERSION );
Logger::info( 'Booking confirmed', [ 'booking' => $id ] );

add_action( 'plugins_loaded', static function () {
	Migrations::run( 'acme_db_version', ACME_VERSION, [
		'1.2.0' => [ Install::class, 'add_bookings_table' ],
	] );
} );
```

## Tests

The integration suite boots a real WordPress: `BP_WP_LOAD=/path/to/wp-load.php vendor/bin/phpunit`.

## Security

Report a vulnerability privately through this repository's **Security → Report a
vulnerability**, not in a public issue.

## Licence

GPL-2.0-or-later. This repository is a read-only mirror: changes are made upstream and
published here with each release.
