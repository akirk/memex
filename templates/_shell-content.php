<?php
/** Static mount points; notes and credentials are never embedded in the shell. */
if ( ! defined( 'ABSPATH' ) ) {
	exit; }
?>
<div class="memex-layout" data-memex-shell>
<aside class="memex-sidebar">
<div class="memex-brand"><a data-route="" href="<?php echo esc_url( home_url( '/memex/' ) ); ?>">Memex</a><button type="button" class="memex-sidebar-toggle" data-shell-menu aria-expanded="false" aria-label="Menu"><span class="memex-sidebar-toggle-icon" aria-hidden="true"></span></button></div>
<div class="memex-sidebar-body">
    <nav class="memex-nav" aria-label="<?php esc_attr_e( 'Memex sections', 'memex' ); ?>">
    <?php
    foreach ( [
		''        => 'All notes',
		'daily'   => 'Today',
		'quick-capture' => 'Quick capture',
		'new'     => 'New note',
		'search'  => 'Search',
		'tags'    => 'Tags',
		'graph'   => 'Graph',
		'orphans' => 'Unlinked notes',
	] as $memex_path => $label ) :
		?>
    <a data-route="<?php echo esc_attr( $memex_path ); ?>" href="<?php echo esc_url( home_url( '/memex/' . $memex_path ) ); ?>"><?php echo esc_html( $label ); ?></a>
    <?php endforeach; ?>
    <?php
    foreach ( [
		'reminders' => 'Reminders',
		'import'    => 'Import',
		'export'    => 'Export',
	] as $memex_path => $label ) :
		?>
    <a href="<?php echo esc_url( home_url( '/memex/' . $memex_path ) ); ?>"><?php echo esc_html( $label ); ?></a>
    <?php endforeach; ?>
    </nav>
    <nav class="memex-folders" data-shell-folders aria-label="<?php esc_attr_e( 'Notes and folders', 'memex' ); ?>"></nav>
</div>
</aside>
<main id="memex-main" class="memex-main">
<p data-shell-status role="status" aria-live="polite"><?php esc_html_e( 'Opening your notes…', 'memex' ); ?></p>
<section data-shell-view></section>
<footer class="memex-footer">
    <details data-shell-offline><summary><?php esc_html_e( 'Offline & sync', 'memex' ); ?> <span data-shell-pending></span></summary>
    <p data-shell-cache-status role="status"><?php esc_html_e( 'Preparing offline app…', 'memex' ); ?></p>
    <p data-shell-sync-status role="status" aria-live="polite"></p>
    <button type="button" data-shell-sync><?php esc_html_e( 'Sync now', 'memex' ); ?></button>
    <details><summary><?php esc_html_e( 'Available offline', 'memex' ); ?> <span data-shell-available-count></span></summary><ul data-shell-available></ul></details>
    <ul data-shell-conflicts></ul>
    <details data-shell-old-drafts hidden><summary><?php esc_html_e( 'Earlier capture drafts', 'memex' ); ?></summary><div data-shell-old-draft-list></div></details>
    <p><?php esc_html_e( 'Opened notes and local drafts are saved on this device. Clearing browser data removes unsynced work. Images and embedded media may need a connection.', 'memex' ); ?></p>
    </details>
</footer>
</main>
</div>
<noscript><p><?php esc_html_e( 'Memex needs JavaScript to browse and edit local notes.', 'memex' ); ?></p></noscript>
