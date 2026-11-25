<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<form class="memex-sidebar-search" data-sidebar-section="search" role="search" aria-label="<?php esc_attr_e( 'Search notes', 'memex' ); ?>" action="<?php echo esc_url( home_url( '/memex/search' ) ); ?>" method="get" hidden>
    <input type="search" name="q" aria-label="<?php esc_attr_e( 'Search notes', 'memex' ); ?>" placeholder="<?php esc_attr_e( 'Search notes…', 'memex' ); ?>">
    <button type="submit"><?php esc_html_e( 'Search', 'memex' ); ?></button>
</form>
