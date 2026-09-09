<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Tests\Changelog;

use PHPUnit\Framework\TestCase;
use RadishConcepts\Changelog\Changelog\Release;

final class ReleaseTest extends TestCase {

	public function test_is_patch_bump_true_for_third_and_fourth_segment_change(): void {
		self::assertTrue( Release::is_patch_bump( '3.22.0.1', '3.22.0.2' ) );
	}

	public function test_is_patch_bump_false_for_minor_change(): void {
		self::assertFalse( Release::is_patch_bump( '3.0.4', '3.1.0.2' ) );
	}

	public function test_is_patch_bump_true_for_two_segment_versions(): void {
		self::assertTrue( Release::is_patch_bump( '7.1', '7.1.1' ) );
	}

	public function test_is_notable_false_when_only_patch_bumps(): void {
		$release = new Release(
			'Sprint 12',
			'2026-09-08',
			[],
			[
				'updates' => [
					[ 'text' => 'WP Rocket: 3.22.0.1 → 3.22.0.2', 'key' => null, 'url' => null ],
				],
			]
		);

		self::assertFalse( $release->is_notable() );
	}

	public function test_is_notable_true_for_wordpress_line_even_when_patch_bump(): void {
		$release = new Release(
			'Sprint 12',
			'2026-09-08',
			[],
			[
				'updates' => [
					[ 'text' => 'WordPress: 7.1 → 7.1.1', 'key' => null, 'url' => null ],
				],
			]
		);

		self::assertTrue( $release->is_notable() );
	}

	public function test_is_notable_true_with_one_ticket(): void {
		$release = new Release(
			'Sprint 12',
			'2026-09-08',
			[],
			[
				'tickets' => [
					[ 'text' => 'Incentive-veld per giftperiode tonen', 'key' => 'KFM-189', 'url' => 'https://radishconcepts.atlassian.net/browse/KFM-189' ],
				],
			]
		);

		self::assertTrue( $release->is_notable() );
	}

	public function test_is_notable_false_for_inventory_only(): void {
		$release = new Release(
			'Nulmeting',
			'2026-09-08',
			[],
			[
				'inventory' => [
					[ 'text' => 'WordPress: 7.1', 'key' => null, 'url' => null ],
				],
			]
		);

		self::assertFalse( $release->is_notable() );
	}

	public function test_is_notable_true_for_new_item(): void {
		$release = new Release(
			'Sprint 12',
			'2026-09-08',
			[],
			[
				'updates' => [
					[ 'text' => 'WebP Express: nieuw (0.25.15)', 'key' => null, 'url' => null ],
				],
			]
		);

		self::assertTrue( $release->is_notable() );
	}
}
