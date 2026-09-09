<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Versions;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Git;

/**
 * WordPress core and plugin version inventory.
 *
 * live() reads the running site (get_plugins(), get_bloginfo()) for the
 * "Current versions" table on the admin page.
 *
 * at_ref() reads the same shape from a git ref, via Git + Header_Parser,
 * for the release engine.
 */
final class Inventory {

	/**
	 * The live inventory of the running site: the WordPress core version
	 * and every installed plugin's Name/Version/Author, excluding plugins
	 * whose Author header contains one of $own_authors (case-insensitive
	 * substring match).
	 *
	 * @param string[] $own_authors
	 * @return array{core: string, plugins: array<string, array{name: string, version: string, author: string}>}
	 */
	public static function live( array $own_authors ): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = [];

		foreach ( get_plugins() as $plugin_file => $data ) {
			$author = (string) ( $data['Author'] ?? '' );

			if ( self::is_own_author( $author, $own_authors ) ) {
				continue;
			}

			$plugins[ self::slug( $plugin_file ) ] = [
				'name'    => (string) ( $data['Name'] ?? '' ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'author'  => $author,
			];
		}

		ksort( $plugins );

		return [
			'core'    => get_bloginfo( 'version' ),
			'plugins' => $plugins,
		];
	}

	/**
	 * The inventory at a specific git ref: the WordPress core version from
	 * $core_rel, and every plugin folder directly under $plugins_rel with
	 * its Name/Version/Author, excluding plugins whose Author header
	 * contains one of $own_authors (case-insensitive substring match, same
	 * rule as live()).
	 *
	 * @param string[] $own_authors
	 * @return array{core: string, plugins: array<string, array{name: string, version: string, author: string}>}
	 * @throws \RuntimeException When $ref does not resolve (propagated from Git, with git's stderr).
	 */
	public static function at_ref( Git $git, string $ref, string $plugins_rel, string $core_rel, array $own_authors ): array {
		$core = self::parse_core_version( $git->show( $ref, $core_rel ) );

		$plugins = [];

		foreach ( $git->ls_tree( $ref, $plugins_rel ) as $slug ) {
			$header = self::header_for_plugin( $git, $ref, $plugins_rel, $slug );

			if ( null === $header ) {
				continue;
			}

			$author = $header['Author'];

			if ( self::is_own_author( $author, $own_authors ) ) {
				continue;
			}

			$plugins[ $slug ] = [
				'name'    => $header['Name'],
				'version' => $header['Version'],
				'author'  => $author,
			];
		}

		ksort( $plugins );

		return [
			'core'    => $core,
			'plugins' => $plugins,
		];
	}

	/**
	 * The first .php file at the top level of $plugins_rel/$slug at $ref
	 * whose header has a "Plugin Name" (WordPress convention: multiple
	 * top-level .php files may exist, only one carries the header).
	 *
	 * @return array{Name: string, Version: string, Author: string}|null
	 */
	private static function header_for_plugin( Git $git, string $ref, string $plugins_rel, string $slug ): ?array {
		$plugin_dir = rtrim( $plugins_rel, '/' ) . '/' . $slug;

		foreach ( $git->ls_tree_files( $ref, $plugin_dir ) as $file ) {
			if ( '.php' !== strtolower( (string) substr( $file, -4 ) ) ) {
				continue;
			}

			$source = $git->show( $ref, $plugin_dir . '/' . $file );
			$header = Header_Parser::parse( $source );

			if ( null !== $header ) {
				return $header;
			}
		}

		return null;
	}

	/**
	 * The WordPress core version from wp-includes/version.php source
	 * ($wp_version = '…';). Empty string when the pattern is not found.
	 */
	private static function parse_core_version( string $version_php_source ): string {
		if ( 1 === preg_match( '/\$wp_version\s*=\s*\'([^\']+)\'/', $version_php_source, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	/**
	 * Case-insensitive substring match of $author against each $own_authors
	 * entry (Q18: "eigen" = Author header contains one of own_authors).
	 *
	 * @param string[] $own_authors
	 */
	private static function is_own_author( string $author, array $own_authors ): bool {
		foreach ( $own_authors as $own_author ) {
			if ( '' !== $own_author && false !== stripos( $author, (string) $own_author ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The plugin folder name, e.g. "gravityforms" from
	 * "gravityforms/gravityforms.php".
	 */
	private static function slug( string $plugin_file ): string {
		$parts = explode( '/', $plugin_file, 2 );

		return $parts[0];
	}

	private function __construct() {}
}
