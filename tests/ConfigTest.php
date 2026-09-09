<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RadishConcepts\Changelog\Config;

final class ConfigTest extends TestCase {

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/radish-changelog-config-test-' . uniqid( '', true );
		mkdir( $this->dir, 0700, true );
	}

	protected function tearDown(): void {
		$this->remove_dir( $this->dir );
	}

	private function remove_dir( string $dir ): void {
		foreach ( glob( $dir . '/*' ) ?: [] as $entry ) {
			is_dir( $entry ) ? $this->remove_dir( $entry ) : unlink( $entry );
		}
		rmdir( $dir );
	}

	private function write_json( string $contents ): string {
		$path = $this->dir . '/changelog.json';
		file_put_contents( $path, $contents );

		return $path;
	}

	public function test_defaults_without_a_file(): void {
		$config = Config::load( null );

		self::assertSame( 'origin', $config['branches']['remote'] );
		self::assertSame( 'develop', $config['branches']['from'] );
		self::assertSame( 'master', $config['branches']['base'] );
		self::assertSame( 'release/', $config['branches']['release_prefix'] );
		self::assertSame( 'https://radishconcepts.atlassian.net', $config['jira']['base_url'] );
		self::assertSame( 'KFM', $config['jira']['project'] );
		self::assertSame( [ 'Radish Concepts' ], $config['own_authors'] );
		self::assertTrue( $config['updates']['include_patch'] );
		self::assertSame( [ 'production' ], $config['notify']['environments'] );
	}

	public function test_merge_with_partial_json(): void {
		$path = $this->write_json( '{"jira":{"project":"ABC"},"updates":{"include_patch":false}}' );

		$config = Config::load( $path );

		self::assertSame( 'ABC', $config['jira']['project'] );
		self::assertSame( 'https://radishconcepts.atlassian.net', $config['jira']['base_url'] );
		self::assertFalse( $config['updates']['include_patch'] );
		self::assertSame( [ 'Radish Concepts' ], $config['own_authors'] );
	}

	public function test_base_url_with_path_throws(): void {
		$path = $this->write_json( '{"jira":{"base_url":"https://radishconcepts.atlassian.net/some/path"}}' );

		$this->expectException( InvalidArgumentException::class );
		Config::load( $path );
	}

	public function test_base_url_with_http_scheme_throws(): void {
		$path = $this->write_json( '{"jira":{"base_url":"http://radishconcepts.atlassian.net"}}' );

		$this->expectException( InvalidArgumentException::class );
		Config::load( $path );
	}

	public function test_branches_remote_empty_string_throws(): void {
		$path = $this->write_json( '{"branches":{"remote":""}}' );

		$this->expectException( InvalidArgumentException::class );
		Config::load( $path );
	}

	public function test_branches_from_starting_with_dash_throws(): void {
		$path = $this->write_json( '{"branches":{"from":"-evil"}}' );

		$this->expectException( InvalidArgumentException::class );
		Config::load( $path );
	}

	public function test_branches_base_non_string_throws(): void {
		$path = $this->write_json( '{"branches":{"base":123}}' );

		$this->expectException( InvalidArgumentException::class );
		Config::load( $path );
	}

	public function test_branches_release_prefix_without_trailing_slash_throws(): void {
		$path = $this->write_json( '{"branches":{"release_prefix":"release"}}' );

		$this->expectException( InvalidArgumentException::class );
		Config::load( $path );
	}

	public function test_branches_release_prefix_starting_with_dash_throws(): void {
		$path = $this->write_json( '{"branches":{"release_prefix":"-release/"}}' );

		$this->expectException( InvalidArgumentException::class );
		Config::load( $path );
	}

	public function test_jira_project_invalid_format_throws(): void {
		$path = $this->write_json( '{"jira":{"project":"kfm"}}' );

		$this->expectException( InvalidArgumentException::class );
		Config::load( $path );
	}

	public function test_notify_environments_non_array_throws(): void {
		$path = $this->write_json( '{"notify":{"environments":"production"}}' );

		$this->expectException( InvalidArgumentException::class );
		Config::load( $path );
	}

	public function test_branches_with_valid_overrides_does_not_throw(): void {
		$path = $this->write_json( '{"branches":{"remote":"upstream","from":"main","base":"stable","release_prefix":"rel/"}}' );

		$config = Config::load( $path );

		self::assertSame( 'upstream', $config['branches']['remote'] );
		self::assertSame( 'main', $config['branches']['from'] );
		self::assertSame( 'stable', $config['branches']['base'] );
		self::assertSame( 'rel/', $config['branches']['release_prefix'] );
	}

	public function test_invalid_json_throws_with_file_name(): void {
		$path = $this->write_json( '{not valid json' );

		try {
			Config::load( $path );
			self::fail( 'Expected InvalidArgumentException.' );
		} catch ( InvalidArgumentException $exception ) {
			self::assertStringContainsString( $path, $exception->getMessage() );
		}
	}

	public function test_locate_finds_changelog_two_levels_up(): void {
		mkdir( $this->dir . '/a/b', 0700, true );
		file_put_contents( $this->dir . '/CHANGELOG.md', '# Changelog' );

		$found = Config::locate( $this->dir . '/a/b' );

		self::assertSame( realpath( $this->dir . '/CHANGELOG.md' ), $found );
	}

	public function test_locate_does_not_find_changelog_four_levels_up(): void {
		mkdir( $this->dir . '/a/b/c/d', 0700, true );
		file_put_contents( $this->dir . '/CHANGELOG.md', '# Changelog' );

		$found = Config::locate( $this->dir . '/a/b/c/d' );

		self::assertNull( $found );
	}

	public function test_relative_returns_path_relative_to_root(): void {
		mkdir( $this->dir . '/app/www/wp-content/plugins', 0700, true );

		$relative = Config::relative( $this->dir . '/app/www/wp-content/plugins', $this->dir );

		self::assertSame( 'app/www/wp-content/plugins', $relative );
	}

	public function test_relative_returns_empty_string_for_the_root_itself(): void {
		self::assertSame( '', Config::relative( $this->dir, $this->dir ) );
	}

	public function test_relative_throws_when_path_lies_outside_root(): void {
		$outside = dirname( $this->dir ) . '/radish-changelog-config-test-outside-' . uniqid( '', true );
		mkdir( $outside, 0700, true );

		try {
			$this->expectException( InvalidArgumentException::class );
			Config::relative( $outside, $this->dir );
		} finally {
			rmdir( $outside );
		}
	}

	public function test_relative_throws_when_path_does_not_exist(): void {
		$this->expectException( InvalidArgumentException::class );
		Config::relative( $this->dir . '/does-not-exist', $this->dir );
	}

	public function test_repo_root_matches_true_for_the_same_directory(): void {
		self::assertTrue( Config::repo_root_matches( $this->dir, $this->dir ) );
	}

	public function test_repo_root_matches_false_for_different_directories(): void {
		mkdir( $this->dir . '/a/b', 0700, true );

		self::assertFalse( Config::repo_root_matches( $this->dir, $this->dir . '/a/b' ) );
	}
}
