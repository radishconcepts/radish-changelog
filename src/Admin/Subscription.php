<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Admin;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Plugin;
use WP_User;

/**
 * Self-service subscription to release e-mails, stored as user-meta. The
 * only entry point is the "Keep me posted" / "Unsubscribe" form in
 * templates/subscribe-form.php (shown on the Changelog page and in the
 * dashboard widget), posted to admin-post.php.
 */
final class Subscription {

	public const META_KEY = 'radish_changelog_subscribed';

	private const ACTION = 'radish_changelog_subscribe';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function is_subscribed( int $user_id ): bool {
		return '1' === get_user_meta( $user_id, self::META_KEY, true );
	}

	/**
	 * No capability filter here: the meta only exists for users who
	 * subscribed from the page, which already required edit_posts.
	 *
	 * @return WP_User[]
	 */
	public function subscribers(): array {
		return get_users(
			[
				'meta_key'   => self::META_KEY,
				'meta_value' => '1',
				'fields'     => 'all',
			]
		);
	}

	public function set( int $user_id, bool $on ): void {
		if ( $on ) {
			update_user_meta( $user_id, self::META_KEY, '1' );
			return;
		}

		delete_user_meta( $user_id, self::META_KEY );
	}

	/**
	 * Handles the subscribe/unsubscribe form POST. Nonce and capability are
	 * verified before anything else is touched. Only the currently
	 * logged-in user (get_current_user_id()) is ever changed; any `user_id`
	 * field in the request is ignored.
	 */
	public function handle(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', Plugin::textdomain() ) );
		}

		$raw       = isset( $_POST['subscribe'] ) ? sanitize_text_field( wp_unslash( $_POST['subscribe'] ) ) : '0';
		$subscribe = '1' === $raw ? '1' : '0';

		$this->set( get_current_user_id(), '1' === $subscribe );

		// Back to where the form was submitted from: the dashboard widget or
		// the Changelog page. Anything else falls back to the page.
		$origin = isset( $_POST['origin'] ) ? sanitize_text_field( wp_unslash( $_POST['origin'] ) ) : 'page';
		$target = 'widget' === $origin ? admin_url( 'index.php' ) : admin_url( 'index.php?page=radish-changelog' );

		wp_safe_redirect( add_query_arg( 'radish-changelog-updated', '1', $target ) );
		exit;
	}

	private function __construct() {}
}
