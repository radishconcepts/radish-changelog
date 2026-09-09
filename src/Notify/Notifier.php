<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Notify;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Admin\Page;
use RadishConcepts\Changelog\Admin\Subscription;
use RadishConcepts\Changelog\Changelog\File;
use RadishConcepts\Changelog\Changelog\Parser;
use RadishConcepts\Changelog\Changelog\Release;
use RadishConcepts\Changelog\Config;
use Throwable;
use WP_User;

/**
 * Decides whether the newest release in CHANGELOG.md should trigger a
 * release e-mail, and does the sending. Two callers share this decision
 * logic:
 *
 * - register() hooks admin_init -> maybe_notify(), which is how a real
 *   production deploy actually mails subscribers (Reachability step 6).
 * - `wp changelog notify` (Cli\Commands\Notify_Command) calls evaluate()
 *   for --dry-run reporting and run() for real/forced sending.
 */
final class Notifier {

	private const SEEN_MTIME_OPTION    = 'radish_changelog_seen_mtime';
	private const MARKER_PREFIX        = 'radish_changelog_notified_';
	private const GRACE_PERIOD_SECONDS = 120;
	private const RESUME_LOCK_SUFFIX   = '_resume_lock';
	private const RESUME_LOCK_TTL      = 300;

	/**
	 * How many send passes (the first claim plus every later resume) a
	 * single release marker gets before this class stops retrying it. A
	 * recipient whose mailbox permanently rejects mail (or any other
	 * wp_mail() failure that repeats every pass) would otherwise keep the
	 * marker incomplete forever, which keeps run() from ever writing
	 * seen_mtime (see its docblock) and so re-parses CHANGELOG.md on every
	 * admin_init indefinitely. After this many attempts, notify_release()
	 * gives up: logs the unreached user ids once and reports the marker as
	 * complete so run() writes seen_mtime and the fast path closes again.
	 */
	private const MAX_RESUME_ATTEMPTS = 5;

	private static ?self $instance = null;

	/**
	 * Loaded changelog.json config, memoized for the lifetime of the
	 * request: environment_enabled() is only reached once the mtime fast
	 * path in run() has already decided something changed, but a request
	 * can still call it more than once (run() then evaluate(), or two
	 * Notifier::instance() call sites), so this avoids loading it twice.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $config_cache = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_action( 'admin_init', [ $this, 'maybe_notify' ] );
	}

	/**
	 * admin_init callback. Wrapped so a parse error (or anything else
	 * unexpected) is logged instead of escaping into admin_init and
	 * breaking the admin. The environment check itself lives inside run(),
	 * after the mtime fast path (see run()'s docblock).
	 */
	public function maybe_notify(): void {
		try {
			$this->run( false );
		} catch ( Throwable $exception ) {
			error_log( sprintf( '[radish-changelog] Notifier::maybe_notify() failed: %s', $exception->getMessage() ) );
		}
	}

	/**
	 * Read-only snapshot of the current state, for `wp changelog notify
	 * --dry-run`. Never touches options or sends mail.
	 *
	 * @return array{
	 *     environment: string,
	 *     environment_enabled: bool,
	 *     changelog_path: ?string,
	 *     release: ?Release,
	 *     notable: bool,
	 *     sent_count: int,
	 *     remaining_count: int,
	 *     attempts: int,
	 *     recipients: WP_User[],
	 * }
	 */
	public function evaluate(): array {
		$changelog_path = Config::locate( ABSPATH );
		$release        = self::newest_release( $changelog_path );
		$marker_key     = null !== $release ? self::marker_key( $release ) : null;
		$recipients     = self::eligible_recipients();
		$marker         = null !== $marker_key ? get_option( $marker_key, false ) : false;
		$sent_ids       = self::marker_sent_ids( $marker );
		$remaining      = self::remaining_recipients( $recipients, $sent_ids );

		return [
			'environment'         => wp_get_environment_type(),
			'environment_enabled' => self::environment_enabled( $changelog_path ),
			'changelog_path'      => $changelog_path,
			'release'             => $release,
			'notable'             => null !== $release && $release->is_notable(),
			'sent_count'          => count( $sent_ids ),
			'remaining_count'     => count( $remaining ),
			'attempts'            => self::marker_attempts( $marker ),
			'recipients'          => $remaining,
		];
	}

