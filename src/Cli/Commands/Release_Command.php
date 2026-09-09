<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use InvalidArgumentException;
use RadishConcepts\Changelog\Changelog\File;
use RadishConcepts\Changelog\Changelog\Parser;
use RadishConcepts\Changelog\Changelog\Writer;
use RadishConcepts\Changelog\Cli\Release_Builder;
use RadishConcepts\Changelog\Cli\Ticket_Keys;
use RadishConcepts\Changelog\Cli\Ticket_Titles;
use RadishConcepts\Changelog\Config;
use RadishConcepts\Changelog\Git;
use RadishConcepts\Changelog\Jira;
use RadishConcepts\Changelog\Plugin;
use RadishConcepts\Changelog\Versions\Inventory;
use RuntimeException;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp changelog release`.
 *
 * The non-CLI parts (resolving --from/--base, building the ticket and
 * updates sections) live in Cli\Release_Builder and Cli\Ticket_Keys so this
 * class stays about flag parsing and WP_CLI I/O (log, confirm, error).
 */
final class Release_Command {

	private const NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

	/**
	 * Builds the changelog entry for a release: detects ticket keys and
	 * version changes between two refs, previews the entry, then creates
	 * `<release_prefix><name>` from --from and commits the entry there.
	 *
	 * Every step reports its own WP_CLI::error() and stops before anything
	 * is changed: the name and branch format, the branch not already
	 * existing, a clean working tree, and resolvable --from/--base refs are
	 * all checked before `git checkout -b` runs.
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Release name. Used for both the branch name
	 * (<release_prefix><name>, "release/" by default) and the changelog
	 * heading. Letters, digits, dots, underscores and hyphens only, up to
	 * 64 characters.
	 *
	 * [--from=<ref>]
	 * : Branch to release from. A bare name (no "/") first tries
	 * "<remote>/<name>"; otherwise <name> itself must resolve (this also
	 * allows a local, unpushed branch). Defaults to branches.from from
	 * changelog.json ("develop" when changelog.json is absent).
	 *
	 * [--base=<ref>]
	 * : Branch to diff against for tickets and version changes. Same
	 * resolution as --from. Defaults to branches.base from changelog.json
	 * ("master" when changelog.json is absent).
	 *
	 * [--tickets=<keys>]
	 * : Comma-separated ticket keys (e.g. "KFM-1,KFM-2") that replace the
	 * list detected from commit subjects between --base and --from.
	 *
	 * [--dry-run]
	 * : Preview the entry without creating a branch or writing anything.
	 *
	 * [--push]
	 * : Push the new branch after committing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp changelog release sprint-12 --dry-run
	 *     wp changelog release sprint-12 --tickets=KFM-1,KFM-2 --yes --push
	 *
	 * @param array<int, string> $args
	 * @param array<string, string> $assoc_args
	 */
	public function run( array $args, array $assoc_args ): void {
		$name = (string) ( $args[0] ?? '' );

		if ( 1 !== preg_match( self::NAME_PATTERN, $name ) ) {
			WP_CLI::error( sprintf(
				'Invalid release name "%s": use only letters, digits, dots, underscores and hyphens (max 64 characters).',
				$name
			) );
			return;
		}

		try {
			$toplevel = ( new Git( ABSPATH ) )->toplevel();
		} catch ( RuntimeException $exception ) {
			WP_CLI::error( sprintf( 'Could not determine the repository root: %s', $exception->getMessage() ) );
			return;
		}

		$git = new Git( $toplevel );

		$located = Changelog_Location::locate( $toplevel );
		if ( null === $located ) {
			return;
		}
		[ $changelog_path, $repo_root, $config ] = $located;

		$remote = $config['branches']['remote'];
		$branch = $config['branches']['release_prefix'] . $name;

		try {
			$branch_format_valid = $git->check_ref_format( $branch );
		} catch ( InvalidArgumentException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return;
		}

		if ( ! $branch_format_valid ) {
			WP_CLI::error( sprintf( 'Invalid release branch name "%s".', $branch ) );
			return;
		}

		try {
			$branch_exists = $git->ref_exists( $branch ) || $git->ref_exists( $remote . '/' . $branch );
		} catch ( InvalidArgumentException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return;
		}

		if ( $branch_exists ) {
			WP_CLI::error( sprintf( 'Branch "%s" already exists locally or on "%s".', $branch, $remote ) );
			return;
		}

		try {
			$is_clean = $git->is_clean();
		} catch ( RuntimeException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return;
		}

		if ( ! $is_clean ) {
			WP_CLI::error( 'Working tree has uncommitted changes to tracked files; commit or stash them first.' );
			return;
		}

		try {
			$git->fetch( $remote );
		} catch ( RuntimeException | InvalidArgumentException $exception ) {
			WP_CLI::error( sprintf( 'git fetch %s failed: %s', $remote, $exception->getMessage() ) );
			return;
		}

		try {
			$from_ref = Release_Builder::resolve_ref( $git, $remote, (string) Utils\get_flag_value( $assoc_args, 'from', $config['branches']['from'] ) );
			$base_ref = Release_Builder::resolve_ref( $git, $remote, (string) Utils\get_flag_value( $assoc_args, 'base', $config['branches']['base'] ) );
		} catch ( RuntimeException | InvalidArgumentException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return;
		}

		try {
			$tickets = self::resolve_tickets( $git, $base_ref, $from_ref, $config['jira']['project'], Utils\get_flag_value( $assoc_args, 'tickets', null ) );
		} catch ( InvalidArgumentException | RuntimeException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return;
		}

		try {
			$plugins_rel = Config::relative( WP_PLUGIN_DIR, $repo_root );
			$core_rel    = Config::relative( ABSPATH . 'wp-includes/version.php', $repo_root );

			$base_inventory = Inventory::at_ref( $git, $base_ref, $plugins_rel, $core_rel, $config['own_authors'] );
			$head_inventory = Inventory::at_ref( $git, $from_ref, $plugins_rel, $core_rel, $config['own_authors'] );
		} catch ( InvalidArgumentException | RuntimeException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return;
		}

		// Ticket_Titles::resolve() never throws for a Jira failure (a wrong
		// or expired token, Jira being down, a network error, …): a release
		// must go out even when Jira cannot be reached. It reports the
		// failure via 'jira_error' instead, folded into the single warning
		// below alongside any keys that stayed bare for other reasons.
		$jira_client = Jira\Client::from_environment( $config['jira']['base_url'] );
		$resolved    = Ticket_Titles::resolve( $tickets, $repo_root, $jira_client );
		$titles      = $resolved['titles'];

		$release = Release_Builder::build_release(
			$name,
			current_time( 'Y-m-d' ),
			$tickets,
			$config['jira']['base_url'],
			$titles,
			$base_inventory,
			$head_inventory,
			$config['updates']['include_patch']
		);
		$block   = Writer::render( $release );

		WP_CLI::log( $block );
		WP_CLI::log( sprintf( 'Branch: %s (from %s, diffed against %s)', $branch, $from_ref, $base_ref ) );
		WP_CLI::log( sprintf( 'Target file: %s', $changelog_path ) );

		self::warn_about_missing_titles( $titles, $jira_client, $resolved['jira_error'] );

		if ( (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false ) ) {
			WP_CLI::log( 'Dry run: nothing was changed.' );
			return;
		}

		// Checked here, before the checkout, rather than left to
		// File::prepend() to discover after `git checkout -b` has already
		// moved the working tree onto the new (now stray) branch.
		// File::prepend() still repeats this check itself right before it
		// writes, as a defence against the file changing in between.
		try {
			self::assert_release_name_available( $changelog_path, $name );
		} catch ( RuntimeException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return;
		}

		WP_CLI::confirm( sprintf( 'Create branch "%s" and commit?', $branch ), $assoc_args );

		try {
			$previous_branch = $git->current_branch();
		} catch ( RuntimeException $exception ) {
			WP_CLI::error( sprintf( 'Could not determine the current branch: %s', $exception->getMessage() ) );
			return;
		}

		try {
			$git->create_branch( $branch, $from_ref );
		} catch ( RuntimeException $exception ) {
			WP_CLI::error( $exception->getMessage() );
			return;
		}

		// From here on the working tree is on the new release branch: any
		// failure leaves it there, so the error names how to get back.
		try {
			( new File( $changelog_path ) )->prepend( $block );
			$git->add( [ Config::relative( $changelog_path, $repo_root ) ] );
			$git->commit( sprintf( 'chore: changelog for release %s', $name ) );
		} catch ( RuntimeException | InvalidArgumentException $exception ) {
			WP_CLI::error( $exception->getMessage() . self::recovery_hint( $previous_branch, $branch ) );
			return;
		}

		$pushed = false;
		if ( (bool) Utils\get_flag_value( $assoc_args, 'push', false ) ) {
			try {
				$git->push( $remote, $branch );
				$pushed = true;
			} catch ( RuntimeException $exception ) {
				WP_CLI::error( $exception->getMessage() . self::recovery_hint( $previous_branch, $branch ) );
				return;
			}
		}

		WP_CLI::success( sprintf( 'Created branch "%s" with one commit.', $branch ) );
		WP_CLI::log( self::next_steps( $branch, $remote, $config['branches']['base'], $pushed ) );
	}

	/**
	 * The ticket list for the release: --tickets= (comma-separated,
	 * validated per key) when given, otherwise every key detected in commit
	 * subjects between $base_ref and $from_ref for $project.
	 *
	 * @return string[]
	 * @throws InvalidArgumentException When --tickets= contains an invalid key.
	 * @throws RuntimeException Propagated from Git::log_subjects() when the range does not resolve.
	 */
	private static function resolve_tickets( Git $git, string $base_ref, string $from_ref, string $project, mixed $tickets_flag ): array {
		if ( null !== $tickets_flag ) {
			$raw = array_filter( array_map( 'trim', explode( ',', (string) $tickets_flag ) ), static fn( string $key ): bool => '' !== $key );

			return array_values( array_unique( Ticket_Keys::validate( array_values( $raw ) ) ) );
		}

		$subjects = $git->log_subjects( $base_ref . '..' . $from_ref );

		return Ticket_Keys::extract( $subjects, $project );
	}

	/**
	 * Prints exactly one WP_CLI::warning() naming the keys that ended up
	 * without a title, when there are any; nothing when every ticket got
	 * one. Two distinct reasons can follow the key list, never both: when
	 * $jira_error is set (a request was attempted and failed, for any
	 * reason - authentication, HTTP status, network, invalid JSON), that
	 * reason plus how to fix credentials; otherwise, when $jira_client is
	 * null (no usable credentials at all), the reason from
	 * Jira\Client::unavailable_reason().
	 *
	 * @param array<string, ?string> $titles
	 */
	private static function warn_about_missing_titles( array $titles, ?Jira\Client $jira_client, ?string $jira_error ): void {
		$missing = array_keys( array_filter( $titles, static fn( ?string $title ): bool => null === $title ) );

		if ( [] === $missing ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: comma-separated list of ticket keys that stayed bare. */
			__( 'No title found for: %s.', Plugin::textdomain() ),
			implode( ', ', $missing )
		);

		if ( null !== $jira_error ) {
			$message .= ' ' . sprintf(
				/* translators: %s: the Jira failure reason (HTTP status or a short description); never credentials. */
				__( 'Jira request failed: %s.', Plugin::textdomain() ),
				$jira_error
			);
			$message .= ' ' . __( 'Check JIRA_EMAIL/JIRA_API_TOKEN or ~/.config/radish-changelog/jira.json.', Plugin::textdomain() );
		} elseif ( null === $jira_client ) {
			$message .= ' ' . Jira\Client::unavailable_reason();
		}

		WP_CLI::warning( $message );
	}

	/**
	 * Fails fast when a release named $name already exists in
	 * CHANGELOG.md, so the duplicate is caught before `git checkout -b`
	 * runs rather than after, when File::prepend() would otherwise be the
	 * first to notice it and leave the working tree on a stray branch.
	 *
	 * @throws RuntimeException When a release named $name already exists.
	 */
	private static function assert_release_name_available( string $changelog_path, string $name ): void {
		$existing_contents = ( new File( $changelog_path ) )->read();

		if ( '' === $existing_contents ) {
			return;
		}

		foreach ( Parser::parse( $existing_contents ) as $release ) {
			if ( $release->name === $name ) {
				throw new RuntimeException( sprintf( 'A release named "%s" already exists in %s.', $name, $changelog_path ) );
			}
		}
	}

	/**
	 * Appended to an error raised after `git checkout -b` has already run,
	 * so the message always says how to get back to $previous_branch and
	 * remove the now-stray $branch.
	 */
	private static function recovery_hint( string $previous_branch, string $branch ): string {
		return sprintf(
			' To clean up: git checkout %s && git branch -D %s',
			$previous_branch,
			$branch
		);
	}

	/**
	 * The closing "what next" text after a successful release commit.
	 */
	private static function next_steps( string $branch, string $remote, string $base, bool $pushed ): string {
		$push_line = $pushed
			? sprintf( 'Already pushed to %s/%s.', $remote, $branch )
			: sprintf( 'git push -u %s %s', $remote, $branch );

		return implode( "\n", [
			'',
			'Next steps:',
			'  1. ' . $push_line,
			sprintf( '  2. Open a pull request into "%s" (a push to "%s" opens one automatically).', $base, $branch ),
			sprintf( '  3. After merging, merge "%s" back into the branch you released from, or CHANGELOG.md will conflict on the next release.', $base ),
		] );
	}
}
