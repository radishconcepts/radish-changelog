<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Changelog;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a Release as the exact markdown block Parser reads back
 * (plan §3 is the contract between the two).
 */
final class Writer {

	private const KNOWN_SECTIONS = [
		'tickets'   => 'Tickets',
		'updates'   => 'Updates',
		'inventory' => 'Inventory',
	];

	public static function render( Release $release ): string {
		$lines = [];

		$heading = '## ' . $release->name;
		if ( null !== $release->date ) {
			$heading .= ' (' . $release->date . ')';
		}
		$lines[] = $heading;
		$lines[] = '';

		foreach ( $release->notes as $paragraph ) {
			$lines[] = $paragraph;
			$lines[] = '';
		}

		foreach ( $release->sections as $key => $items ) {
			if ( [] === $items ) {
				// Empty sections are omitted.
				continue;
			}

			$lines[] = '### ' . self::section_heading( $key );
			foreach ( $items as $item ) {
				$lines[] = self::render_item( $item );
			}
			$lines[] = '';
		}

		while ( [] !== $lines && '' === end( $lines ) ) {
			array_pop( $lines );
		}

		return implode( "\n", $lines ) . "\n";
	}

	private static function section_heading( string $key ): string {
		return self::KNOWN_SECTIONS[ $key ] ?? ucfirst( $key );
	}

	/**
	 * @param array{text: string, key: ?string, url: ?string} $item
	 */
	private static function render_item( array $item ): string {
		if ( null !== $item['key'] && null !== $item['url'] ) {
			$line = '- [' . $item['key'] . '](' . $item['url'] . ')';
			if ( '' !== $item['text'] ) {
				$line .= ' ' . $item['text'];
			}

			return $line;
		}

		return '- ' . $item['text'];
	}

	private function __construct() {}
}
