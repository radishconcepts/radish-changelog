<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;
use RuntimeException;

/**
 * Thin wrapper around the `git` binary. This is the only class in the
 * plugin allowed to spawn a process: every call goes through proc_open()
 * with an argument array (never a shell string, never
 * shell_exec()/exec()/system()), runs with cwd = the repo root, is bounded
 * by a timeout, and raises a RuntimeException carrying git's stderr on a
 * non-zero exit.
 *
 * Ref and range arguments are validated before they reach git: a value
 * that does not look like a plausible ref (and in particular, anything
 * starting with "-") is rejected up front so it can never be interpreted
 * as a git option instead of a ref.
 */
final class Git {

	private const TIMEOUT_SECONDS = 30;
	private const POLL_MICROSECONDS = 100000;

	public function __construct(
		private readonly string $repo_root,
		private readonly string $binary = 'git',
	) {}

	/**
	 * Runs `git <args>` with cwd = the repo root and returns trimmed stdout.
	 *
	 * @param string[] $args
	 * @throws RuntimeException When git cannot be started, times out, or exits non-zero; the message always includes git's stderr.
	 */
	public function run( array $args ): string {
		$command = array_merge( [ $this->binary ], $args );

		$descriptors = [
			0 => [ 'pipe', 'r' ],
			1 => [ 'pipe', 'w' ],
			2 => [ 'pipe', 'w' ],
		];

		$process = proc_open( $command, $descriptors, $pipes, $this->repo_root, null, [ 'bypass_shell' => true ] );

		if ( false === $process || ! is_resource( $process ) ) {
			throw new RuntimeException( sprintf( 'Could not start "git %s".', implode( ' ', $args ) ) );
		}

		fclose( $pipes[0] );
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$stdout = '';
		$stderr = '';

		try {
			[ $stdout, $stderr ] = $this->collect_output( $process, $pipes, $args );
		} finally {
			// Runs on every path, including collect_output() throwing on a
			// timeout (which has already called proc_terminate() itself):
			// the pipes and the process are never left open.
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$exit_code = proc_close( $process );
		}

		if ( 0 !== $exit_code ) {
			throw new RuntimeException( sprintf(
				'git %s failed (exit %d): %s',
				implode( ' ', $args ),
				$exit_code,
				trim( $stderr )
			) );
		}

		return trim( $stdout );
	}

	/**
	 * @param resource $process
	 * @param array<int, resource> $pipes
	 * @param string[] $args
	 * @return array{0: string, 1: string} [ stdout, stderr ]
	 * @throws RuntimeException On timeout; the process is terminated first.
	 */
	private function collect_output( $process, array $pipes, array $args ): array {
		$stdout   = '';
		$stderr   = '';
		$deadline = microtime( true ) + self::TIMEOUT_SECONDS;

		while ( true ) {
			$read    = [ $pipes[1], $pipes[2] ];
			$write   = null;
			$except  = null;
			$changed = stream_select( $read, $write, $except, 0, self::POLL_MICROSECONDS );

			if ( false !== $changed && $changed > 0 ) {
				foreach ( $read as $stream ) {
					$chunk = stream_get_contents( $stream );
					if ( false === $chunk || '' === $chunk ) {
						continue;
					}
					if ( $stream === $pipes[1] ) {
						$stdout .= $chunk;
					} else {
						$stderr .= $chunk;
					}
				}
			}

			$status = proc_get_status( $process );

			if ( ! $status['running'] ) {
				break;
			}

			if ( microtime( true ) > $deadline ) {
				proc_terminate( $process );
				throw new RuntimeException( sprintf(
					'git %s timed out after %d seconds.',
					implode( ' ', $args ),
					self::TIMEOUT_SECONDS
				) );
			}
		}

		// Drain anything left in the buffers after the process exited.
		$stdout .= (string) stream_get_contents( $pipes[1] );
		$stderr .= (string) stream_get_contents( $pipes[2] );

		return [ $stdout, $stderr ];
	}

	/**
	 * The file content at <ref>:<path>.
	 *
	 * @throws RuntimeException When $ref does not resolve or $path does not exist at $ref.
	 */
	public function show( string $ref, string $path ): string {
		$this->assert_safe_ref( $ref );
		$this->assert_safe_relative_path( $path );

		return $this->run( [ 'show', $ref . ':' . $path ] );
	}

	/**
	 * Directory names directly inside $dir at $ref: `git ls-tree <ref>
	 * <dir>/`, filtered to tree entries only.
	 *
	 * @return string[]
	 */
	public function ls_tree( string $ref, string $dir ): array {
		return $this->list_entries( $ref, $dir, 'tree' );
	}

	/**
	 * File names directly inside $dir at $ref: `git ls-tree <ref> <dir>/`,
	 * filtered to blob entries only.
	 *
	 * @return string[]
	 */
	public function ls_tree_files( string $ref, string $dir ): array {
		return $this->list_entries( $ref, $dir, 'blob' );
	}

	/**
	 * @return string[]
	 */
	private function list_entries( string $ref, string $dir, string $type ): array {
		$this->assert_safe_ref( $ref );
		$this->assert_safe_relative_path( $dir );

		$normalized = rtrim( $dir, '/' ) . '/';
		$output     = $this->run( [ 'ls-tree', $ref, $normalized ] );

		if ( '' === $output ) {
			return [];
		}

		$names = [];

		foreach ( preg_split( '/\r\n|\r|\n/', $output ) as $line ) {
			if ( '' === $line ) {
				continue;
			}

			// Format: "<mode> <type> <sha>\t<path>".
			if ( 1 !== preg_match( '/^\d+\s+(blob|tree|commit)\s+[0-9a-f]+\t(.+)$/', $line, $matches ) ) {
				continue;
			}

			if ( $matches[1] !== $type ) {
				continue;
			}

			$names[] = basename( $matches[2] );
		}

		return $names;
	}

