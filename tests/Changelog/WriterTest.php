<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Tests\Changelog;

use PHPUnit\Framework\TestCase;
use RadishConcepts\Changelog\Changelog\Parser;
use RadishConcepts\Changelog\Changelog\Release;
use RadishConcepts\Changelog\Changelog\Writer;

final class WriterTest extends TestCase {

	public function test_round_trip_yields_an_equal_release(): void {
		$release = new Release(
			'Sprint 12',
			'2026-09-08',
			[ "Optionele vrije tekst onder de kop, één of meer alinea's." ],
			[
				'tickets' => [
					[ 'text' => 'Incentive-veld per giftperiode tonen', 'key' => 'KFM-189', 'url' => 'https://radishconcepts.atlassian.net/browse/KFM-189' ],
					[ 'text' => '', 'key' => 'KFM-196', 'url' => 'https://radishconcepts.atlassian.net/browse/KFM-196' ],
				],
				'updates' => [
					[ 'text' => 'WordPress: 6.9 → 7.1', 'key' => null, 'url' => null ],
					[ 'text' => 'WebP Express: nieuw (0.25.15)', 'key' => null, 'url' => null ],
				],
			]
		);

		$markdown        = "# Changelog\n\n" . Writer::render( $release );
		$parsed_releases = Parser::parse( $markdown );

		self::assertCount( 1, $parsed_releases );
		self::assertEquals( $release, $parsed_releases[0] );
	}

	public function test_empty_sections_are_omitted(): void {
		$release = new Release(
			'Nulmeting',
			'2026-09-08',
			[],
			[
				'tickets'   => [],
				'inventory' => [
					[ 'text' => 'WordPress: 7.1', 'key' => null, 'url' => null ],
				],
			]
		);

		$rendered = Writer::render( $release );

		self::assertStringNotContainsString( '### Tickets', $rendered );
		self::assertStringContainsString( '### Inventory', $rendered );
	}
}
