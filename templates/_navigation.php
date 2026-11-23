<?php
/** Shared navigation for the app shell and online tools. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
				<nav class="memex-nav" aria-label="<?php esc_attr_e( 'Memex sections', 'memex' ); ?>">
					<a href="<?php echo esc_url( home_url( '/memex/' ) ); ?>"><?php esc_html_e( 'All notes', 'memex' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/memex/daily' ) ); ?>"><?php esc_html_e( 'Today', 'memex' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/memex/reminders' ) ); ?>"><?php esc_html_e( 'Reminders', 'memex' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/memex/search' ) ); ?>"><?php esc_html_e( 'Search', 'memex' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/memex/graph' ) ); ?>"><?php esc_html_e( 'Graph', 'memex' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/memex/tags' ) ); ?>"><?php esc_html_e( 'Tags', 'memex' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/memex/orphans' ) ); ?>"><?php esc_html_e( 'Orphans', 'memex' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/memex/broken' ) ); ?>"><?php esc_html_e( 'Broken links', 'memex' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/memex/import' ) ); ?>"><?php esc_html_e( 'Import', 'memex' ); ?></a>
					<a href="<?php echo esc_url( home_url( '/memex/export' ) ); ?>"><?php esc_html_e( 'Export', 'memex' ); ?></a>
				</nav>
