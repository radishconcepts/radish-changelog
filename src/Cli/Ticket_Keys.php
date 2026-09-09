<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Cli;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;

/**
 * Extracts and validates Jira-style ticket keys ("KFM-196"). Pure PHP: no
 * WordPress or git dependency, so it is unit-testable on its own.
 */
final class Ticket_Keys {

	private const FIND_PATTERN  = '/\b([A-Za-z][A-Za-z0-9]+)-(\d+)\b/';
	private const VALID_PATTERN = '/^[A-Z][A-Z0-9]+-\d+$/';

	/**
	 * Finds every ticket key across $subjects (commit subjects + bodies),
	 * uppercased and deduplicated, filtered to $project, sorted by ticket
	 * number.
	 *
	 * @param string[] $subjects
	 * @return string[]
	 */
	public static function extract( array $subjects, string $project ): array {
		$project_prefix = strtoupper( $project ) . '-';
		$numbers_by_key = [];

		foreach ( $subjects as $subject ) {
			$match_count = preg_match_all( self::FIND_PATTERN, $subject, $matches, PREG_SET_ORDER );

			// preg_match_all() returns the number of matches (0 or more), or
			// false on a regex engine error; only those two are "no matches
			// here", not "found more than one" (a previous bug used `1 !==`,
			// which silently dropped every subject with 2+ keys).
			if ( false === $match_count || 0 === $match_count ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$key = strtoupper( $match[1] ) . '-' . $match[2];

				if ( ! str_starts_with( $key, $project_prefix ) ) {
					continue;
				}

				$numbers_by_key[ $key ] = (int) $match[2];
			}
		}

		asort( $numbers_by_key );

		return array_keys( $numbers_by_key );
	}

	/**
	 * Validates and uppercases a list of ticket keys, e.g. from --tickets=.
	 *
	 * @param string[] $keys
	 * @return string[]
	 * @throws InvalidArgumentException When any key does not match /^[A-Z][A-Z0-9]+-\d+$/ after uppercasing.
	 */
	public static function validate( array $keys ): array {
		$validated = [];

		foreach ( $keys as $key ) {
			$upper = strtoupper( trim( $key ) );

			if ( '' === $upper ) {
				continue;
			}

			if ( 1 !== preg_match( self::VALID_PATTERN, $upper ) ) {
				throw new InvalidArgumentException( sprintf(
					'Invalid ticket key "%s": expected e.g. "KFM-123".',
					$key
				) );
			}

			$validated[] = $upper;
		}

		return $validated;
	}

	private function __construct() {}
}
