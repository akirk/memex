<?php
/** Minimal capture shell: safe to cache without the private folder tree or note contents. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$memex_capture_version = \Memex\OfflineCapture::asset_version();
$memex_capture_base = plugin_dir_url( dirname( __DIR__ ) . '/memex.php' );
nocache_headers();
wp_enqueue_style( 'memex-capture-shell', $memex_capture_base . 'assets/memex.css', array(), $memex_capture_version );
wp_enqueue_script( 'memex-capture-shell', $memex_capture_base . 'assets/memex-offline.js', array(), $memex_capture_version, true );
wp_add_inline_script(
	'memex-capture-shell',
	'window.memexOffline = ' . wp_json_encode( \Memex\OfflineCapture::client_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
	'before'
);
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Capture — Memex', 'memex' ); ?></title>
	<link rel="manifest" href="<?php echo esc_url( \WpApp\Pwa::get_manifest_url( 'memex' ) ); ?>">
	<meta name="theme-color" content="#2271b1">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<link rel="apple-touch-icon" href="<?php echo esc_url( $memex_capture_base . 'assets/icon-192.png' ); ?>">
	<?php wp_print_styles( array( 'memex-capture-shell' ) ); ?>
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
	<?php include __DIR__ . '/_offline-panel.php'; ?>
	<p><?php esc_html_e( 'Open this screen online once before using it offline. Unsynced entries and drafts are stored on this device; clearing browser data removes them.', 'memex' ); ?></p>
</main>
<?php wp_print_scripts( array( 'memex-capture-shell' ) ); ?>
</body>
</html>
