<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Changelog;

defined( 'ABSPATH' ) || exit;

use RuntimeException;

/**
 * Reads and atomically writes CHANGELOG.md at a given path.
 */
final class File {

	private const HEADING = '# Changelog';

	public function __construct( private readonly string $path ) {}

	public function read(): string {
		if ( ! is_readable( $this->path ) ) {
			return '';
		}

		$contents = file_get_contents( $this->path );

		return false === $contents ? '' : $contents;
	}

	public function mtime(): ?int {
		if ( ! is_readable( $this->path ) ) {
			return null;
		}

		$mtime = filemtime( $this->path );

		return false === $mtime ? null : $mtime;
	}

	/**
	 * Prepends a rendered release block (see Writer::render()) after the H1.
	 * Creates the file with "# Changelog" when it does not exist yet.
	 * Refuses when a release with the same name already exists.
	 */
	public function prepend( string $block ): void {
		$incoming_name = self::first_release_name( $block );

		$existing_contents = $this->read();

		if ( '' === $existing_contents ) {
			$existing_contents = self::HEADING . "\n";
		} elseif ( null !== $incoming_name ) {
			foreach ( Parser::parse( $existing_contents ) as $release ) {
				if ( $release->name === $incoming_name ) {
					throw new RuntimeException( sprintf( 'A release named "%s" already exists in %s.', $incoming_name, $this->path ) );
				}
			}
		}

		self::write_atomically( $this->path, self::insert_after_heading( $existing_contents, $block ) );
	}

	private static function first_release_name( string $block ): ?string {
		$releases = Parser::parse( $block );

		return $releases[0]->name ?? null;
	}

	private static function insert_after_heading( string $contents, string $block ): string {
		$contents = rtrim( $contents, "\n" );
		$block    = rtrim( $block, "\n" );

		$lines        = explode( "\n", $contents );
		$heading_line = array_shift( $lines );
		if ( self::HEADING !== trim( $heading_line ) ) {
			// Defensive: read()/'' case above always seeds the heading, but
			// guard against a hand-edited file that dropped it.
			array_unshift( $lines, $heading_line );
			$heading_line = self::HEADING;
		}

		$rest = ltrim( implode( "\n", $lines ), "\n" );

		$body = $block;
		if ( '' !== $rest ) {
			$body .= "\n\n" . $rest;
		}

		return $heading_line . "\n\n" . $body . "\n";
	}

	private static function write_atomically( string $path, string $contents ): void {
		$dir = dirname( $path );
		$tmp = $dir . '/.' . basename( $path ) . '.' . uniqid( '', true ) . '.tmp';

		$written = file_put_contents( $tmp, $contents, LOCK_EX );
		if ( false === $written ) {
			throw new RuntimeException( sprintf( 'Could not write a temporary file for %s.', $path ) );
		}

		if ( is_file( $path ) ) {
			// Preserve the existing file's permissions: file_put_contents()
			// creates the temp file with the process umask, which can be
			// more permissive (or restrictive) than what was deliberately
			// set on the target CHANGELOG.md.
			$existing_perms = fileperms( $path );
			if ( false !== $existing_perms ) {
				chmod( $tmp, $existing_perms & 0777 );
			}
		}

		if ( false === rename( $tmp, $path ) ) {
			@unlink( $tmp );
			throw new RuntimeException( sprintf( 'Could not replace %s.', $path ) );
		}
	}
}
