<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Notify;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Admin\Page;
use RadishConcepts\Changelog\Changelog\Release;
use RadishConcepts\Changelog\Plugin;
use WP_User;

/**
 * Sends the release e-mail: one wp_mail() call per recipient (never CC/BCC,
 * so nobody's address leaks to another subscriber), plain text only.
 */
final class Mailer {

	/**
	 * @param WP_User[] $users
	 * @param string $jira_host The configured Jira host: a ticket item is
	 *                          only linked when its URL's host matches this,
	 *                          the same rule Admin\Page applies (Page::host_matches()).
	 * @param ?callable(WP_User): void $on_sent Invoked once per successful
	 *                                          wp_mail(), so a caller can
	 *                                          persist progress (e.g. a
	 *                                          resumable marker) after each
	 *                                          recipient rather than only at
	 *                                          the end.
	 * @return int Number of mails actually sent.
	 */
	public static function send( Release $release, array $users, string $jira_host, ?callable $on_sent = null ): int {
		$subject = self::subject( $release );
		$body    = self::body( $release, $jira_host );

		$sent = 0;

		foreach ( $users as $user ) {
			if ( ! $user instanceof WP_User ) {
				continue;
			}

			if ( wp_mail( $user->user_email, $subject, $body ) ) {
				++$sent;

				if ( null !== $on_sent ) {
					$on_sent( $user );
				}

				continue;
			}

			// Never log the address, only the user id.
			error_log( sprintf( '[radish-changelog] Failed to send release mail to user #%d.', $user->ID ) );
		}

		return $sent;
	}

	private static function subject( Release $release ): string {
		return sprintf(
			/* translators: 1: site name, 2: release name */
			__( '[%1$s] New release: %2$s', Plugin::textdomain() ),
			self::plain( get_bloginfo( 'name' ) ),
			self::plain( $release->name )
		);
	}

	private static function body( Release $release, string $jira_host ): string {
		$lines   = [];
		$lines[] = sprintf(
			/* translators: %s: release name */
			__( 'A new release, %s, is now live.', Plugin::textdomain() ),
			self::plain( $release->name )
		);

		$date_timestamp = null !== $release->date ? strtotime( $release->date ) : false;
		if ( false !== $date_timestamp ) {
			$lines[] = sprintf(
				/* translators: %s: release date */
				__( 'Date: %s', Plugin::textdomain() ),
				wp_date( 'j F Y', $date_timestamp )
			);
		}

		$tickets = $release->tickets();
		if ( [] !== $tickets ) {
			$lines[] = '';
			$lines[] = __( 'Changes:', Plugin::textdomain() );
			foreach ( $tickets as $item ) {
				$lines[] = '- ' . self::format_item( $item, $jira_host );
			}
		}

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: URL of the changelog admin page */
			__( 'View the full changelog: %s', Plugin::textdomain() ),
			admin_url( 'index.php?page=radish-changelog' )
		);

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: URL of the changelog admin page */
			__( 'You are receiving this because you subscribed on that page. You can unsubscribe there at any time: %s', Plugin::textdomain() ),
			admin_url( 'index.php?page=radish-changelog' )
		);

		return implode( "\n", $lines );
	}

	/**
	 * A URL is only appended when it is set AND its host matches
	 * $jira_host, the same rule Admin\Page::render_item() applies (via the
	 * shared Page::host_matches()); a non-matching URL is dropped and only
	 * the plain text is kept, never surfaced to the recipient as a link or
	 * bare URL.
	 *
	 * @param array{text: string, key: ?string, url: ?string} $item
	 */
	private static function format_item( array $item, string $jira_host ): string {
		$label = trim( self::plain( trim( ( $item['key'] ?? '' ) . ' ' . $item['text'] ) ) );

		if ( null !== $item['url'] && '' !== $item['url'] && Page::host_matches( $item['url'], $jira_host ) ) {
			return trim( $label . ' ' . esc_url_raw( $item['url'] ) );
		}

		return $label;
	}

	/**
	 * Plain-text mail: strip any HTML and stray line breaks from
	 * developer-controlled changelog content before it reaches the subject
	 * or body, the same trust boundary as any other file input.
	 */
	private static function plain( string $text ): string {
		return trim( str_replace( [ "\r", "\n" ], ' ', wp_strip_all_tags( $text ) ) );
	}

	private function __construct() {}
}
