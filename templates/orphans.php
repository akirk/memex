<?php
/**
 * Orphan notes — notes that are not linked to by any other note.
 */

use Memex\CPT;
use Memex\WikiLinks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$memex_title = __( 'Orphans', 'memex' );
include __DIR__ . '/_header.php';

// Get IDs that ARE linked to.
global $wpdb;
$linked = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT DISTINCT meta_value FROM $wpdb->postmeta WHERE meta_key = %s",
		CPT::META_LINKS_TO
	)
);
$linked = array_map( 'intval', $linked );

$orphans = get_posts(
	array(
		'post_type'      => CPT::POST_TYPE,
		'post_status'    => array( 'publish', 'draft', 'private' ),
		'posts_per_page' => 500,
		'post__not_in'   => $linked ?: array( 0 ),
		'orderby'        => 'modified',
		'order'          => 'DESC',
	)
);
?>

<header class="memex-page-header">
	<h1><?php esc_html_e( 'Orphan notes', 'memex' ); ?></h1>
	<p class="memex-muted"><?php esc_html_e( 'Notes that no other note links to. Good candidates to connect or archive.', 'memex' ); ?></p>
</header>

<?php if ( ! $orphans ) : ?>
	<p><?php esc_html_e( 'No orphans — every note is connected.', 'memex' ); ?></p>
<?php else : ?>
	<ul class="memex-note-list">
		<?php foreach ( $orphans as $o ) : ?>
			<li class="memex-note-list-item">
				<a class="memex-note-list-title" href="<?php echo esc_url( CPT::url( $o ) ); ?>"><?php echo esc_html( $o->post_title ); ?></a>
				<div class="memex-note-list-meta"><?php echo esc_html( get_the_modified_date( '', $o ) ); ?></div>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
