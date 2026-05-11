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
	<title><?php wp_app_title( isset( $memex_title ) ? esc_html( $memex_title ) : '' ); ?></title>
	<?php wp_app_head(); ?>
</head>
<body class="wp-app-body memex">
	<?php wp_app_body_open(); ?>

	<div class="memex-layout">
		<aside class="memex-sidebar">
			<div class="memex-brand">
				<a href="<?php echo esc_url( home_url( '/memex/' ) ); ?>">Memex</a>
			</div>
			<form class="memex-quick-capture" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="memex_quick_capture">
				<?php wp_nonce_field( 'memex_quick_capture' ); ?>
				<textarea name="content" rows="2" placeholder="<?php esc_attr_e( 'Quick capture — appends to today', 'memex' ); ?>"></textarea>
				<button type="submit"><?php esc_html_e( 'Capture', 'memex' ); ?></button>
			</form>
			<nav class="memex-nav">
				<a href="<?php echo esc_url( home_url( '/memex/' ) ); ?>"><?php esc_html_e( 'All notes', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/daily' ) ); ?>"><?php esc_html_e( 'Today', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/reminders' ) ); ?>"><?php esc_html_e( 'Reminders', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/search' ) ); ?>"><?php esc_html_e( 'Search', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/graph' ) ); ?>"><?php esc_html_e( 'Graph', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/orphans' ) ); ?>"><?php esc_html_e( 'Orphans', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/broken' ) ); ?>"><?php esc_html_e( 'Broken links', 'memex' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/memex/import' ) ); ?>"><?php esc_html_e( 'Import', 'memex' ); ?></a>
			</nav>
			<form class="memex-create" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="memex_create_note">
				<?php wp_nonce_field( 'memex_create_note' ); ?>
				<input type="text" name="title" placeholder="<?php esc_attr_e( 'New note title…', 'memex' ); ?>" autocomplete="off" required>
				<button type="submit"><?php esc_html_e( 'Create', 'memex' ); ?></button>
			</form>
		</aside>
		<main class="memex-main">
