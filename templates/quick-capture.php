<?php
/**
 * Dedicated quick-capture screen (also available in the sidebar).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$memex_title = __( 'Quick capture', 'memex' );
include __DIR__ . '/_header.php';
?>
<header class="memex-page-header">
	<h1><?php esc_html_e( 'Quick capture', 'memex' ); ?></h1>
	<p class="memex-muted"><?php esc_html_e( 'Anything you type here is appended, with a timestamp, to today\'s daily note.', 'memex' ); ?></p>
</header>
<form class="memex-quick-capture memex-quick-capture-full" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="memex_quick_capture">
	<?php wp_nonce_field( 'memex_quick_capture' ); ?>
	<textarea name="content" rows="10" autofocus placeholder="<?php esc_attr_e( 'What\'s on your mind?', 'memex' ); ?>"></textarea>
	<button type="submit" class="memex-button memex-button-primary"><?php esc_html_e( 'Append to today', 'memex' ); ?></button>
</form>
<?php include __DIR__ . '/_footer.php'; ?>
