<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Tests\Versions;

use PHPUnit\Framework\TestCase;
use RadishConcepts\Changelog\Versions\Version_Diff;

final class VersionDiffTest extends TestCase {

	public function test_core_changed(): void {
		$base = [ 'core' => '7.0', 'plugins' => [] ];
		$head = [ 'core' => '7.1', 'plugins' => [] ];

		$diff = Version_Diff::between( $base, $head, true );

		self::assertSame( [ '7.0', '7.1' ], $diff['core'] );
	}

	public function test_core_unchanged(): void {
		$base = [ 'core' => '7.1', 'plugins' => [] ];
		$head = [ 'core' => '7.1', 'plugins' => [] ];

		$diff = Version_Diff::between( $base, $head, true );

		self::assertNull( $diff['core'] );
	}

	public function test_updated_added_removed(): void {
		$base = [
			'core'    => '7.1',
			'plugins' => [
				'gravityforms' => [ 'name' => 'Gravity Forms', 'version' => '3.0.4', 'author' => 'Gravity Forms' ],
				'akismet'      => [ 'name' => 'Akismet', 'version' => '5.7.2', 'author' => 'Automattic' ],
			],
		];
		$head = [
			'core'    => '7.1',
			'plugins' => [
				'gravityforms' => [ 'name' => 'Gravity Forms', 'version' => '3.1.0.2', 'author' => 'Gravity Forms' ],
				'webp-express' => [ 'name' => 'WebP Express', 'version' => '0.25.15', 'author' => 'Bjørn Rosell' ],
			],
		];

		$diff = Version_Diff::between( $base, $head, true );

		self::assertSame( [ 'gravityforms' => [ '3.0.4', '3.1.0.2' ] ], $diff['updated'] );
		self::assertSame( [ 'webp-express' => [ 'name' => 'WebP Express', 'version' => '0.25.15' ] ], $diff['added'] );
		self::assertSame( [ 'akismet' => [ 'name' => 'Akismet', 'version' => '5.7.2' ] ], $diff['removed'] );
	}

	public function test_include_patch_false_drops_patch_bump_but_keeps_minor_bump(): void {
		$base = [
			'core'    => '7.1',
			'plugins' => [
				'wp-rocket'    => [ 'name' => 'WP Rocket', 'version' => '3.22.0.1', 'author' => 'WP Media' ],
				'gravityforms' => [ 'name' => 'Gravity Forms', 'version' => '3.0.4', 'author' => 'Gravity Forms' ],
			],
		];
		$head = [
			'core'    => '7.1',
			'plugins' => [
				'wp-rocket'    => [ 'name' => 'WP Rocket', 'version' => '3.22.0.2', 'author' => 'WP Media' ],
				'gravityforms' => [ 'name' => 'Gravity Forms', 'version' => '3.1.0.2', 'author' => 'Gravity Forms' ],
			],
		];

		$diff = Version_Diff::between( $base, $head, false );

		self::assertArrayNotHasKey( 'wp-rocket', $diff['updated'] );
		self::assertSame( [ '3.0.4', '3.1.0.2' ], $diff['updated']['gravityforms'] );
	}

	public function test_own_author_already_filtered_at_inventory_level_is_absent(): void {
		// Version_Diff never sees own-authored plugins: Inventory::at_ref()
		// / Inventory::live() filter them out before the arrays reach here,
		// so a plugin like "kfm" simply never appears in $base or $head.
		$base = [
			'core'    => '7.1',
			'plugins' => [ 'gravityforms' => [ 'name' => 'Gravity Forms', 'version' => '3.0.4', 'author' => 'Gravity Forms' ] ],
		];
		$head = [
			'core'    => '7.1',
			'plugins' => [ 'gravityforms' => [ 'name' => 'Gravity Forms', 'version' => '3.1.0.2', 'author' => 'Gravity Forms' ] ],
		];

		$diff = Version_Diff::between( $base, $head, true );

		self::assertArrayNotHasKey( 'kfm', $diff['updated'] );
		self::assertArrayNotHasKey( 'kfm', $diff['added'] );
		self::assertArrayNotHasKey( 'kfm', $diff['removed'] );
	}
}
