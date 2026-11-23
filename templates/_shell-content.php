<?php
/** Static mount points; notes and credentials are never embedded in the shell. */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
?>
<div class="memex-layout" data-memex-shell>
<aside class="memex-sidebar">
<div class="memex-brand"><a data-route="" href="<?php echo esc_url( home_url( '/memex/' ) ); ?>">Memex</a><button type="button" class="memex-sidebar-toggle" data-shell-menu aria-expanded="false" aria-label="Menu"><span class="memex-sidebar-toggle-icon" aria-hidden="true"></span></button></div>
<div class="memex-sidebar-body">
<div data-shell-quick-capture>
<form class="memex-quick-capture" data-memex-capture="daily" aria-label="<?php esc_attr_e( 'Quick capture', 'memex' ); ?>" aria-busy="true">
    <textarea name="content" rows="2" aria-label="<?php esc_attr_e( 'Quick capture text', 'memex' ); ?>" placeholder="<?php esc_attr_e( 'Quick capture — appends to today', 'memex' ); ?>" disabled></textarea>
    <div class="memex-quick-capture-actions">
        <div class="memex-server-time">
            <a data-capture-settings aria-disabled="true" aria-label="<?php esc_attr_e( 'Site time. Open timezone settings.', 'memex' ); ?>"><time data-memex-server-time>--:--</time></a>
            <a role="button" data-memex-capture-task aria-disabled="true" aria-pressed="false" aria-label="<?php esc_attr_e( 'Toggle checkbox at start of entry', 'memex' ); ?>">[ ]</a>
        </div>
        <button type="submit" disabled><?php esc_html_e( 'Capture', 'memex' ); ?></button>
    </div>
    <p data-capture-message role="status"></p>
</form>
</div>
    <?php include __DIR__ . '/_navigation.php'; ?>
    <nav class="memex-folders" data-shell-folders aria-label="<?php esc_attr_e( 'Notes and folders', 'memex' ); ?>"></nav>
<form class="memex-create" data-shell-create aria-label="<?php esc_attr_e( 'Create note', 'memex' ); ?>">
    <input type="text" name="title" aria-label="<?php esc_attr_e( 'New note title', 'memex' ); ?>" placeholder="<?php esc_attr_e( 'New note title…', 'memex' ); ?>" autocomplete="off" required>
    <button type="submit"><?php esc_html_e( 'Create', 'memex' ); ?></button><p role="status"></p>
</form>

</div>
<footer class="memex-sidebar-offline">
    <details id="memex-offline-sync" data-shell-offline><summary><?php esc_html_e( 'Offline & sync', 'memex' ); ?> <span data-shell-pending></span></summary>
    <p data-shell-cache-status role="status"><?php esc_html_e( 'Preparing offline app…', 'memex' ); ?></p>
    <p data-shell-sync-status role="status" aria-live="polite"></p>
    <button type="button" class="memex-button" data-shell-sync><?php esc_html_e( 'Sync now', 'memex' ); ?></button>
    <details><summary><?php esc_html_e( 'Available offline', 'memex' ); ?> <span data-shell-available-count></span></summary><ul data-shell-available></ul></details>
    <ul data-shell-conflicts></ul>
    <details data-shell-old-drafts hidden><summary><?php esc_html_e( 'Earlier capture drafts', 'memex' ); ?></summary><div data-shell-old-draft-list></div></details>
    <p><?php esc_html_e( 'Opened notes and local drafts are saved on this device. Clearing browser data removes unsynced work. Images and embedded media may need a connection.', 'memex' ); ?></p>
    </details>
</footer>
</aside>
<main id="memex-main" class="memex-main">
<p data-shell-status role="status" aria-live="polite"><?php esc_html_e( 'Opening your notes…', 'memex' ); ?></p>
<section data-shell-view></section>

</main>
</div>
<noscript><p><?php esc_html_e( 'Memex needs JavaScript to browse and edit local notes.', 'memex' ); ?></p></noscript>
