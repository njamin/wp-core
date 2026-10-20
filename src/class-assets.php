<?php
namespace BP\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one registry of a plugin's scripts and styles (DESIGN §4.4.2, §4.6.1).
 *
 * Every asset is self-hosted and registered here once; a module only enqueues the handle
 * on the screens that use it. The plugin version is the cache key, so any asset change
 * bumps the version. Vendor files carry their own version in the file name.
 *
 *     $assets = new Assets( 'acme', plugin_dir_url( FILE ), plugin_dir_path( FILE ), ACME_VERSION );
 *     $assets->vendor_script( 'alpinejs', 'alpine.3.15.12.min.js' );
 *     $assets->script( 'bookings', [ 'acme-vendor-alpinejs' ] );   // assets/js/acme-bookings.js
 *     $assets->register();
 */
final class Assets {

	/** @var list<array{type: string, handle: string, path: string, deps: list<string>, footer: bool}> */
	private array $assets = [];

	public function __construct(
		private readonly string $prefix,
		private readonly string $base_url,
		private readonly string $base_dir,
		private readonly string $version,
	) {}

	/** @param list<string> $deps */
	public function script( string $name, array $deps = [] ): self {
		return $this->add( 'script', "{$this->prefix}-{$name}", "assets/js/{$this->prefix}-{$name}.js", $deps );
	}

	/** @param list<string> $deps */
	public function style( string $name, array $deps = [] ): self {
		return $this->add( 'style', "{$this->prefix}-{$name}", "assets/css/{$this->prefix}-{$name}.css", $deps );
	}

	/** @param list<string> $deps */
	public function vendor_script( string $lib, string $file, array $deps = [] ): self {
		return $this->add( 'script', "{$this->prefix}-vendor-{$lib}", "assets/vendor/{$file}", $deps );
	}

	/** @param list<string> $deps */
	public function vendor_style( string $lib, string $file, array $deps = [] ): self {
		return $this->add( 'style', "{$this->prefix}-vendor-{$lib}", "assets/vendor/{$file}", $deps );
	}

	public function register(): void {
		$callback = function (): void {
			foreach ( $this->assets as $asset ) {
				if ( ! file_exists( $this->base_dir . $asset['path'] ) ) {
					Logger::warning( 'Registered asset is missing', [ 'handle' => $asset['handle'], 'path' => $asset['path'] ] );
					continue;
				}

				$url = $this->base_url . $asset['path'];
				if ( 'script' === $asset['type'] ) {
					wp_register_script( $asset['handle'], $url, $asset['deps'], $this->version, [ 'in_footer' => $asset['footer'] ] );
				} else {
					wp_register_style( $asset['handle'], $url, $asset['deps'], $this->version );
				}
			}
		};

		add_action( 'wp_enqueue_scripts', $callback, 1 );
		add_action( 'admin_enqueue_scripts', $callback, 1 );
		add_action( 'login_enqueue_scripts', $callback, 1 );
	}

	/** @param list<string> $deps */
	private function add( string $type, string $handle, string $path, array $deps ): self {
		$this->assets[] = [ 'type' => $type, 'handle' => $handle, 'path' => $path, 'deps' => $deps, 'footer' => true ];

		return $this;
	}
}
