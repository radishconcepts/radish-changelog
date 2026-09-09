<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Cli;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Jira;
use RuntimeException;

/**
 * Title resolution for a list of ticket keys, in order: (1) the first line
 * of `.docs/<KEY>/ticket.md` in the repo root, (2) Jira, for whatever keys
 * are left, in a single request, (3) null (a bare key).
 *
 * from_ticket_md() is pure PHP (no WordPress dependency) so it is
 * unit-tested directly; resolve() calls into Jira\Client, which depends on
 * WordPress HTTP functions and is therefore covered by manual QA instead
 * (see plan Verification), not by this class's own tests.
 */
final class Ticket_Titles {

	private const KEY_PATTERN = '/^[A-Z][A-Z0-9]+-\d+$/';
	private const TITLE_LINE  = '/^#\s+([A-Z][A-Z0-9]+-\d+)(?:\s*[\x{2014}:])?\s+(.+)$/u';
	private const MAX_LENGTH  = 200;

	/**
	 * Resolves a title per key: ticket.md first, then one Jira request for
	 * whatever keys are still missing (skipped entirely when every key was
	 * covered by ticket.md, or when $client is null), then null.
	 *
	 * A Jira failure (authentication failure, a non-200 response, a
	 * transport/network error, or an invalid response body — see
	 * Jira\Client::summaries()) must never stop a release: it is caught
	 * here, the still-queryable keys fall through to a bare (null) title
	 * exactly like "no client configured", and the failure reason is
	 * returned alongside the titles for the caller to fold into its own
	 * single warning. The exception's message never contains credentials
	 * (see Jira\Client).
	 *
	 * @param string[] $keys
	 * @return array{titles: array<string, ?string>, jira_error: ?string}
	 */
	public static function resolve( array $keys, string $repo_root, ?Jira\Client $client ): array {
		$titles      = [];
		$queryable   = [];
		$always_null = [];
		$jira_error  = null;

		foreach ( $keys as $key ) {
			if ( 1 !== preg_match( self::KEY_PATTERN, $key ) ) {
				// Not a plausible Jira key: never touch the filesystem or
				// Jira with it, just report it as titleless.
				$always_null[] = $key;
				continue;
			}

			$title = self::from_ticket_md( self::ticket_md_path( $repo_root, $key ), $key );

			if ( null !== $title ) {
				$titles[ $key ] = self::finalize( $title );
				continue;
			}

			$queryable[] = $key;
		}

		if ( [] !== $queryable && null !== $client ) {
			try {
				$summaries = $client->summaries( $queryable );

				foreach ( $summaries as $key => $summary ) {
					if ( '' === trim( $summary ) ) {
						continue;
					}

					$titles[ $key ] = self::finalize( $summary );
				}

				$queryable = array_values( array_diff( $queryable, array_keys( $titles ) ) );
			} catch ( RuntimeException $exception ) {
				// The still-queryable keys stay bare below; the caller
				// reports $jira_error once, not per key.
				$jira_error = $exception->getMessage();
			}
		}

		foreach ( array_merge( $queryable, $always_null ) as $key ) {
			$titles[ $key ] = null;
		}

		return [
			'titles'     => $titles,
			'jira_error' => $jira_error,
		];
	}

	/**
	 * The title from the first line of a ticket.md file, e.g.
	 * "# KFM-191 — Omslagafbeelding (op mobiel) optimaliseren" (also
	 * accepts "# KFM-1: Title" and "# KFM-1 Title"). Pure PHP: no
	 * WordPress dependency, so it is directly unit-testable.
	 *
	 * Returns null when the file is missing or unreadable, the first line
	 * does not match the expected heading shape, or the key in the heading
	 * does not match $key (e.g. a ticket.md copied into the wrong folder).
	 */
	public static function from_ticket_md( string $path, string $key ): ?string {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$handle = fopen( $path, 'r' );
		if ( false === $handle ) {
			return null;
		}

		$first_line = fgets( $handle );
		fclose( $handle );

		if ( false === $first_line ) {
			return null;
		}

		if ( 1 !== preg_match( self::TITLE_LINE, trim( $first_line ), $matches ) ) {
			return null;
		}

		if ( $matches[1] !== $key ) {
			return null;
		}

		return self::normalize( $matches[2] );
	}

	/**
	 * `<repo_root>/.docs/<key>/ticket.md`. $key is validated by the caller
	 * (self::KEY_PATTERN, the same shape Ticket_Keys produces), so it can
	 * never contain "/" or "..".
	 */
	private static function ticket_md_path( string $repo_root, string $key ): string {
		return rtrim( $repo_root, '/\\' ) . '/.docs/' . $key . '/ticket.md';
	}

	/**
	 * Final cleanup applied to every title regardless of source:
	 * wp_strip_all_tags() (a no-op for a clean ticket.md heading, a real
	 * safeguard for an untrusted Jira summary), collapsed to one line,
	 * trimmed, capped at self::MAX_LENGTH characters.
	 */
	private static function finalize( string $raw ): string {
		$stripped = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $raw ) : strip_tags( $raw );

		return self::normalize( $stripped );
	}

	private static function normalize( string $raw ): string {
		$collapsed = preg_replace( '/\s+/u', ' ', $raw );
		$trimmed   = trim( null === $collapsed ? $raw : $collapsed );

		return function_exists( 'mb_substr' ) ? mb_substr( $trimmed, 0, self::MAX_LENGTH ) : substr( $trimmed, 0, self::MAX_LENGTH );
	}

	private function __construct() {}
}
