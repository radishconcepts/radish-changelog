<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Versions;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Changelog\Release;

/**
 * Diffs two Inventory arrays (see Inventory::at_ref() / Inventory::live())
 * into what changed: core, updated/added/removed plugins. Pure PHP.
 */
final class Version_Diff {

	/**
	 * @param array{core: string, plugins: array<string, array{name: string, version: string, author: string}>} $base
	 * @param array{core: string, plugins: array<string, array{name: string, version: string, author: string}>} $head
	 * @return array{
	 *     core: array{0: string, 1: string}|null,
	 *     updated: array<string, array{0: string, 1: string}>,
	 *     added: array<string, array{name: string, version: string}>,
	 *     removed: array<string, array{name: string, version: string}>
	 * }
	 */
	public static function between( array $base, array $head, bool $include_patch ): array {
		return [
			'core'    => self::core_diff( $base['core'], $head['core'] ),
			'updated' => self::updated( $base['plugins'], $head['plugins'], $include_patch ),
			'added'   => self::added( $base['plugins'], $head['plugins'] ),
			'removed' => self::removed( $base['plugins'], $head['plugins'] ),
		];
	}

	/**
	 * @return array{0: string, 1: string}|null
	 */
	private static function core_diff( string $base_core, string $head_core ): ?array {
		if ( $base_core === $head_core ) {
			return null;
		}

		return [ $base_core, $head_core ];
	}

	/**
	 * @param array<string, array{name: string, version: string, author: string}> $base_plugins
	 * @param array<string, array{name: string, version: string, author: string}> $head_plugins
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function updated( array $base_plugins, array $head_plugins, bool $include_patch ): array {
		$updated = [];

		foreach ( $head_plugins as $slug => $head_plugin ) {
			if ( ! array_key_exists( $slug, $base_plugins ) ) {
				continue;
			}

			$old_version = $base_plugins[ $slug ]['version'];
			$new_version = $head_plugin['version'];

			if ( $old_version === $new_version ) {
				continue;
			}

			if ( ! $include_patch && Release::is_patch_bump( $old_version, $new_version ) ) {
				continue;
			}

			$updated[ $slug ] = [ $old_version, $new_version ];
		}

		return $updated;
	}

	/**
	 * @param array<string, array{name: string, version: string, author: string}> $base_plugins
	 * @param array<string, array{name: string, version: string, author: string}> $head_plugins
	 * @return array<string, array{name: string, version: string}>
	 */
	private static function added( array $base_plugins, array $head_plugins ): array {
		$added = [];

		foreach ( $head_plugins as $slug => $head_plugin ) {
			if ( ! array_key_exists( $slug, $base_plugins ) ) {
				$added[ $slug ] = [
					'name'    => $head_plugin['name'],
					'version' => $head_plugin['version'],
				];
			}
		}

		return $added;
	}

	/**
	 * @param array<string, array{name: string, version: string, author: string}> $base_plugins
	 * @param array<string, array{name: string, version: string, author: string}> $head_plugins
	 * @return array<string, array{name: string, version: string}>
	 */
	private static function removed( array $base_plugins, array $head_plugins ): array {
		$removed = [];

		foreach ( $base_plugins as $slug => $base_plugin ) {
			if ( ! array_key_exists( $slug, $head_plugins ) ) {
				$removed[ $slug ] = [
					'name'    => $base_plugin['name'],
					'version' => $base_plugin['version'],
				];
			}
		}

		return $removed;
	}

	private function __construct() {}
}
