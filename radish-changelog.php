<?php
/**
 * Plugin Name:       Radish Changelog
 * Plugin URI:        https://github.com/radishconcepts/radish-changelog
 * Description:       Release changelog from CHANGELOG.md: dashboard widget, admin page, release command and release e-mails.
 * Version:           1.0.1
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Radish Concepts
 * License:           GPL-2.0-or-later
 * Text Domain:       radish-changelog
 * Domain Path:       /languages
 */

declare( strict_types=1 );

namespace RadishConcepts\Changelog;

defined( 'ABSPATH' ) || exit;

$autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $autoload ) ) {
	require_once $autoload;
}

// Fallback PSR-4 autoloader so the plugin works without `composer install`
// (it has no runtime dependencies).
spl_autoload_register( static function ( string $class ): void {
	$prefix = __NAMESPACE__ . '\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

	if ( is_readable( $path ) ) {
		require_once $path;
	}
} );

Plugin::bootstrap(
	__FILE__,
	[
		'name'       => 'Radish Changelog',
		'version'    => '1.0.1',
		'textdomain' => 'radish-changelog',
	]
);

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::get_instance()->boot();
	}
);
