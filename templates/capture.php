<?php
/** Minimal capture shell: safe to cache without the private folder tree or note contents. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
nocache_headers();
header( 'X-Wp-App-Offline: ' . get_current_user_id() );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Capture — Memex', 'memex' ); ?></title>
	<?php wp_app_head(); ?>
</head>
<body class="memex memex-capture-shell">
<main>
	<noscript><?php esc_html_e( 'Offline capture requires JavaScript. Use Quick capture in Memex to save online.', 'memex' ); ?></noscript>
	<a href="<?php echo esc_url( home_url( '/memex/' ) ); ?>">← Memex</a>
	<h1><?php esc_html_e( 'Capture', 'memex' ); ?></h1>
	<p><?php esc_html_e( 'Save a note or daily entry here, even offline. New notes sync as drafts.', 'memex' ); ?></p>
	<form data-memex-capture="choice">
		<label><?php esc_html_e( 'Save as', 'memex' ); ?>
			<select name="kind"><option value="daily"><?php esc_html_e( 'Daily entry', 'memex' ); ?></option><option value="note"><?php esc_html_e( 'New note', 'memex' ); ?></option></select>
		</label>
		<label data-capture-title hidden><?php esc_html_e( 'Title', 'memex' ); ?><input name="title" type="text"></label>
		<label><?php esc_html_e( 'Text', 'memex' ); ?><textarea name="content" rows="12" required></textarea></label>
		<button type="submit"><?php esc_html_e( 'Save capture', 'memex' ); ?></button>
		<p data-capture-status role="status" aria-live="polite"></p>
	</form>
	<p><?php esc_html_e( 'Open this screen online once before using it offline. Unsynced entries and drafts are stored on this device; clearing browser data removes them.', 'memex' ); ?></p>
	<footer class="memex-footer">
		<?php include __DIR__ . '/_offline-panel.php'; ?>
	</footer>
</main>
<?php wp_app_body_close(); ?>
</body>
</html>
