<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Admin;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Changelog\Parser;
use RadishConcepts\Changelog\Changelog\Release;
use RadishConcepts\Changelog\Config;
use RadishConcepts\Changelog\Plugin;

/**
 * The "Latest release" dashboard widget, registered only for edit_posts and
 * for nobody else. Links to the Changelog admin page (Admin\Page).
 */
final class Dashboard_Widget {

	public const MAX_TICKETS = 5;

	private const WIDGET_ID = 'radish_changelog';

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'add_widget' ] );
	}

	public function add_widget(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET_ID,
			__( 'Latest release', Plugin::textdomain() ),
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$changelog_path = Config::locate( ABSPATH );

		/** @var ?Release $release */
		$release = null;

		if ( null !== $changelog_path ) {
			$markdown = file_get_contents( $changelog_path );
			if ( false !== $markdown ) {
				$releases = Parser::parse( $markdown );
				$release  = $releases[0] ?? null;
			}
		}

		$jira_host = Page::jira_host( $changelog_path );

		require Plugin::path( 'templates/widget.php' );
	}

	private function __construct() {}
}
