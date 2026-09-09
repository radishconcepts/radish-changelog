<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Admin;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Plugin;

/**
 * Subscribe / unsubscribe form, shared by the Changelog page and the
 * dashboard widget. Posts to admin-post.php; Subscription::handle() sends
 * the user back to where the form was submitted from.
 *
 * @var bool $is_subscribed
 * @var string $origin  'page' or 'widget'; decides the redirect target and the button size.
 */
$is_widget = 'widget' === $origin;
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="radish-changelog-subscribe">
	<?php wp_nonce_field( 'radish_changelog_subscribe' ); ?>
	<input type="hidden" name="action" value="radish_changelog_subscribe" />
	<input type="hidden" name="origin" value="<?php echo esc_attr( $is_widget ? 'widget' : 'page' ); ?>" />
	<input type="hidden" name="subscribe" value="<?php echo esc_attr( $is_subscribed ? '0' : '1' ); ?>" />
	<?php
	submit_button(
		$is_subscribed
			? __( 'Unsubscribe', Plugin::textdomain() )
			: __( 'Keep me posted', Plugin::textdomain() ),
		$is_widget ? 'secondary small' : 'primary',
		'submit',
		! $is_widget
	);
	?>
</form>
