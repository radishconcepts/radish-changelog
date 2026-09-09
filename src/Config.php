<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Locates CHANGELOG.md and loads/validates changelog.json.
 *
 * Pure PHP: no WordPress functions are called from methods used by the
 * unit tests (locate(), load()), so this class stays testable without a
 * WordPress bootstrap. Where a WP helper is genuinely nicer (wp_parse_url),
 * it is used only when available and falls back to plain PHP otherwise.
 */
final class Config {

	private const MAX_LEVELS = 3;
	private const FILENAME   = 'CHANGELOG.md';

	/**
	 * Defaults and full key set — the contract other projects configure against.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULTS = [
		'branches'    => [
			'remote'         => 'origin',
			'from'           => 'develop',
			'base'           => 'master',
			'release_prefix' => 'release/',
		],
		'jira'        => [
			'base_url' => 'https://radishconcepts.atlassian.net',
			'project'  => 'KFM',
		],
		'own_authors' => [ 'Radish Concepts' ],
		'updates'     => [ 'include_patch' => true ],
		'notify'      => [ 'environments' => [ 'production' ] ],
	];

	/**
	 * Finds CHANGELOG.md.
	 *
	 * If the RADISH_CHANGELOG_FILE constant is defined and readable, that
	 * path wins (after realpath()). Otherwise searches upward from
	 * $start_dir, at most self::MAX_LEVELS parent directories, for the
	 * first CHANGELOG.md. Returns null when nothing is found.
	 */
	public static function locate( string $start_dir ): ?string {
		if ( defined( 'RADISH_CHANGELOG_FILE' ) && is_readable( (string) constant( 'RADISH_CHANGELOG_FILE' ) ) ) {
			$real = realpath( (string) constant( 'RADISH_CHANGELOG_FILE' ) );
			if ( false !== $real ) {
				return $real;
			}
		}

		$dir = rtrim( $start_dir, '/\\' );

		for ( $level = 0; $level <= self::MAX_LEVELS; $level++ ) {
			$candidate = $dir . '/' . self::FILENAME;
			if ( is_readable( $candidate ) ) {
				$real = realpath( $candidate );
				if ( false !== $real ) {
					return $real;
				}
			}

			$parent = dirname( $dir );
			if ( $parent === $dir ) {
				break;
			}
			$dir = $parent;
		}

		return null;
	}

	/**
	 * The repo root: the directory containing the located CHANGELOG.md.
	 *
	 * This is a pure path operation and never spawns git; call
	 * repo_root_matches() alongside it (with Git::toplevel()'s result) when
	 * the caller needs to be sure the two agree.
	 */
	public static function repo_root( string $changelog_path ): string {
		return dirname( $changelog_path );
	}

	/**
	 * Whether $repo_root (from repo_root(), above) matches git's own idea
	 * of the repo root. Pure comparison: this never spawns git itself —
	 * pass in the result of Git::toplevel() from the caller (the CLI), so
	 * Config stays git-free at page-render time.
	 */
	public static function repo_root_matches( string $repo_root, string $git_toplevel ): bool {
		$repo_root_real = realpath( $repo_root );
		$toplevel_real  = realpath( $git_toplevel );

		return false !== $repo_root_real && false !== $toplevel_real && $repo_root_real === $toplevel_real;
	}

