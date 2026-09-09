<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Cli;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Changelog\Release;
use RadishConcepts\Changelog\Git;
use RadishConcepts\Changelog\Plugin;
use RadishConcepts\Changelog\Versions\Version_Diff;
use RuntimeException;

/**
 * The non-CLI half of `wp changelog release`: resolving --from/--base to an
 * actual ref, and turning ticket keys + a version diff into a Release. Kept
 * apart from Cli\Commands so that class stays about WP_CLI I/O (log,
 * confirm, error) while this one stays about building the release content.
 */
final class Release_Builder {

	/**
	 * Resolves a --from/--base value (e.g. "develop") to an existing ref,
	 * per the project convention: a bare name (no "/") prefers
	 * "<remote>/<value>" when that ref exists; otherwise, if $value itself
	 * is an existing ref, it is used as-is (this allows a local, unpushed
	 * branch to be targeted directly, e.g. "radish-build/release-changelog"
	 * during verification). Errors listing what was tried when neither
	 * resolves.
	 *
	 * @throws RuntimeException When neither "<remote>/<value>" nor $value resolves.
	 */
	public static function resolve_ref( Git $git, string $remote, string $value ): string {
		if ( ! str_contains( $value, '/' ) ) {
			$prefixed = $remote . '/' . $value;

			if ( $git->ref_exists( $prefixed ) ) {
				return $prefixed;
			}
		}

		if ( $git->ref_exists( $value ) ) {
			return $value;
		}

		throw new RuntimeException( sprintf(
			'Could not resolve ref "%s": tried "%s/%s" and "%s".',
			$value,
			$remote,
			$value,
			$value
		) );
	}

	/**
	 * Builds the Release for the preview / CHANGELOG.md entry: a "tickets"
	 * section (one item per key, linked to Jira, with $titles text when
	 * known) and an "updates" section (WordPress core first, then changed,
	 * added and removed plugins, each sorted by display name).
	 *
	 * @param string[] $tickets
	 * @param array<string, ?string> $titles Key => title, from Ticket_Titles::resolve()['titles']; a missing or null entry falls back to a bare "[KEY](url)" link.
	 * @param array{core: string, plugins: array<string, array{name: string, version: string, author: string}>} $base_inventory
	 * @param array{core: string, plugins: array<string, array{name: string, version: string, author: string}>} $head_inventory
	 */
	public static function build_release(
		string $name,
		string $date,
		array $tickets,
		string $jira_base_url,
		array $titles,
		array $base_inventory,
		array $head_inventory,
		bool $include_patch
	): Release {
		$diff = Version_Diff::between( $base_inventory, $head_inventory, $include_patch );

		return new Release( $name, $date, [], [
			'tickets' => self::ticket_items( $tickets, $jira_base_url, $titles ),
			'updates' => self::update_items( $diff, $head_inventory ),
		] );
	}

	/**
	 * @param string[] $tickets
	 * @param array<string, ?string> $titles
	 * @return array<int, array{text: string, key: ?string, url: ?string}>
	 */
	private static function ticket_items( array $tickets, string $jira_base_url, array $titles ): array {
		$base_url = rtrim( $jira_base_url, '/' );
		$items    = [];

		foreach ( $tickets as $key ) {
			$items[] = [
				'text' => (string) ( $titles[ $key ] ?? '' ),
				'key'  => $key,
				'url'  => $base_url . '/browse/' . $key,
			];
		}

		return $items;
	}

	/**
	 * @param array{
	 *     core: array{0: string, 1: string}|null,
	 *     updated: array<string, array{0: string, 1: string}>,
	 *     added: array<string, array{name: string, version: string}>,
	 *     removed: array<string, array{name: string, version: string}>
	 * } $diff
	 * @param array{core: string, plugins: array<string, array{name: string, version: string, author: string}>} $head_inventory
	 * @return array<int, array{text: string, key: ?string, url: ?string}>
	 */
	private static function update_items( array $diff, array $head_inventory ): array {
		$items = [];

		if ( null !== $diff['core'] ) {
			$items[] = self::plain_item( sprintf( 'WordPress: %s → %s', $diff['core'][0], $diff['core'][1] ) );
		}

		$updated = $diff['updated'];
		uksort( $updated, static fn( string $a, string $b ): int => strnatcasecmp(
			$head_inventory['plugins'][ $a ]['name'] ?? $a,
			$head_inventory['plugins'][ $b ]['name'] ?? $b
		) );

		foreach ( $updated as $slug => $change ) {
			$name    = $head_inventory['plugins'][ $slug ]['name'] ?? $slug;
			$items[] = self::plain_item( sprintf( '%s: %s → %s', $name, $change[0], $change[1] ) );
		}

		foreach ( self::sorted_by_name( $diff['added'] ) as $plugin ) {
			$items[] = self::plain_item( sprintf( '%s: %s (%s)', $plugin['name'], __( 'new', Plugin::textdomain() ), $plugin['version'] ) );
		}

		foreach ( self::sorted_by_name( $diff['removed'] ) as $plugin ) {
			$items[] = self::plain_item( sprintf( '%s: %s (%s)', $plugin['name'], __( 'removed', Plugin::textdomain() ), $plugin['version'] ) );
		}

		return $items;
	}

	/**
	 * @param array<string, array{name: string, version: string}> $plugins
	 * @return array<string, array{name: string, version: string}>
	 */
	private static function sorted_by_name( array $plugins ): array {
		uasort( $plugins, static fn( array $a, array $b ): int => strnatcasecmp( $a['name'], $b['name'] ) );

		return $plugins;
	}

	/**
	 * @return array{text: string, key: ?string, url: ?string}
	 */
	private static function plain_item( string $text ): array {
		return [ 'text' => $text, 'key' => null, 'url' => null ];
	}

	private function __construct() {}
}