	/**
	 * Commit subjects (+ bodies) for a revision range, e.g. "origin/base..origin/from".
	 *
	 * @return string[]
	 */
	public function log_subjects( string $range ): array {
		$this->assert_safe_range( $range );

		$output = $this->run( [ 'log', '--format=%s%n%b%x00', $range ] );

		if ( '' === $output ) {
			return [];
		}

		$entries = array_map( 'trim', explode( "\0", $output ) );

		return array_values( array_filter( $entries, static fn( string $entry ): bool => '' !== $entry ) );
	}

	/**
	 * Whether the working tree is clean. Untracked files are ignored
	 * (`--untracked-files=no`): only CHANGELOG.md is ever `git add`ed for a
	 * release commit, so an untracked file elsewhere in the tree cannot leak
	 * into it, and treating it as "dirty" would block a release on e.g. a
	 * freshly generated, gitignored composer.lock.
	 */
	public function is_clean(): bool {
		return '' === $this->run( [ 'status', '--porcelain', '--untracked-files=no' ] );
	}

	/**
	 * Whether $ref resolves (`git rev-parse --verify --quiet <ref>`),
	 * without raising on a missing ref.
	 */
	public function ref_exists( string $ref ): bool {
		$this->assert_safe_ref( $ref );

		try {
			$this->run( [ 'rev-parse', '--verify', '--quiet', $ref ] );

			return true;
		} catch ( RuntimeException ) {
			return false;
		}
	}

	/**
	 * Whether $ref is a valid branch name per `git check-ref-format
	 * --branch`. Does not raise when the format is invalid, mirroring
	 * ref_exists()'s "ask, don't throw" shape so the caller can report a
	 * clean error instead of catching an exception for an expected outcome.
	 */
	public function check_ref_format( string $ref ): bool {
		$this->assert_safe_ref( $ref );

		try {
			$this->run( [ 'check-ref-format', '--branch', $ref ] );

			return true;
		} catch ( RuntimeException ) {
			return false;
		}
	}

	/**
	 * `git fetch <remote>`.
	 */
	public function fetch( string $remote ): void {
		$this->assert_safe_ref( $remote );

		$this->run( [ 'fetch', $remote ] );
	}

	/**
	 * `git checkout -b <branch> <start_point>`.
	 */
	public function create_branch( string $branch, string $start_point ): void {
		$this->assert_safe_ref( $branch );
		$this->assert_safe_ref( $start_point );

		$this->run( [ 'checkout', '-b', $branch, $start_point ] );
	}

	/**
	 * `git add <paths...>`.
	 *
	 * @param string[] $paths
	 */
	public function add( array $paths ): void {
		foreach ( $paths as $path ) {
			$this->assert_safe_relative_path( $path );
		}

		$this->run( array_merge( [ 'add', '--' ], $paths ) );
	}

	/**
	 * `git commit -m <message>`.
	 */
	public function commit( string $message ): void {
		$this->run( [ 'commit', '-m', $message ] );
	}

	/**
	 * `git push -u <remote> <branch>`.
	 */
	public function push( string $remote, string $branch ): void {
		$this->assert_safe_ref( $remote );
		$this->assert_safe_ref( $branch );

		$this->run( [ 'push', '-u', $remote, $branch ] );
	}

	/**
	 * `git rev-parse --show-toplevel`, trimmed.
	 */
	public function toplevel(): string {
		return $this->run( [ 'rev-parse', '--show-toplevel' ] );
	}

	/**
	 * `git rev-parse --abbrev-ref HEAD`: the current branch name ("HEAD"
	 * in detached-HEAD state). Used to tell a caller which branch to
	 * return to if something fails after it has checked out a new one.
	 */
	public function current_branch(): string {
		return $this->run( [ 'rev-parse', '--abbrev-ref', 'HEAD' ] );
	}

	/**
	 * Rejects anything that is not a plausible git ref: empty, a leading
	 * "-" (which git would read as an option rather than a ref), or
	 * characters check-ref-format forbids.
	 *
	 * @throws InvalidArgumentException When $ref is not safe to pass to git.
	 */
	private function assert_safe_ref( string $ref ): void {
		if (
			'' === $ref
			|| str_contains( $ref, '..' )
			|| str_contains( $ref, '//' )
			|| 1 !== preg_match( '#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $ref )
		) {
			throw new InvalidArgumentException( sprintf( 'Refusing unsafe git ref "%s".', $ref ) );
		}
	}

	/**
	 * A revision range such as "origin/base..origin/from": split on the
	 * (one or two) dots and validate each side as a ref.
	 *
	 * @throws InvalidArgumentException When $range is not a plausible two-sided range.
	 */
	private function assert_safe_range( string $range ): void {
		$parts = preg_split( '/\.\.\.?/', $range );

		if ( false === $parts || 2 !== count( $parts ) ) {
			throw new InvalidArgumentException( sprintf( 'Refusing unsafe git range "%s".', $range ) );
		}

		foreach ( $parts as $part ) {
			$this->assert_safe_ref( $part );
		}
	}

	/**
	 * Rejects a relative path that is empty, absolute, or starts with "-"
	 * (which git would otherwise read as an option).
	 *
	 * @throws InvalidArgumentException When $path is not safe to pass to git.
	 */
	private function assert_safe_relative_path( string $path ): void {
		if ( '' === $path || str_starts_with( $path, '-' ) || str_starts_with( $path, '/' ) ) {
			throw new InvalidArgumentException( sprintf( 'Refusing unsafe path "%s".', $path ) );
		}
	}
}