	/**
	 * Runs the real sequence:
	 *
	 * 1. Locate CHANGELOG.md and compare its mtime with the
	 *    radish_changelog_seen_mtime option FIRST, before anything else:
	 *    equal means nothing changed since the last check, so returns
	 *    without ever loading changelog.json or parsing the file (skipped
	 *    when $force). This is what keeps the config load and the parser
	 *    off every admin request.
	 * 2. Only once the mtime shows something changed (or $force): the
	 *    environment check, then parse and take the newest release.
	 * 3. notify_release() claims/reads the per-release marker and mails
	 *    whoever is still owed a mail for this release (see its own
	 *    docblock for the resumable-send design), and reports back whether
	 *    the marker is now complete: nothing further will ever be sent for
	 *    it (nobody left to mail, the release isn't notable, or
	 *    MAX_RESUME_ATTEMPTS was reached and it gave up).
	 * 4. seen_mtime is only refreshed when there is nothing left to do: no
	 *    release, the environment check failed (this environment will
	 *    never mail this release), or notify_release() reports complete.
	 *    When a remainder exists (grace-period skip, resume lock held by
	 *    another process, or a resume still under MAX_RESUME_ATTEMPTS),
	 *    seen_mtime is left untouched so the mtime fast path stays closed
	 *    and the next request re-evaluates and retries. Writing it
	 *    unconditionally here would let one unmailed recipient (or one
	 *    process losing the lock race) hide the rest of a release from
	 *    every later admin_init and `wp changelog notify` (without
	 *    --force) forever.
	 *
	 * @return int Number of mails sent (0 when nothing was sent).
	 */
	public function run( bool $force ): int {
		$changelog_path = Config::locate( ABSPATH );
		if ( null === $changelog_path ) {
			return 0;
		}

		$file  = new File( $changelog_path );
		$mtime = $file->mtime();

		if ( ! $force && null !== $mtime && self::seen_mtime_matches( $mtime ) ) {
			return 0;
		}

		$sent     = 0;
		$complete = true;

		if ( $force || self::environment_enabled( $changelog_path ) ) {
			$release = self::newest_release( $changelog_path );

			if ( null !== $release ) {
				$result   = self::notify_release( $release, $force, $changelog_path );
				$sent     = $result['sent'];
				$complete = $result['complete'];
			}
		}

		if ( $complete && null !== $mtime ) {
			update_option( self::SEEN_MTIME_OPTION, $mtime, false );
		}

		return $sent;
	}

