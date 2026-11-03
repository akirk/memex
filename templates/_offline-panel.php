<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<details class="memex-offline-panel" data-memex-offline-panel>
	<summary><?php esc_html_e( 'Offline & sync', 'memex' ); ?> <span data-offline-count></span></summary>
	<dl>
		<dt><?php esc_html_e( 'Connection', 'memex' ); ?></dt><dd data-offline-connection>Checking…</dd>
		<dt><?php esc_html_e( 'Offline capture', 'memex' ); ?></dt><dd data-offline-ready>Checking…</dd>
		<dt><?php esc_html_e( 'Pending entries', 'memex' ); ?></dt><dd data-offline-pending>Checking…</dd>
		<dt><?php esc_html_e( 'Last sync', 'memex' ); ?></dt><dd data-offline-last>None yet</dd>
	</dl>
	<p data-offline-status role="status" aria-live="polite"></p>
	<button type="button" data-offline-sync><?php esc_html_e( 'Sync now', 'memex' ); ?></button>
	<a href="<?php echo esc_url( wp_login_url( home_url( '/memex/capture' ) ) ); ?>" data-offline-login hidden><?php esc_html_e( 'Log in to sync', 'memex' ); ?></a>
	<p><a data-offline-saved hidden><?php esc_html_e( 'Open last synced note', 'memex' ); ?></a></p>
	<details><summary><?php esc_html_e( 'Pending entries', 'memex' ); ?></summary><ul data-offline-entries></ul></details>
	<p class="memex-muted"><?php esc_html_e( 'Keep Memex open to sync. Pending entries stay on this browser until the server confirms saving them.', 'memex' ); ?></p>
</details>
