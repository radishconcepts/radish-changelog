<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Tests\Cli;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RadishConcepts\Changelog\Cli\Ticket_Keys;

final class TicketKeysTest extends TestCase {

	public function test_extracts_key_from_merge_branch_subject(): void {
		$keys = Ticket_Keys::extract( [ "Merge branch 'feature/KFM-189-incentive-feedback'" ], 'KFM' );

		self::assertSame( [ 'KFM-189' ], $keys );
	}

	public function test_extracts_and_uppercases_lowercase_key(): void {
		$keys = Ticket_Keys::extract( [ 'hotfix/kfm-196' ], 'KFM' );

		self::assertSame( [ 'KFM-196' ], $keys );
	}

	public function test_filters_out_keys_from_other_projects(): void {
		$keys = Ticket_Keys::extract( [ 'BREAKPOINT-30', 'UPDATES-2026', 'KFM-170' ], 'KFM' );

		self::assertSame( [ 'KFM-170' ], $keys );
	}

	public function test_result_is_unique_and_sorted_by_ticket_number(): void {
		$keys = Ticket_Keys::extract( [
			'Merge pull request #64 from foo/feature/KFM-188',
			'KFM-170 something',
			'KFM-188 duplicate mention',
			'KFM-196 another one',
		], 'KFM' );

		self::assertSame( [ 'KFM-170', 'KFM-188', 'KFM-196' ], $keys );
	}

	public function test_extract_returns_empty_array_for_no_matches(): void {
		self::assertSame( [], Ticket_Keys::extract( [ 'chore: bump version' ], 'KFM' ) );
	}

	public function test_extracts_every_key_from_a_multi_key_merge_body(): void {
		// A realistic squash-merge subject+body: two keys in one $subjects
		// entry. preg_match_all() returns 2 here, not 1; a regression where
		// the guard checked `1 !== preg_match_all(...)` silently dropped
		// this entire entry instead of processing its two matches.
		$subject = "Merge branch 'feature/KFM-189-x' into develop\n\n* feature/KFM-189-x:\n  feat: KFM-190 something";

		$keys = Ticket_Keys::extract( [ $subject ], 'KFM' );

		self::assertSame( [ 'KFM-189', 'KFM-190' ], $keys );
	}

	public function test_validate_uppercases_valid_keys(): void {
		self::assertSame( [ 'KFM-1', 'KFM-2' ], Ticket_Keys::validate( [ 'kfm-1', 'KFM-2' ] ) );
	}

	public function test_validate_throws_on_invalid_key(): void {
		$this->expectException( InvalidArgumentException::class );
		Ticket_Keys::validate( [ 'DROP;TABLE' ] );
	}

	public function test_validate_throws_on_key_without_number(): void {
		$this->expectException( InvalidArgumentException::class );
		Ticket_Keys::validate( [ 'KFM-' ] );
	}
}
