<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog;

defined( 'ABSPATH' ) || exit;

use RuntimeException;

/**
 * Plugin metadata factory and service bootstrap (Variant C: standalone-minimal).
 */
final class Plugin {

	private static ?self $instance = null;

	private string $file;
	private string $path;
	private string $url;
	private string $basename;
	private string $textdomain;
	private string $key;
	private string $name;
	private string $version;
	private bool $booted = false;

	private function __construct( array $args ) {
		$this->file       = $args['file'];
		$this->path       = $args['path'];
		$this->url        = $args['url'];
		$this->basename   = $args['basename'];
		$this->textdomain = $args['textdomain'];
		$this->key        = $args['key'];
		$this->name       = $args['name'];
		$this->version    = $args['version'];

		add_action( 'init', [ $this, 'loadTextdomain' ] );
	}

	public static function bootstrap( string $file, array $args = [] ): void {
		if ( null !== self::$instance ) {
			return;
		}

		$args['file'] = $file;
		$args['path'] = untrailingslashit( plugin_dir_path( $file ) );
		$args['url']  = untrailingslashit( plugin_dir_url( $file ) );

		$args['basename']   = $args['basename'] ?? wp_basename( $args['path'] );
		$args['textdomain'] = $args['textdomain'] ?? wp_basename( $args['path'] );
		$args['key']        = sanitize_key( $args['name'] ?? $args['basename'] );
		$args['name']       = ucfirst( trim( $args['name'] ?? $args['basename'] ) );
		$args['version']    = $args['version'] ?? '1.0.0';

		self::$instance = new self( $args );
	}

	public function loadTextdomain(): void {
		load_plugin_textdomain( $this->textdomain, false, $this->basename . '/languages' );
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			throw new RuntimeException( 'Plugin not initialized. Call Plugin::bootstrap() first.' );
		}

		return self::$instance;
	}

	public static function file(): string {
		return self::get_instance()->file;
	}

	public static function basename(): string {
		return self::get_instance()->basename;
	}

	public static function textdomain(): string {
		return self::get_instance()->textdomain;
	}

	public static function key(): string {
		return self::get_instance()->key;
	}

	public static function name(): string {
		return self::get_instance()->name;
	}

	public static function version(): string {
		return self::get_instance()->version;
	}

	public static function path( string $append = '' ): string {
		$path = self::get_instance()->path;

		return $append ? trailingslashit( $path ) . $append : $path;
	}

	public static function url( string $append = '' ): string {
		$url = self::get_instance()->url;

		return $append ? trailingslashit( $url ) . $append : $url;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Admin\Page::instance()->register();
		Admin\Dashboard_Widget::instance()->register();
		Admin\Subscription::instance()->register();
		Notify\Notifier::instance()->register();
		Cli\Commands::instance()->register();
	}
}
