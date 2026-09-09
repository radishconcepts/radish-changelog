<?php

declare( strict_types=1 );

namespace RadishConcepts\Changelog\Admin;

defined( 'ABSPATH' ) || exit;

use RadishConcepts\Changelog\Changelog\Release;
use RadishConcepts\Changelog\Plugin;

/**
 * @var ?Release $release
 * @var string $jira_host
 */
?>
<div class="radish-changelog-widget">
	<?php if ( null === $release ) { ?>
		<p><?php esc_html_e( 'There is no changelog yet.', Plugin::textdomain() ); ?></p>
	<?php } else { ?>
		<h3>
			<?php echo esc_html( $release->name ); ?>
			<?php if ( null !== $release->date && false !== strtotime( $release->date ) ) { ?>
				<span class="radish-changelog-date">
					<?php echo esc_html( wp_date( 'j F Y', strtotime( $release->date ) ) ); ?>
				</span>
			<?php } ?>
		</h3>

		<?php
		$tickets      = $release->tickets();
		$shown        = array_slice( $tickets, 0, Dashboard_Widget::MAX_TICKETS );
		$remaining    = count( $tickets ) - count( $shown );
		$update_count = count( $release->updates() );
		?>

		<?php if ( [] !== $shown ) { ?>
			<ul>
				<?php foreach ( $shown as $item ) { ?>
					<li><?php echo Page::render_item( $item, $jira_host ); // phpcs:ignore -- render_item() escapes internally. ?></li>
				<?php } ?>
			</ul>
			<?php if ( $remaining > 0 ) { ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of additional tickets not shown. */
							__( 'and %d more', Plugin::textdomain() ),
							$remaining
						)
					);
					?>
				</p>
			<?php } ?>
		<?php } ?>

		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of updates in this release. */
					_n( '%d update', '%d updates', $update_count, Plugin::textdomain() ),
					$update_count
				)
			);
			?>
		</p>

		<p>
			<a href="<?php echo esc_url( admin_url( 'index.php?page=radish-changelog' ) ); ?>">
				<?php esc_html_e( 'View the full changelog', Plugin::textdomain() ); ?>
			</a>
		</p>
	<?php } ?>
</div>
