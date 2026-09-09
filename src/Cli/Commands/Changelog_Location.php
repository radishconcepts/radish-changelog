<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;
use RadishConcepts\Changelog\Config;
use WP_CLI;

/**
 * Locates CHANGELOG.md (or its default not-yet-existing path when none
 * exists yet) and the merged changelog.json configuration. Shared by
 * Init_Command and Release_Command.
 */
final class Changelog_Location {

	/**
	 * Reports its own WP_CLI::error() and returns null on failure, so
	 * callers can just `return` when this returns null.
	 *
	 * @return array{0: string, 1: string, 2: array<string, mixed>}|null [ changelog_path, repo_root, config ]
	 */
	public static function locate( string $toplevel ): ?array {
		$changelog_path = Config::locate( ABSPATH );

		if ( null !== $changelog_path ) {
			$repo_root = Config::repo_root( $changelog_path );

			if ( ! Config::repo_root_matches( $repo_root, $toplevel ) ) {
				WP_CLI::error( sprintf(
					'CHANGELOG.md at "%s" is outside the git repository at "%s".',
					$repo_root,
					$toplevel
				) );
				return null;
			}
		} else {
			$repo_root      = $toplevel;
			$changelog_path = rtrim( $repo_root, '/\\' ) . '/CHANGELOG.md';
		}

		try {
			$config = Config::load( Config::json_path_for( $changelog_path ) );
		} catch ( InvalidArgumentException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return null;
		}

		return [ $changelog_path, $repo_root, $config ];
	}

	private function __construct() {}
}
