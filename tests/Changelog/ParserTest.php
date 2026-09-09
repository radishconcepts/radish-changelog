<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Tests\Changelog;

use PHPUnit\Framework\TestCase;
use RadishConcepts\Changelog\Changelog\Parser;

final class ParserTest extends TestCase {

	private const EXAMPLE = <<<'MD'
# Changelog

## Sprint 12 (2026-09-08)

Optionele vrije tekst onder de kop, één of meer alinea's.

### Tickets
- [KFM-189](https://radishconcepts.atlassian.net/browse/KFM-189) Incentive-veld per giftperiode tonen
- [KFM-196](https://radishconcepts.atlassian.net/browse/KFM-196)

### Updates
- WordPress: 6.9 → 7.1
- Gravity Forms: 3.0.4 → 3.1.0.2
- WP Rocket: 3.22.0.1 → 3.22.0.2
- WebP Express: nieuw (0.25.15)
- Akismet: verwijderd (5.7.2)

## Nulmeting (2026-09-08)

### Inventory
- WordPress: 7.1
- Gravity Forms: 3.1.0.2
MD;

	public function test_parses_example_into_two_releases(): void {
		$releases = Parser::parse( self::EXAMPLE );

		self::assertCount( 2, $releases );
		self::assertSame( 'Sprint 12', $releases[0]->name );
		self::assertSame( 'Nulmeting', $releases[1]->name );
	}

	public function test_date_is_optional(): void {
		$releases = Parser::parse( "# Changelog\n\n## Sprint 12 (2026-09-08)\n\n### Tickets\n- foo\n" );

		self::assertSame( '2026-09-08', $releases[0]->date );
	}

	public function test_heading_without_parentheses_has_null_date(): void {
		$releases = Parser::parse( "# Changelog\n\n## Sprint 12\n\n### Tickets\n- foo\n" );

		self::assertNull( $releases[0]->date );
	}

	public function test_notes_are_preserved(): void {
		$releases = Parser::parse( self::EXAMPLE );

		self::assertSame( [ "Optionele vrije tekst onder de kop, één of meer alinea's." ], $releases[0]->notes );
	}

	public function test_link_item_captures_key_and_url(): void {
		$releases = Parser::parse( self::EXAMPLE );
		$tickets  = $releases[0]->tickets();

		self::assertSame( 'KFM-189', $tickets[0]['key'] );
		self::assertSame( 'https://radishconcepts.atlassian.net/browse/KFM-189', $tickets[0]['url'] );
		self::assertSame( 'Incentive-veld per giftperiode tonen', $tickets[0]['text'] );
	}

	public function test_item_without_link_has_null_url(): void {
		$releases = Parser::parse( "# Changelog\n\n## Sprint 12 (2026-09-08)\n\n### Updates\n- WordPress: 6.9 → 7.1\n" );
		$updates  = $releases[0]->updates();

		self::assertNull( $updates[0]['key'] );
		self::assertNull( $updates[0]['url'] );
		self::assertSame( 'WordPress: 6.9 → 7.1', $updates[0]['text'] );
	}

	public function test_unknown_section_is_preserved(): void {
		$releases = Parser::parse( "# Changelog\n\n## Sprint 12 (2026-09-08)\n\n### Notes for QA\n- check the checkout flow\n" );

		self::assertArrayHasKey( 'notes for qa', $releases[0]->sections );
		self::assertSame( 'check the checkout flow', $releases[0]->sections['notes for qa'][0]['text'] );
	}

	public function test_empty_markdown_yields_no_releases(): void {
		self::assertSame( [], Parser::parse( '' ) );
	}

	public function test_malicious_link_is_parsed_without_crashing(): void {
		$releases = Parser::parse( "# Changelog\n\n## Sprint 12 (2026-09-08)\n\n### Tickets\n- [x](javascript:alert(1))\n" );
		$tickets  = $releases[0]->tickets();

		self::assertSame( 'x', $tickets[0]['key'] );
		self::assertNotNull( $tickets[0]['url'] );
	}
}
