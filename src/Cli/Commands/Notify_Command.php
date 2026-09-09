<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Cli\Commands;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Notify\Notifier;
use WP_CLI;
use WP_CLI\Utils;

/**
 * `wp changelog notify`.
 *
 * Shares its decision logic with the admin_init hook: both call into
 * Notify\Notifier, so there is exactly one implementation of "should this
 * release be mailed" (evaluate()) and "send it" (run()).
 */
final class Notify_Command {

	/**
	 * Without flags, runs exactly the same sequence as the admin_init hook
	 * (Notify\Notifier::maybe_notify()), just with CLI output: environment
	 * check, per-release marker lock, mail only when notable.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would happen (release, notable yes/no, environment
	 * enabled yes/no, already notified yes/no, recipients) without sending
	 * anything or changing any state.
	 *
	 * [--force]
	 * : Ignore the environment check and the per-release marker, and send
	 * regardless. A deliberate human action, for testing or resending.
	 *
	 * ## EXAMPLES
	 *
	 *     wp changelog notify --dry-run
	 *     wp changelog notify --force
	 *
	 * @param array<int, string> $args
	 * @param array<string, string> $assoc_args
	 */
	public function run( array $args, array $assoc_args ): void {
		$dry_run = (bool) Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$force   = (bool) Utils\get_flag_value( $assoc_args, 'force', false );

		if ( $dry_run ) {
			$this->report( Notifier::instance()->evaluate() );
			return;
		}

		$sent = Notifier::instance()->run( $force );

		if ( 0 === $sent ) {
			WP_CLI::log( 'No mail sent.' );
			return;
		}

		WP_CLI::success( sprintf( 'Sent %d release mail(s).', $sent ) );
	}

	/**
	 * @param array{
	 *     environment: string,
	 *     environment_enabled: bool,
	 *     changelog_path: ?string,
	 *     release: ?\RadishConcepts\Changelog\Changelog\Release,
	 *     notable: bool,
	 *     sent_count: int,
	 *     remaining_count: int,
	 *     attempts: int,
	 *     recipients: \WP_User[],
	 * } $state
	 */
	private function report( array $state ): void {
		if ( null === $state['release'] ) {
			WP_CLI::log( 'No CHANGELOG.md found, or it has no releases.' );
			return;
		}

		WP_CLI::log( sprintf( 'Release: %s', $state['release']->name ) );
		WP_CLI::log( sprintf( 'Notable: %s', $state['notable'] ? 'yes' : 'no' ) );
		WP_CLI::log( sprintf(
			'Environment enabled: %s (current: %s)',
			$state['environment_enabled'] ? 'yes' : 'no',
			$state['environment']
		) );
		// 'recipients' below is who is still owed a mail for this release
		// (eligible subscribers minus those already recorded as sent in the
		// resumable marker); sent_count is how many already got one;
		// attempts is how many send passes (first claim plus resumes) the
		// marker has recorded so far, capped at Notifier::MAX_RESUME_ATTEMPTS.
		WP_CLI::log( sprintf( 'Already sent: %d', $state['sent_count'] ) );
		WP_CLI::log( sprintf( 'Remaining: %d', $state['remaining_count'] ) );
		WP_CLI::log( sprintf( 'Attempts: %d', $state['attempts'] ) );

		if ( [] === $state['recipients'] ) {
			WP_CLI::log( 'Recipients: none' );
			return;
		}

		WP_CLI::log( 'Recipients:' );
		foreach ( $state['recipients'] as $user ) {
			WP_CLI::log( sprintf( '- %s <%s>', $user->display_name, $user->user_email ) );
		}
	}
}
