<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Changelog;

defined( 'ABSPATH' ) || exit;

/**
 * Parses the fixed CHANGELOG.md format (see plan §3) into Release objects.
 *
 * No general markdown interpretation: anything that does not fit this exact
 * shape is kept as plain text (Q11 — a bespoke parser, no markdown library).
 */
final class Parser {

	/**
	 * @return Release[]
	 */
	public static function parse( string $markdown ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $markdown );
		if ( false === $lines ) {
			return [];
		}

		$releases    = [];
		$current     = null;
		$section     = null;
		$note_buffer = [];

		$flush_notes = static function () use ( &$note_buffer, &$current ): void {
			if ( [] !== $note_buffer && null !== $current ) {
				$current['notes'][] = implode( "\n", $note_buffer );
			}
			$note_buffer = [];
		};

		foreach ( $lines as $line ) {
			if ( 1 === preg_match( '/^##\s+(.+?)(?:\s*\((\d{4}-\d{2}-\d{2})\))?\s*$/u', $line, $matches ) ) {
				$flush_notes();
				if ( null !== $current ) {
					$releases[] = self::to_release( $current );
				}

				$current = [
					'name'     => trim( $matches[1] ),
					'date'     => $matches[2] ?? null,
					'notes'    => [],
					'sections' => [],
				];
				$section = null;
				continue;
			}

			if ( null === $current ) {
				continue;
			}

			if ( 1 === preg_match( '/^###\s+(.+?)\s*$/u', $line, $matches ) ) {
				$flush_notes();
				$section = strtolower( trim( $matches[1] ) );
				if ( ! isset( $current['sections'][ $section ] ) ) {
					$current['sections'][ $section ] = [];
				}
				continue;
			}

			$trimmed = trim( $line );

			if ( '' === $trimmed ) {
				$flush_notes();
				continue;
			}

			if ( null !== $section && str_starts_with( $line, '- ' ) ) {
				$current['sections'][ $section ][] = self::parse_item( substr( $line, 2 ) );
				continue;
			}

			if ( null === $section ) {
				$note_buffer[] = $trimmed;
			}
		}

		$flush_notes();
		if ( null !== $current ) {
			$releases[] = self::to_release( $current );
		}

		return $releases;
	}

	/**
	 * @return array{text: string, key: ?string, url: ?string}
	 */
	private static function parse_item( string $rest ): array {
		$key  = null;
		$url  = null;
		$text = $rest;

		if ( isset( $rest[0] ) && '[' === $rest[0] ) {
			$close_bracket = strpos( $rest, ']' );
			if ( false !== $close_bracket && isset( $rest[ $close_bracket + 1 ] ) && '(' === $rest[ $close_bracket + 1 ] ) {
				$paren_start = $close_bracket + 2;
				$paren_end   = strpos( $rest, ')', $paren_start );
				if ( false !== $paren_end ) {
					$key  = substr( $rest, 1, $close_bracket - 1 );
					$url  = substr( $rest, $paren_start, $paren_end - $paren_start );
					$text = trim( substr( $rest, $paren_end + 1 ) );
				}
			}
		}

		return [
			'text' => $text,
			'key'  => $key,
			'url'  => $url,
		];
	}

	/**
	 * @param array{name: string, date: ?string, notes: array<int, string>, sections: array<string, array<int, array{text: string, key: ?string, url: ?string}>>} $data
	 */
	private static function to_release( array $data ): Release {
		return new Release( $data['name'], $data['date'], $data['notes'], $data['sections'] );
	}

	private function __construct() {}
}
