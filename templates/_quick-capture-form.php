<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local state.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form class="memex-quick-capture<?php echo ! empty( $memex_inline_capture ) ? ' memex-quick-capture-inline' : ''; ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" aria-label="<?php esc_attr_e( 'Quick capture', 'memex' ); ?>">
	<input type="hidden" name="action" value="memex_quick_capture">
	<?php wp_nonce_field( 'memex_quick_capture' ); ?>
	<?php if ( ! empty( $memex_inline_capture ) ) : ?>
		<input type="text" name="content" aria-label="<?php esc_attr_e( 'Quick capture text', 'memex' ); ?>" placeholder="<?php esc_attr_e( 'Add an entry', 'memex' ); ?>" autocomplete="off" required>
	<?php else : ?>
		<textarea name="content" rows="2" aria-label="<?php esc_attr_e( 'Quick capture text', 'memex' ); ?>" placeholder="<?php esc_attr_e( 'Quick capture — appends to today', 'memex' ); ?>"></textarea>
	<?php endif; ?>
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
			<button type="button" data-memex-capture-task aria-pressed="false" aria-label="<?php esc_attr_e( 'Toggle checkbox at start of entry', 'memex' ); ?>">[ ]</button>
		</div>
		<button type="submit"><?php esc_html_e( 'Capture', 'memex' ); ?></button>
	</div>
</form>
