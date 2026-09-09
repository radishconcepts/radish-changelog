<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Admin;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Plugin;

/**
 * @var \RadishConcepts\Changelog\Changelog\Release[] $releases
 * @var string $jira_host
 * @var array{core: string, plugins: array<string, array{name: string, version: string, author: string}>} $inventory
 * @var bool $is_subscribed
 * @var bool $show_subscribed_notice
 */
?>
<div class="wrap radish-changelog-page">
	<h1><?php esc_html_e( 'Changelog', Plugin::textdomain() ); ?></h1>

	<?php if ( $show_subscribed_notice ) { ?>
		<div class="notice notice-success is-dismissible">
			<p><?php esc_html_e( 'Your subscription has been updated.', Plugin::textdomain() ); ?></p>
		</div>
	<?php } ?>

	<?php if ( [] === $releases ) { ?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'There is no changelog yet.', Plugin::textdomain() ); ?></p>
		</div>
	<?php } else { ?>
		<?php foreach ( $releases as $release ) { ?>
			<div class="radish-changelog-release">
				<h2>
					<?php echo esc_html( $release->name ); ?>
					<?php if ( null !== $release->date && false !== strtotime( $release->date ) ) { ?>
						<span class="radish-changelog-date">
							<?php echo esc_html( wp_date( 'j F Y', strtotime( $release->date ) ) ); ?>
						</span>
					<?php } ?>
				</h2>

				<?php foreach ( $release->notes as $note ) { ?>
					<p><?php echo esc_html( $note ); ?></p>
				<?php } ?>

				<?php foreach ( $release->sections as $section_key => $items ) { ?>
					<?php if ( [] === $items ) { ?>
						<?php continue; ?>
					<?php } ?>
					<h3><?php echo esc_html( Page::section_label( $section_key ) ); ?></h3>
					<ul>
						<?php foreach ( $items as $item ) { ?>
							<li><?php echo Page::render_item( $item, $jira_host ); // phpcs:ignore -- render_item() escapes internally. ?></li>
						<?php } ?>
					</ul>
				<?php } ?>
			</div>
		<?php } ?>
	<?php } ?>

	<h2><?php esc_html_e( 'Current versions', Plugin::textdomain() ); ?></h2>
	<table class="widefat striped radish-changelog-inventory">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Name', Plugin::textdomain() ); ?></th>
				<th scope="col"><?php esc_html_e( 'Version', Plugin::textdomain() ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php esc_html_e( 'WordPress', Plugin::textdomain() ); ?></td>
				<td><?php echo esc_html( $inventory['core'] ); ?></td>
			</tr>
			<?php foreach ( $inventory['plugins'] as $plugin ) { ?>
				<tr>
					<td><?php echo esc_html( $plugin['name'] ); ?></td>
					<td><?php echo esc_html( $plugin['version'] ); ?></td>
				</tr>
			<?php } ?>
		</tbody>
	</table>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'radish_changelog_subscribe' ); ?>
		<input type="hidden" name="action" value="radish_changelog_subscribe" />
		<input type="hidden" name="subscribe" value="<?php echo esc_attr( $is_subscribed ? '0' : '1' ); ?>" />
		<?php
		submit_button(
			$is_subscribed
				? __( 'Unsubscribe', Plugin::textdomain() )
				: __( 'Keep me posted', Plugin::textdomain() )
		);
		?>
	</form>
</div>