	/**
	 * The path of $abs relative to $root, e.g. "app/www/wp-content/plugins"
	 * for $abs = WP_PLUGIN_DIR and $root = the repo root. Realpath-based so
	 * symlinks and ".." resolve before the comparison (filesystem trust
	 * boundary: a prefix compare on the resolved paths, with a trailing
	 * separator on both sides, so "/root-evil" cannot match a "/root" root).
	 *
	 * @throws InvalidArgumentException When either path does not resolve, or $abs lies outside $root.
	 */
	public static function relative( string $abs, string $root ): string {
		$abs_real  = realpath( $abs );
		$root_real = realpath( $root );

		if ( false === $abs_real ) {
			throw new InvalidArgumentException( sprintf( 'Path "%s" does not exist.', $abs ) );
		}

		if ( false === $root_real ) {
			throw new InvalidArgumentException( sprintf( 'Repo root "%s" does not exist.', $root ) );
		}

		$root_stripped = rtrim( $root_real, '/\\' );

		if ( $abs_real === $root_stripped ) {
			return '';
		}

		$root_prefix = $root_stripped . DIRECTORY_SEPARATOR;

		if ( ! str_starts_with( $abs_real . DIRECTORY_SEPARATOR, $root_prefix ) ) {
			throw new InvalidArgumentException( sprintf( 'Path "%s" lies outside repo root "%s".', $abs_real, $root_real ) );
		}

		return str_replace( '\\', '/', substr( $abs_real, strlen( $root_prefix ) ) );
	}

	/**
	 * Loads defaults, overridden by changelog.json when given and readable.
	 *
	 * @return array<string, mixed>
	 * @throws InvalidArgumentException On invalid JSON or wrong types; the file name and offending key are always in the message.
	 */
	public static function load( ?string $json_path ): array {
		$config = self::DEFAULTS;

		if ( null !== $json_path && is_readable( $json_path ) ) {
			$contents = file_get_contents( $json_path );
			if ( false === $contents ) {
				throw new InvalidArgumentException( sprintf( 'Could not read %s.', $json_path ) );
			}

			$decoded = json_decode( $contents, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				throw new InvalidArgumentException( sprintf( 'Invalid JSON in %s: %s', $json_path, json_last_error_msg() ) );
			}

			$config = self::merge( $config, $decoded, $json_path );
		}

		self::validate( $config, $json_path ?? '(defaults)' );

		return $config;
	}

