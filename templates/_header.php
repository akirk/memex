<?php
/**
 * Shared header partial for Memex app templates.
 *
 * Usage:
 *   $memex_title = 'Page title';
 *   include __DIR__ . '/_header.php';
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
?>
<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php echo wp_app_title( isset( $memex_title ) ? $memex_title : '' ); ?></title>
	<?php wp_app_head(); ?>
</head>
<body class="wp-app-body memex">
	<?php wp_app_body_open(); ?>

	<div class="memex-layout">
		<aside class="memex-sidebar" aria-labelledby="memex-sidebar-heading">
			<div class="memex-brand">
				<a id="memex-sidebar-heading" href="<?php echo esc_url( home_url( '/memex/' ) ); ?>">Memex</a>
			</div>
			<form class="memex-quick-capture" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" aria-label="<?php esc_attr_e( 'Quick capture', 'memex' ); ?>">
				<input type="hidden" name="action" value="memex_quick_capture">
				<?php wp_nonce_field( 'memex_quick_capture' ); ?>
				<textarea name="content" rows="2" aria-label="<?php esc_attr_e( 'Quick capture text', 'memex' ); ?>" placeholder="<?php esc_attr_e( 'Quick capture — appends to today', 'memex' ); ?>"></textarea>
				<div class="memex-quick-capture-actions">
					<div class="memex-server-time">
						<a href="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" aria-label="<?php esc_attr_e( 'Site time. Open timezone settings.', 'memex' ); ?>">
							<time
								datetime="<?php echo esc_attr( wp_date( DATE_W3C ) ); ?>"
								data-memex-server-time
								data-server-timestamp="<?php echo esc_attr( (string) time() ); ?>"
								data-timezone="<?php echo esc_attr( wp_timezone_string() ); ?>"
								data-format="<?php echo esc_attr( get_option( 'time_format' ) ); ?>"
							><?php echo esc_html( wp_date( get_option( 'time_format' ) ) ); ?></time>
						</a>
					</div>
					<button type="submit"><?php esc_html_e( 'Capture', 'memex' ); ?></button>
				</div>
			</form>
			<nav class="memex-nav" aria-label="<?php esc_attr_e( 'Memex sections', 'memex' ); ?>">
				<a href="<?php echo esc_url( home_url( '/memex/' ) ); ?>"><?php esc_html_e( 'All notes', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/daily' ) ); ?>"><?php esc_html_e( 'Today', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/reminders' ) ); ?>"><?php esc_html_e( 'Reminders', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/search' ) ); ?>"><?php esc_html_e( 'Search', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/graph' ) ); ?>"><?php esc_html_e( 'Graph', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/orphans' ) ); ?>"><?php esc_html_e( 'Orphans', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/broken' ) ); ?>"><?php esc_html_e( 'Broken links', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/import' ) ); ?>"><?php esc_html_e( 'Import', 'memex' ); ?></a>
			</nav>
			<form class="memex-create" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" aria-label="<?php esc_attr_e( 'Create note', 'memex' ); ?>">
				<input type="hidden" name="action" value="memex_create_note">
				<?php wp_nonce_field( 'memex_create_note' ); ?>
				<input type="text" name="title" aria-label="<?php esc_attr_e( 'New note title', 'memex' ); ?>" placeholder="<?php esc_attr_e( 'New note title…', 'memex' ); ?>" autocomplete="off" required>
				<button type="submit"><?php esc_html_e( 'Create', 'memex' ); ?></button>
			</form>
		</aside>
		<main id="memex-main" class="memex-main">