	/**
	 * Sends (or resumes sending) the release mail for $release, tracking
	 * progress in the marker option's value instead of just its existence,
	 * so an interrupted send (timeout, SMTP death) does not leave the
	 * remaining subscribers unmailed forever:
	 *
	 * - $force: mails everyone regardless of any existing marker.
	 * - No marker yet: atomic_claim_option() is the first-claim lock (see
	 *   its own docblock for why this is not add_option()); the claimant
	 *   mails everyone (sent starts empty).
	 * - A marker already exists: read it. If nobody is left to mail,
	 *   nothing to do. Otherwise, only when the marker is older than
	 *   GRACE_PERIOD_SECONDS is it treated as an interrupted send (a
	 *   concurrent request within the grace period does nothing, since
	 *   another request is very likely still sending). Past the grace
	 *   period, resuming is itself claimed atomically: acquire_resume_lock()
	 *   uses the same atomic_claim_option() compare-and-swap primitive as
	 *   the first-claim lock above, so of several requests that pass the
	 *   grace check at once only one proceeds to read the marker, bump
	 *   'started' and send; the rest see the lock taken and do nothing.
	 *   The lock is released (delete_option()) once send_and_record()
	 *   returns, and a lock older than RESUME_LOCK_TTL is treated as
	 *   abandoned (the holder crashed or timed out) and taken over.
	 *
	 * A release that is not notable never mails anyone; the marker is
	 * still claimed on first sight of it so it is not re-evaluated forever.
	 *
	 * @return array{sent: int, complete: bool} 'complete' is true once
	 *         nothing further will ever be sent for this release (no
	 *         recipient left, not notable, or MAX_RESUME_ATTEMPTS reached),
	 *         and false when a remainder exists that a later request should
	 *         still retry (grace-period skip, resume lock held elsewhere,
	 *         or a resume still under the attempt cap). run() only writes
	 *         seen_mtime when this is true.
	 */
	private static function notify_release( Release $release, bool $force, string $changelog_path ): array {
		$marker_key = self::marker_key( $release );
		$recipients = self::eligible_recipients();

		if ( $force ) {
			return self::attempt_send( $release, $recipients, $recipients, $marker_key, [], time(), 0, $changelog_path );
		}

		$locked = self::atomic_claim_option( $marker_key, self::marker_value( [], time(), 0 ) );

		if ( $locked ) {
			if ( ! $release->is_notable() ) {
				return [ 'sent' => 0, 'complete' => true ];
			}

			return self::attempt_send( $release, $recipients, $recipients, $marker_key, [], time(), 1, $changelog_path );
		}

		if ( ! $release->is_notable() ) {
			return [ 'sent' => 0, 'complete' => true ];
		}

		$marker    = get_option( $marker_key, false );
		$sent_ids  = self::marker_sent_ids( $marker );
		$started   = self::marker_started( $marker );
		$attempts  = self::marker_attempts( $marker );
		$remaining = self::remaining_recipients( $recipients, $sent_ids );

		if ( [] === $remaining ) {
			return [ 'sent' => 0, 'complete' => true ];
		}

		if ( ( time() - $started ) < self::GRACE_PERIOD_SECONDS ) {
			// Another request is very likely still sending; do nothing so
			// the two don't double-mail the same recipients. Leave
			// seen_mtime untouched (complete: false) so this is re-checked.
			return [ 'sent' => 0, 'complete' => false ];
		}

		if ( $attempts >= self::MAX_RESUME_ATTEMPTS ) {
			// Every pass so far still left someone unmailed; stop retrying
			// so this marker doesn't keep the mtime fast path shut forever.
			self::log_give_up( $release, $remaining, $attempts );

			return [ 'sent' => 0, 'complete' => true ];
		}

		if ( ! self::acquire_resume_lock( $marker_key ) ) {
			// Another request already claimed the resume; do nothing so
			// the two don't double-mail the same recipients.
			return [ 'sent' => 0, 'complete' => false ];
		}

		try {
			// Re-read: another request may have resumed and finished (or
			// partially sent) between our grace-period check above and
			// acquiring the lock.
			$marker    = get_option( $marker_key, false );
			$sent_ids  = self::marker_sent_ids( $marker );
			$attempts  = self::marker_attempts( $marker );
			$remaining = self::remaining_recipients( $recipients, $sent_ids );

			if ( [] === $remaining ) {
				return [ 'sent' => 0, 'complete' => true ];
			}

			if ( $attempts >= self::MAX_RESUME_ATTEMPTS ) {
				self::log_give_up( $release, $remaining, $attempts );

				return [ 'sent' => 0, 'complete' => true ];
			}

			// Claim the resume: a request arriving moments later sees a
			// fresh 'started' and treats this as active for the grace
			// period, rather than also racing to resume.
			$next_attempts = $attempts + 1;
			update_option( $marker_key, self::marker_value( $sent_ids, time(), $next_attempts ), false );

			return self::attempt_send( $release, $remaining, $recipients, $marker_key, $sent_ids, time(), $next_attempts, $changelog_path );
		} finally {
			delete_option( $marker_key . self::RESUME_LOCK_SUFFIX );
		}
	}

