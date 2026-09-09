<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;
use RadishConcepts\Changelog\Changelog\File;
use RadishConcepts\Changelog\Changelog\Parser;
use RadishConcepts\Changelog\Changelog\Release;
use RadishConcepts\Changelog\Changelog\Writer;
use RadishConcepts\Changelog\Config;
use RadishConcepts\Changelog\Git;
use RadishConcepts\Changelog\Plugin;
use RadishConcepts\Changelog\Versions\Inventory;
use RuntimeException;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp changelog init`.
 */
final class Init_Command {

	private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._-]{0,63}$/';

	/**
	 * Writes the baseline entry into CHANGELOG.md: WordPress core and every
	 * third-party plugin's version at a git ref, so later releases have a
	 * starting point to diff against.
	 *
	 * Refuses when CHANGELOG.md already contains one or more releases —
	 * this command is meant to run exactly once per project.
	 *
	 * ## OPTIONS
	 *
	 * [--name=<name>]
	 * : Heading for the entry. Letters, digits, spaces, dots, underscores
	 * and hyphens only, up to 64 characters.
	 * ---
	 * default: Baseline
	 * ---
	 *
	 * [--ref=<ref>]
	 * : Git ref to read WordPress and plugin versions from. Defaults to
	 * origin/<base>, using branches.base from changelog.json ("master"
	 * when changelog.json is absent).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp changelog init
	 *     wp changelog init --name="Baseline" --ref=origin/master --yes
	 *
	 * @param array<int, string> $args
	 * @param array<string, string> $assoc_args
	 */
	public function run( array $args, array $assoc_args ): void {
		$name = (string) Utils\get_flag_value( $assoc_args, 'name', __( 'Baseline', Plugin::textdomain() ) );

		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			WP_CLI::error( sprintf(
				'Invalid --name "%s": use only letters, digits, spaces, dots, underscores and hyphens (max 64 characters).',
				$name
			) );
			return;
		}

		try {
			$toplevel = ( new Git( ABSPATH ) )->toplevel();
		} catch ( RuntimeException $exception ) {
			WP_CLI::error( sprintf( 'Could not determine the repository root: %s', $exception->getMessage() ) );
			return;
		}

		// Pathspecs passed to git (ls-tree, show) resolve relative to cwd,
		// and Config::relative() below produces paths relative to the repo
		// root — so every further git call must run with cwd = repo root,
		// not ABSPATH (a subdirectory of it).
		$git = new Git( $toplevel );

		$located = Changelog_Location::locate( $toplevel );
		if ( null === $located ) {
			return;
		}
		[ $changelog_path, $repo_root, $config ] = $located;

		$existing_markdown = is_readable( $changelog_path ) ? file_get_contents( $changelog_path ) : false;
		if ( false !== $existing_markdown && [] !== Parser::parse( $existing_markdown ) ) {
			WP_CLI::error( sprintf(
				'%s already contains one or more releases; wp changelog init only creates the baseline entry once.',
				$changelog_path
			) );
			return;
		}

		$ref = (string) Utils\get_flag_value( $assoc_args, 'ref', 'origin/' . $config['branches']['base'] );

		try {
			$ref_exists = $git->ref_exists( $ref );
		} catch ( InvalidArgumentException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return;
		}

		if ( ! $ref_exists ) {
			WP_CLI::error( sprintf( 'Git ref "%s" does not resolve.', $ref ) );
			return;
		}

		try {
			$plugins_rel = Config::relative( WP_PLUGIN_DIR, $repo_root );
			$core_rel    = Config::relative( ABSPATH . 'wp-includes/version.php', $repo_root );
		} catch ( InvalidArgumentException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return;
		}

		try {
			$inventory = Inventory::at_ref( $git, $ref, $plugins_rel, $core_rel, $config['own_authors'] );
		} catch ( RuntimeException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return;
		}

		$release = self::build_baseline_release( $name, $inventory );
		$block   = Writer::render( $release );

		WP_CLI::log( $block );
		WP_CLI::log( sprintf( 'Target file: %s', $changelog_path ) );

		WP_CLI::confirm( sprintf( 'Write this "%s" entry to %s?', $name, $changelog_path ), $assoc_args );

		try {
			( new File( $changelog_path ) )->prepend( $block );
		} catch ( RuntimeException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return;
		}

		WP_CLI::success( sprintf( 'Wrote "%s" to %s.', $name, $changelog_path ) );
	}

	/**
	 * Builds the "Inventory" release: WordPress core first, then every
	 * plugin sorted by display name (not slug).
	 *
	 * @param array{core: string, plugins: array<string, array{name: string, version: string, author: string}>} $inventory
	 */
	private static function build_baseline_release( string $name, array $inventory ): Release {
		$plugins = $inventory['plugins'];
		uasort( $plugins, static fn( array $a, array $b ): int => strnatcasecmp( $a['name'], $b['name'] ) );

		$items = [
			[ 'text' => 'WordPress: ' . $inventory['core'], 'key' => null, 'url' => null ],
		];

		foreach ( $plugins as $plugin ) {
			$items[] = [ 'text' => $plugin['name'] . ': ' . $plugin['version'], 'key' => null, 'url' => null ];
		}

		return new Release( $name, current_time( 'Y-m-d' ), [], [ 'inventory' => $items ] );
	}
}
