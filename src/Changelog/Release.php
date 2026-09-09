<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Changelog;

defined( 'ABSPATH' ) || exit;

/**
 * One changelog entry: a name, an optional ISO date, free-text notes and
 * a set of sections ('tickets' | 'updates' | 'inventory' | other => Item[]).
 *
 * An Item is `[ 'text' => string, 'key' => ?string, 'url' => ?string ]`.
 */
final readonly class Release {

	/**
	 * @param array<int, string> $notes
	 * @param array<string, array<int, array{text: string, key: ?string, url: ?string}>> $sections
	 */
	public function __construct(
		public string $name,
		public ?string $date,
		public array $notes = [],
		public array $sections = [],
	) {}

	/**
	 * @return array<int, array{text: string, key: ?string, url: ?string}>
	 */
	public function tickets(): array {
		return $this->sections['tickets'] ?? [];
	}

	/**
	 * @return array<int, array{text: string, key: ?string, url: ?string}>
	 */
	public function updates(): array {
		return $this->sections['updates'] ?? [];
	}

	/**
	 * A release is "mail-worthy" when it has at least one ticket, or an
	 * Updates item that is not a pure patch bump. WordPress lines always
	 * count when $core_always is true.
	 */
	public function is_notable( bool $core_always = true ): bool {
		if ( count( $this->tickets() ) > 0 ) {
			return true;
		}

		foreach ( $this->updates() as $item ) {
			$text = $item['text'];

			if ( $core_always && str_starts_with( $text, 'WordPress:' ) ) {
				return true;
			}

			if ( self::is_non_patch_update( $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An Updates line either reads "Name: old → new" (a version diff, notable
	 * only when it is not a pure patch bump) or anything else (e.g. "Name:
	 * new (x.y.z)" / "Name: removed (x.y.z)"), which is always notable.
	 */
	private static function is_non_patch_update( string $text ): bool {
		if ( 1 === preg_match( '/:\s*(\S+)\s*→\s*(\S+)\s*$/u', $text, $matches ) ) {
			return ! self::is_patch_bump( $matches[1], $matches[2] );
		}

		return true;
	}

	/**
	 * True when the first two numeric segments of $old and $new are equal
	 * (e.g. 3.22.0.1 → 3.22.0.2, or 7.1 → 7.1.1).
	 */
	public static function is_patch_bump( string $old, string $new ): bool {
		$old_head = array_slice( array_map( 'intval', explode( '.', $old ) ), 0, 2 );
		$new_head = array_slice( array_map( 'intval', explode( '.', $new ) ), 0, 2 );

		return $old_head === $new_head;
	}
}