	/**
	 * Sends one pass (the first claim, a resume, or a forced send) and
	 * decides completeness from what remains afterwards: nothing left to
	 * mail, or $attempts has now reached MAX_RESUME_ATTEMPTS and this
	 * class is giving up on the rest (logged once).
	 *
	 * @param WP_User[] $to_send Recipients to mail this pass.
	 * @param WP_User[] $all_recipients The full eligible set, to recompute
	 *                                  what remains after the pass (a
	 *                                  narrower set than $to_send for a
	 *                                  resume, equal to it for a first
	 *                                  claim or a forced send).
	 * @param int[] $already_sent_ids
	 * @return array{sent: int, complete: bool}
	 */
	private static function attempt_send(
		Release $release,
		array $to_send,
		array $all_recipients,
		string $marker_key,
		array $already_sent_ids,
		int $started,
		int $attempts,
		string $changelog_path
	): array {
		$sent = self::send_and_record( $release, $to_send, $marker_key, $already_sent_ids, $started, $changelog_path, $attempts );

		$marker    = get_option( $marker_key, false );
		$remaining = self::remaining_recipients( $all_recipients, self::marker_sent_ids( $marker ) );

		if ( [] === $remaining ) {
			return [ 'sent' => $sent, 'complete' => true ];
		}

		if ( $attempts >= self::MAX_RESUME_ATTEMPTS ) {
			self::log_give_up( $release, $remaining, $attempts );

			return [ 'sent' => $sent, 'complete' => true ];
		}

		return [ 'sent' => $sent, 'complete' => false ];
	}

	/**
	 * Never logs an e-mail address, only user ids, matching Mailer::send()'s
	 * own per-recipient failure log.
	 *
	 * @param WP_User[] $unreached
	 */
	private static function log_give_up( Release $release, array $unreached, int $attempts ): void {
		error_log( sprintf(
			'[radish-changelog] Giving up on release mail for "%s" after %d attempt(s); unreached user ids: %s',
			$release->name,
			$attempts,
			implode( ', ', array_map( static fn( WP_User $user ): string => (string) $user->ID, $unreached ) )
		) );
	}

	/**
	 * Atomic compare-and-swap for the resume path, mirroring the
	 * atomic_claim_option() first-claim lock in notify_release(): only one
	 * of several concurrent requests past the grace period can acquire
	 * this lock and proceed to resume sending. A lock older than
	 * RESUME_LOCK_TTL is treated as abandoned (its holder crashed or the
	 * request died before reaching the `finally` release) and is taken
	 * over once.
	 *
	 * Taking over a stale lock is itself a compare-and-delete
	 * (compare_and_delete_option()), not a plain delete_option() followed
	 * by a separate INSERT IGNORE: two processes that both read the same
	 * stale lock value would otherwise both see delete_option() "succeed"
	 * (it does not report whether a row actually existed) and both go on
	 * to win the atomic_claim_option() insert that follows, since by then
	 * the row is gone for both of them and INSERT IGNORE has nothing to
	 * collide with. Deleting only the exact row this process just read
	 * (matched by option_value) means only one of them can affect a row,
	 * so only one proceeds to re-claim the lock; the other backs off.
	 */
	private static function acquire_resume_lock( string $marker_key ): bool {
		$lock_key = $marker_key . self::RESUME_LOCK_SUFFIX;

		if ( self::atomic_claim_option( $lock_key, time() ) ) {
			return true;
		}

		$existing = get_option( $lock_key, false );
		$age      = ( false !== $existing && is_numeric( $existing ) )
			? ( time() - (int) $existing )
			: PHP_INT_MAX;

		if ( $age < self::RESUME_LOCK_TTL ) {
			return false;
		}

		if ( ! self::compare_and_delete_option( $lock_key, $existing ) ) {
			// Another process already took over (or released) this stale
			// lock between our read and here; back off rather than racing
			// it for the fresh claim below.
			return false;
		}

		return self::atomic_claim_option( $lock_key, time() );
	}

