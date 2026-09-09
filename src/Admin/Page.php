<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Admin;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Changelog\Parser;
use RadishConcepts\Changelog\Config;
use RadishConcepts\Changelog\Plugin;
use RadishConcepts\Changelog\Versions\Inventory;

/**
 * The "Changelog" admin page under Dashboard, visible to edit_posts and
 * nobody else. Renders the parsed CHANGELOG.md.
 */
final class Page {

	private const SLUG = 'radish-changelog';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
	}

	public function add_menu(): void {
		add_submenu_page(
			'index.php',
			__( 'Changelog', Plugin::textdomain() ),
			__( 'Changelog', Plugin::textdomain() ),
			'edit_posts',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', Plugin::textdomain() ) );
		}

		$changelog_path = Config::locate( ABSPATH );
		$releases       = [];

		if ( null !== $changelog_path ) {
			$markdown = file_get_contents( $changelog_path );
			if ( false !== $markdown ) {
				$releases = Parser::parse( $markdown );
			}
		}

		$config                 = Config::for_changelog( $changelog_path );
		$jira_host              = self::jira_host_from_config( $config );
		$inventory              = Inventory::live( $config['own_authors'] );
		$is_subscribed          = Subscription::instance()->is_subscribed( get_current_user_id() );
		$show_subscribed_notice = self::show_subscribed_notice();

		require Plugin::path( 'templates/page.php' );
	}

	/**
	 * True only when the redirect from Subscription::handle() explicitly
	 * flags a successful update. Every other value of this GET parameter
	 * (including it being absent) is untrusted and ignored; it never
	 * triggers a state change, only a notice.
	 */
	private static function show_subscribed_notice(): bool {
		if ( ! isset( $_GET['radish-changelog-updated'] ) ) {
			return false;
		}

		$value = sanitize_text_field( wp_unslash( $_GET['radish-changelog-updated'] ) );

		return '1' === $value;
	}

	/**
	 * Maps a section key from the file format to its display label.
	 */
	public static function section_label( string $key ): string {
		return match ( $key ) {
			'tickets'   => __( 'Changes', Plugin::textdomain() ),
			'updates'   => __( 'Updates', Plugin::textdomain() ),
			'inventory' => __( 'Inventory', Plugin::textdomain() ),
			default     => ucfirst( $key ),
		};
	}

	/**
	 * Renders one item as safe HTML: a link only when it has a URL AND that
	 * URL's host matches the configured Jira host, plain escaped text otherwise.
	 *
	 * @param array{text: string, key: ?string, url: ?string} $item
	 */
	public static function render_item( array $item, string $jira_host ): string {
		$label = null !== $item['key'] ? $item['key'] : '';
		$text  = $item['text'];

		$escaped_url = '' !== $jira_host && null !== $item['url'] ? esc_url( $item['url'] ) : '';
		$is_safe_link = '' !== $escaped_url && '' !== $label && self::host_matches( $item['url'] ?? '', $jira_host );

		if ( $is_safe_link ) {
			$html = '<a href="' . $escaped_url . '">' . esc_html( $label ) . '</a>';
			if ( '' !== $text ) {
				$html .= ' ' . esc_html( $text );
			}

			return $html;
		}

		$plain = '' !== $label ? trim( $label . ' ' . $text ) : $text;

		return esc_html( $plain );
	}

	/**
	 * Shared by Mailer::format_item() so the mail body applies the exact
	 * same "only link when the URL's host matches the configured Jira
	 * host" rule as the admin page and widget.
	 */
	public static function host_matches( string $url, string $jira_host ): bool {
		if ( '' === $jira_host ) {
			return false;
		}

		$parsed = wp_parse_url( $url );

		return is_array( $parsed ) && isset( $parsed['host'] ) && strtolower( (string) $parsed['host'] ) === strtolower( $jira_host );
	}

	/**
	 * Resolves the configured Jira host for the safe link-rendering rule in
	 * render_item(). Shared by Admin\Page and Admin\Dashboard_Widget so the
	 * host-matching rule lives in exactly one place.
	 */
	public static function jira_host( ?string $changelog_path ): string {
		return self::jira_host_from_config( Config::for_changelog( $changelog_path ) );
	}

	/**
	 * @param array<string, mixed> $config
	 */
	private static function jira_host_from_config( array $config ): string {
		$base_url = (string) ( $config['jira']['base_url'] ?? '' );
		$parsed   = wp_parse_url( $base_url );

		return is_array( $parsed ) && isset( $parsed['host'] ) ? (string) $parsed['host'] : '';
	}

	private function __construct() {}
}