	/**
	 * @param array<string, mixed> $defaults
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private static function merge( array $defaults, array $overrides, string $source ): array {
		foreach ( $overrides as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				throw new InvalidArgumentException( sprintf( 'Unknown key "%s" in %s.', $key, $source ) );
			}

			if ( is_array( $defaults[ $key ] ) && self::is_assoc( $defaults[ $key ] ) ) {
				if ( ! is_array( $value ) ) {
					throw new InvalidArgumentException( sprintf( 'Key "%s" in %s must be an object.', $key, $source ) );
				}
				$defaults[ $key ] = self::merge( $defaults[ $key ], $value, $source );
				continue;
			}

			$defaults[ $key ] = $value;
		}

		return $defaults;
	}

	private static function is_assoc( array $array ): bool {
		return $array !== array_values( $array );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private static function validate( array $config, string $source ): void {
		self::validate_branches( $config, $source );

		$base_url = $config['jira']['base_url'] ?? null;
		if ( ! is_string( $base_url ) ) {
			throw new InvalidArgumentException( sprintf( 'Key "jira.base_url" in %s must be a string.', $source ) );
		}

		$parsed = self::parse_url( $base_url );
		if ( null === $parsed || 'https' !== ( $parsed['scheme'] ?? '' ) || empty( $parsed['host'] ) ) {
			throw new InvalidArgumentException( sprintf( 'Key "jira.base_url" in %s must be an https:// URL with only a scheme and host.', $source ) );
		}

		foreach ( $parsed as $part => $value ) {
			if ( in_array( $part, [ 'scheme', 'host' ], true ) ) {
				continue;
			}
			if ( '' === $value || null === $value ) {
				continue;
			}
			throw new InvalidArgumentException( sprintf( 'Key "jira.base_url" in %s must contain only a scheme and host (found "%s").', $source, $part ) );
		}

		$project = $config['jira']['project'] ?? null;
		if ( ! is_string( $project ) || 1 !== preg_match( '/^[A-Z][A-Z0-9]+$/', $project ) ) {
			throw new InvalidArgumentException( sprintf( 'Key "jira.project" in %s must match /^[A-Z][A-Z0-9]+$/.', $source ) );
		}

		if ( ! is_array( $config['own_authors'] ?? null ) ) {
			throw new InvalidArgumentException( sprintf( 'Key "own_authors" in %s must be an array.', $source ) );
		}

		if ( ! is_bool( $config['updates']['include_patch'] ?? null ) ) {
			throw new InvalidArgumentException( sprintf( 'Key "updates.include_patch" in %s must be a boolean.', $source ) );
		}

		if ( ! is_array( $config['notify']['environments'] ?? null ) ) {
			throw new InvalidArgumentException( sprintf( 'Key "notify.environments" in %s must be an array.', $source ) );
		}
	}

	/**
	 * branches.remote/from/base/release_prefix are shelled out to git and
	 * used to build a branch name (Cli\Commands\Release_Command), so a bad
	 * value here must fail as a clean InvalidArgumentException rather than
	 * reach Git with something git would read as an option (a leading "-")
	 * or an empty ref.
	 *
	 * @param array<string, mixed> $config
	 */
	private static function validate_branches( array $config, string $source ): void {
		$branches = $config['branches'] ?? null;
		if ( ! is_array( $branches ) ) {
			throw new InvalidArgumentException( sprintf( 'Key "branches" in %s must be an object.', $source ) );
		}

		foreach ( [ 'remote', 'from', 'base' ] as $key ) {
			$value = $branches[ $key ] ?? null;
			if ( ! is_string( $value ) || '' === $value || str_starts_with( $value, '-' ) ) {
				throw new InvalidArgumentException( sprintf(
					'Key "branches.%s" in %s must be a non-empty string that does not start with "-".',
					$key,
					$source
				) );
			}
		}

		$release_prefix = $branches['release_prefix'] ?? null;
		if (
			! is_string( $release_prefix )
			|| '' === $release_prefix
			|| str_starts_with( $release_prefix, '-' )
			|| ! str_ends_with( $release_prefix, '/' )
		) {
			throw new InvalidArgumentException( sprintf(
				'Key "branches.release_prefix" in %s must be a non-empty string ending with "/" that does not start with "-".',
				$source
			) );
		}
	}

	/**
	 * The changelog.json path next to $changelog_path, or null when
	 * $changelog_path is null or no readable changelog.json sits beside it.
	 * Shared by every caller that needs to resolve "the changelog.json for
	 * this CHANGELOG.md" (Admin\Page, Notify\Notifier, and
	 * Cli\Commands\Changelog_Location, which still handles a load failure
	 * itself via WP_CLI::error() rather than calling for_changelog()).
	 */
	public static function json_path_for( ?string $changelog_path ): ?string {
		if ( null === $changelog_path ) {
			return null;
		}

		$candidate = dirname( $changelog_path ) . '/changelog.json';

		return is_readable( $candidate ) ? $candidate : null;
	}

	/**
	 * Merged config for the changelog.json next to $changelog_path, falling
	 * back to defaults when $changelog_path is null, changelog.json is
	 * missing/unreadable, or invalid. For contexts that must never fatal on
	 * a bad changelog.json (the admin page, the notifier): an invalid file
	 * is reported via error_log() instead of being surfaced to the visitor.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_changelog( ?string $changelog_path ): array {
		try {
			return self::load( self::json_path_for( $changelog_path ) );
		} catch ( InvalidArgumentException $exception ) {
			error_log( sprintf( '[radish-changelog] %s', $exception->getMessage() ) );

			return self::load( null );
		}
	}

	/**
	 * @return array<string, string>|null
	 */
	private static function parse_url( string $url ): ?array {
		if ( function_exists( 'wp_parse_url' ) ) {
			$parsed = wp_parse_url( $url );
		} else {
			$parsed = parse_url( $url );
		}

		if ( false === $parsed || ! is_array( $parsed ) ) {
			return null;
		}

		return $parsed;
	}

	private function __construct() {}
}
