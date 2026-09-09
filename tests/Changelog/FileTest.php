<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Tests\Changelog;

use PHPUnit\Framework\TestCase;
use RadishConcepts\Changelog\Changelog\File;
use RadishConcepts\Changelog\Changelog\Parser;
use RuntimeException;

final class FileTest extends TestCase {

	private string $dir;
	private string $path;

	protected function setUp(): void {
		$this->dir  = sys_get_temp_dir() . '/radish-changelog-file-test-' . uniqid( '', true );
		mkdir( $this->dir, 0700, true );
		$this->path = $this->dir . '/CHANGELOG.md';
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->dir . '/*' ) ?: [] );
		rmdir( $this->dir );
	}

	public function test_prepend_creates_file_with_h1(): void {
		$file = new File( $this->path );

		$file->prepend( "## Nulmeting (2026-09-08)\n\n### Inventory\n- WordPress: 7.1\n" );

		$contents = $file->read();
		self::assertStringStartsWith( "# Changelog\n", $contents );
	}

	public function test_prepend_inserts_after_h1(): void {
		$file = new File( $this->path );

		$file->prepend( "## Nulmeting (2026-09-08)\n\n### Inventory\n- WordPress: 7.1\n" );

		$lines = explode( "\n", $file->read() );
		self::assertSame( '# Changelog', $lines[0] );
		self::assertSame( '', $lines[1] );
		self::assertSame( '## Nulmeting (2026-09-08)', $lines[2] );
	}

	public function test_prepend_refuses_a_duplicate_release_name(): void {
		$file = new File( $this->path );
		$file->prepend( "## Nulmeting (2026-09-08)\n\n### Inventory\n- WordPress: 7.1\n" );

		$this->expectException( RuntimeException::class );
		$file->prepend( "## Nulmeting (2026-09-09)\n\n### Inventory\n- WordPress: 7.2\n" );
	}

	public function test_existing_releases_stay_intact(): void {
		$file = new File( $this->path );
		$file->prepend( "## Nulmeting (2026-09-08)\n\n### Inventory\n- WordPress: 7.1\n" );
		$file->prepend( "## Sprint 12 (2026-09-09)\n\n### Tickets\n- [KFM-1](https://radishconcepts.atlassian.net/browse/KFM-1) Fix\n" );

		$releases = Parser::parse( $file->read() );

		self::assertCount( 2, $releases );
		self::assertSame( 'Sprint 12', $releases[0]->name );
		self::assertSame( 'Nulmeting', $releases[1]->name );
		self::assertSame( [ [ 'text' => 'WordPress: 7.1', 'key' => null, 'url' => null ] ], $releases[1]->sections['inventory'] );
	}

	public function test_mtime_is_null_for_missing_file(): void {
		$file = new File( $this->path );

		self::assertNull( $file->mtime() );
	}

	public function test_mtime_is_an_int_after_writing(): void {
		$file = new File( $this->path );
		$file->prepend( "## Nulmeting (2026-09-08)\n\n### Inventory\n- WordPress: 7.1\n" );

		self::assertIsInt( $file->mtime() );
	}
}
