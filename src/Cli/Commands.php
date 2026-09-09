<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Cli;

defined( 'ABSPATH' ) || exit;

use WP_CLI;

/**
 * `wp changelog` command group registration.
 *
 * Each subcommand's logic lives in its own class under Cli/Commands/
 * (Init_Command, Release_Command, Notify_Command) — this class only wires
 * them to WP-CLI in register(), so it never surfaces as a subcommand itself
 * and stays small as the group grows.
 */
final class Commands {

	private static ?self $instance = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function register(): void {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		WP_CLI::add_command( 'changelog init', [ new Commands\Init_Command(), 'run' ] );
		WP_CLI::add_command( 'changelog release', [ new Commands\Release_Command(), 'run' ] );
		WP_CLI::add_command( 'changelog notify', [ new Commands\Notify_Command(), 'run' ] );
	}

	private function __construct() {}
}
