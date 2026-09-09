<?php

declare( strict_types=1 );

// WordPress requires this exact guard (not ABSPATH) for uninstall.php: it
// is only ever included by WordPress itself, right before running the
// uninstall routine, never requested directly.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Keys mirrored from RadishConcepts\Changelog\Notify\Notifier
// (SEEN_MTIME_OPTION, MARKER_PREFIX) and
// RadishConcepts\Changelog\Admin\Subscription (META_KEY). uninstall.php
// runs standalone, without the plugin's own autoloader, so these are kept
// as literals here rather than requiring src/ classes.
$seen_mtime_option   = 'radish_changelog_seen_mtime';
$marker_option_like  = 'radish_changelog_notified_';
$subscribed_meta_key = 'radish_changelog_subscribed';

delete_option( $seen_mtime_option );

// Every per-release notification marker (radish_changelog_notified_<md5>):
// there is no fixed list of these, so they are matched by prefix, with
// the "_" and "%" LIKE wildcards in the prefix itself escaped. Selected
// by name and removed one by one through delete_option() rather than a
// single bulk DELETE, so a persistent object cache (Redis, Memcached, …)
// is invalidated for each of them too, not just the database row.
$marker_option_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( $marker_option_like ) . '%'
	)
);

foreach ( $marker_option_names as $marker_option_name ) {
	delete_option( $marker_option_name );
}

// The subscription flag, for every user who ever set it.
delete_metadata( 'user', 0, $subscribed_meta_key, '', true );