	/**
	 * A genuine insert-or-fail compare-and-swap for a WordPress option,
	 * used everywhere in this class that needs an exclusive first claim
	 * (the release marker and the resume lock).
	 *
	 * add_option() is *not* safe for this on the WordPress/MySQL versions
	 * this plugin runs on: its INSERT uses `ON DUPLICATE KEY UPDATE`
	 * (wp-includes/option.php), so when the option already exists and the
	 * new value differs from the old one (true every time here, since
	 * every claimed value embeds the current timestamp), MySQL reports
	 * the row as changed and add_option() returns true, silently
	 * overwriting the existing claim instead of failing. Two concurrent
	 * callers racing on the same never-before-seen option name both get
	 * `true` back. This was verified directly against this project's
	 * WordPress 7.1 install: two processes racing add_option() on a fresh
	 * option both returned true, the second silently clobbering the
	 * first's value.
	 *
	 * `INSERT IGNORE` does not have that failure mode: the UNIQUE KEY on
	 * `option_name` makes the second of two racing inserts a genuine
	 * no-op at the database level (0 rows affected), regardless of
	 * whether the value differs. flush_option_cache() forces the next
	 * get_option() for this key to read the row we (or a racing request)
	 * just wrote, since add_option()'s own object-cache bookkeeping
	 * (updating `alloptions`/`notoptions`) is bypassed here.
	 */
	private static function atomic_claim_option( string $key, mixed $value ): bool {
		global $wpdb;

		$inserted = (bool) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$key,
				maybe_serialize( $value )
			)
		);

		self::flush_option_cache( $key );

		return $inserted;
	}

	/**
	 * Compare-and-delete for the stale-lock takeover in acquire_resume_lock():
	 * removes the option row only when its current value still matches
	 * $expected_value exactly (the value that caller read moments earlier),
	 * so of several processes that all read the same stale lock, only the
	 * one whose DELETE actually matched a row (affected rows === 1)
	 * proceeds to re-claim it; the rest back off. See acquire_resume_lock()'s
	 * docblock for why a plain delete_option() here is unsafe.
	 */
	private static function compare_and_delete_option( string $key, mixed $expected_value ): bool {
		global $wpdb;

		$affected = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$key,
				maybe_serialize( $expected_value )
			)
		);

		self::flush_option_cache( $key );

		return 1 === $affected;
	}

	/**
	 * Cache hygiene for a raw $wpdb write against wp_options that bypasses
	 * WordPress's own option functions (add_option()/update_option()/
	 * delete_option()), which otherwise keep the object cache in sync
	 * themselves. This is the only place in this class that talks to
	 * $wpdb directly (atomic_claim_option()'s INSERT IGNORE and
	 * compare_and_delete_option()'s DELETE above); every other read/write
	 * here goes through get_option()/update_option()/delete_option().
	 *
	 * Clears both caches WordPress's Options API consults before hitting
	 * the database:
	 * - the per-key `options` cache entry, so a stale get_option() value
	 *   for this key is never returned;
	 * - this key's entry in the `notoptions` negative cache, so an
	 *   earlier get_option() miss (e.g. `wp changelog notify --dry-run`
	 *   run before the row existed) does not keep hiding a row this
	 *   method just wrote or removed under a persistent object cache.
	 *
	 * autoload is always 'no' for every option this class writes (the
	 * marker and both locks), so `alloptions` (which only caches
	 * autoloaded options) never holds these keys and needs no
	 * invalidation here.
	 */
	private static function flush_option_cache( string $key ): void {
		wp_cache_delete( $key, 'options' );

		$not_options = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $not_options ) && isset( $not_options[ $key ] ) ) {
			unset( $not_options[ $key ] );
			wp_cache_set( 'notoptions', $not_options, 'options' );
		}
	}

	/**
	 * Sends to $recipients, recording each success in the marker option
	 * immediately (not just at the end), so a send interrupted partway
	 * through still leaves an accurate 'sent' list behind.
	 *
	 * @param WP_User[] $recipients
	 * @param int[] $already_sent_ids
	 * @param int $attempts The attempt number this pass records in the
	 *                      marker (see MAX_RESUME_ATTEMPTS), unchanged for
	 *                      every recipient recorded during this one pass.
	 */
	private static function send_and_record(
		Release $release,
		array $recipients,
		string $marker_key,
		array $already_sent_ids,
		int $started,
		string $changelog_path,
		int $attempts
	): int {
		$sent_ids  = $already_sent_ids;
		$jira_host = Page::jira_host( $changelog_path );

		return Mailer::send(
			$release,
			$recipients,
			$jira_host,
			static function ( WP_User $user ) use ( $marker_key, $started, $attempts, &$sent_ids ): void {
				$sent_ids[] = $user->ID;
				update_option( $marker_key, self::marker_value( $sent_ids, $started, $attempts ), false );
			}
		);
	}

	/**
	 * @param int[] $sent_ids
	 * @return array{started: int, sent: int[], attempts: int}
	 */
	private static function marker_value( array $sent_ids, int $started, int $attempts ): array {
		return [
			'started'  => $started,
			'sent'     => array_values( array_unique( $sent_ids ) ),
			'attempts' => $attempts,
		];
	}

	/**
	 * @return int[]
	 */
	private static function marker_sent_ids( mixed $marker ): array {
		if ( ! is_array( $marker ) || ! isset( $marker['sent'] ) || ! is_array( $marker['sent'] ) ) {
			// Also covers a legacy marker (a plain timestamp string, from
			// before markers tracked per-recipient progress): treated as
			// "nobody recorded yet", which is safe because such a marker
			// only exists when send_and_record() below never ran on it, so
			// there is nothing to resume.
			return [];
		}

		return array_values( array_filter( $marker['sent'], 'is_int' ) );
	}

	private static function marker_started( mixed $marker ): int {
		if ( is_array( $marker ) && isset( $marker['started'] ) && is_int( $marker['started'] ) ) {
			return $marker['started'];
		}

		return time();
	}

	/**
	 * Number of send passes already recorded for this marker (see
	 * MAX_RESUME_ATTEMPTS). A legacy or missing marker has none.
	 */
	private static function marker_attempts( mixed $marker ): int {
		if ( is_array( $marker ) && isset( $marker['attempts'] ) && is_int( $marker['attempts'] ) ) {
			return $marker['attempts'];
		}

		return 0;
	}

	/**
	 * @param WP_User[] $recipients
	 * @param int[] $sent_ids
	 * @return WP_User[]
	 */
	private static function remaining_recipients( array $recipients, array $sent_ids ): array {
		return array_values( array_filter(
			$recipients,
			static fn( WP_User $user ): bool => ! in_array( $user->ID, $sent_ids, true )
		) );
	}

	private static function newest_release( ?string $changelog_path ): ?Release {
		if ( null === $changelog_path ) {
			return null;
		}

		$markdown = ( new File( $changelog_path ) )->read();
		if ( '' === $markdown ) {
			return null;
		}

		$releases = Parser::parse( $markdown );

		return $releases[0] ?? null;
	}

	private static function seen_mtime_matches( int $mtime ): bool {
		$stored = get_option( self::SEEN_MTIME_OPTION, false );

		return false !== $stored && (int) $stored === $mtime;
	}

	private static function marker_key( Release $release ): string {
		return self::MARKER_PREFIX . md5( $release->name );
	}

	/**
	 * Recipients are Admin\Subscription::subscribers() (slice 8), minus
	 * anyone who no longer has edit_posts — a user can lose the capability
	 * after subscribing, and the linked page requires it, so mailing them
	 * release details they cannot open would be pointless and a needless
	 * data exposure.
	 *
	 * @return WP_User[]
	 */
	private static function eligible_recipients(): array {
		return array_values(
			array_filter(
				Subscription::instance()->subscribers(),
				static fn( WP_User $user ): bool => user_can( $user, 'edit_posts' )
			)
		);
	}

	private static function environment_enabled( ?string $changelog_path ): bool {
		$config       = self::config( $changelog_path );
		$environments = $config['notify']['environments'] ?? [];

		return in_array( wp_get_environment_type(), (array) $environments, true );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function config( ?string $changelog_path ): array {
		return self::$config_cache ??= Config::for_changelog( $changelog_path );
	}

	private function __construct() {}
}
