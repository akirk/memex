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
		'post_status'    => CPT::readable_statuses(),
		'meta_key'       => CPT::META_STUB,
		'meta_value'     => '1',
		'posts_per_page' => 500,
		'orderby'        => 'title',
		'order'          => 'ASC',
	)
);
?>

<header class="memex-page-header">
	<h1 id="broken-links-heading"><?php esc_html_e( 'Broken links', 'memex' ); ?></h1>
	<p class="memex-muted">
		<?php esc_html_e( 'Stub notes that other notes reference but that you have not written yet.', 'memex' ); ?>
	</p>
</header>

<?php if ( ! $stubs ) : ?>
	<p><?php esc_html_e( 'No broken links — every wiki-link has a home.', 'memex' ); ?></p>
<?php else : ?>
	<section id="broken-link-notes" aria-labelledby="broken-links-heading" data-ai-assistant-important>
		<ul class="memex-note-list">
			<?php foreach ( $stubs as $s ) : ?>
				<?php $backlinks = Links::get_backlinks( (int) $s->ID ); ?>
				<li class="memex-note-list-item is-stub" data-note-id="<?php echo (int) $s->ID; ?>" data-note-status="<?php echo esc_attr( $s->post_status ); ?>">
					<a class="memex-note-list-title" href="<?php echo esc_url( CPT::url( $s ) ); ?>"><?php echo esc_html( $s->post_title ); ?></a>
					<p class="memex-note-list-excerpt">
						<?php
						printf(
							/* translators: %d: number of referring notes */
							esc_html( _n( 'referenced by %d note', 'referenced by %d notes', count( $backlinks ), 'memex' ) ),
							count( $backlinks )
						);
						?>
						<span class="memex-muted"><?php echo esc_html( sprintf( /* translators: %1$d: note ID, %2$s: post status */ __( 'ID %1$d · %2$s', 'memex' ), (int) $s->ID, $s->post_status ) ); ?></span>
					</p>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
