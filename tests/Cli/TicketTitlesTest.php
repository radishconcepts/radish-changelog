<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Tests\Cli;

use PHPUnit\Framework\TestCase;
use RadishConcepts\Changelog\Cli\Ticket_Titles;

final class TicketTitlesTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/radish-changelog-ticket-titles-test-' . uniqid( '', true );
		mkdir( $this->dir, 0700, true );
	}

	protected function tearDown(): void {
		self::remove_recursively( $this->dir );
	}

	private static function remove_recursively( string $dir ): void {
		foreach ( glob( $dir . '/*' ) ?: [] as $path ) {
			is_dir( $path ) ? self::remove_recursively( $path ) : unlink( $path );
		}

		rmdir( $dir );
	}

	public function test_reads_title_with_em_dash_separator(): void {
		$path = $this->write_ticket_md( "# KFM-191 — Omslagafbeelding (op mobiel) optimaliseren\n\nSome body text.\n" );

		self::assertSame(
			'Omslagafbeelding (op mobiel) optimaliseren',
			Ticket_Titles::from_ticket_md( $path, 'KFM-191' )
		);
	}

	public function test_reads_title_with_colon_separator(): void {
		$path = $this->write_ticket_md( "# KFM-1: Title\n" );

		self::assertSame( 'Title', Ticket_Titles::from_ticket_md( $path, 'KFM-1' ) );
	}

	public function test_reads_title_with_plain_space_separator(): void {
		$path = $this->write_ticket_md( "# KFM-1 Title\n" );

		self::assertSame( 'Title', Ticket_Titles::from_ticket_md( $path, 'KFM-1' ) );
	}

	public function test_returns_null_for_missing_file(): void {
		self::assertNull( Ticket_Titles::from_ticket_md( $this->dir . '/does-not-exist/ticket.md', 'KFM-1' ) );
	}

	public function test_returns_null_when_heading_key_differs_from_folder(): void {
		$path = $this->write_ticket_md( "# KFM-2 — Title\n" );

		self::assertNull( Ticket_Titles::from_ticket_md( $path, 'KFM-1' ) );
	}

	public function test_returns_null_when_first_line_is_not_a_heading(): void {
		$path = $this->write_ticket_md( "Not a heading\n\n# KFM-1 — Title\n" );

		self::assertNull( Ticket_Titles::from_ticket_md( $path, 'KFM-1' ) );
	}

	public function test_title_is_trimmed_and_collapsed_to_a_single_line(): void {
		$path = $this->write_ticket_md( "# KFM-1 —   Title   with   extra   spaces  \n" );

		self::assertSame( 'Title with extra spaces', Ticket_Titles::from_ticket_md( $path, 'KFM-1' ) );
	}

	private function write_ticket_md( string $contents ): string {
		$ticket_dir = $this->dir . '/ticket-' . uniqid( '', true );
		mkdir( $ticket_dir, 0700, true );

		$path = $ticket_dir . '/ticket.md';
		file_put_contents( $path, $contents );

		return $path;
	}
}
