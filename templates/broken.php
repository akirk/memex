<?php
/**
 * Broken links — references to notes that don't (yet) have content.
 *
 * Stubs are notes auto-created for an unresolved link target — listing them
 * gives the user a punch-list of topics they planned to write but haven't.
 */

use Memex\CPT;
use Memex\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$memex_title = __( 'Broken links', 'memex' );
include __DIR__ . '/_header.php';

// Find all stub notes — they were auto-created when an importer encountered
// a link to a note that didn't exist yet.
$stubs = get_posts(
	array(
		'post_type'      => CPT::POST_TYPE,
		'post_status'    => array( 'publish', 'draft', 'private' ),
		'meta_key'       => CPT::META_STUB,
		'meta_value'     => '1',
		'posts_per_page' => 500,
		'orderby'        => 'title',
		'order'          => 'ASC',
	)
);
?>

<header class="memex-page-header">
	<h1><?php esc_html_e( 'Broken links', 'memex' ); ?></h1>
	<p class="memex-muted">
		<?php esc_html_e( 'Stub notes that other notes reference but that you have not written yet.', 'memex' ); ?>
	</p>
</header>

<?php if ( ! $stubs ) : ?>
	<p><?php esc_html_e( 'No broken links — every wiki-link has a home.', 'memex' ); ?></p>
<?php else : ?>
	<ul class="memex-note-list">
		<?php foreach ( $stubs as $s ) : ?>
			<?php $backlinks = Links::get_backlinks( (int) $s->ID ); ?>
			<li class="memex-note-list-item is-stub">
				<a class="memex-note-list-title" href="<?php echo esc_url( CPT::url( $s ) ); ?>"><?php echo esc_html( $s->post_title ); ?></a>
				<p class="memex-note-list-excerpt">
					<?php
					printf(
						/* translators: %d: number of referring notes */
						esc_html( _n( 'referenced by %d note', 'referenced by %d notes', count( $backlinks ), 'memex' ) ),
						count( $backlinks )
					);
					?>
				</p>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
