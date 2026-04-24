<?php
/**
 * Broken links — `[[Target]]` references where Target doesn't exist as a note.
 *
 * Any resolved-but-stub target counts too, so the list is useful for filling in
 * what you actually planned to write.
 */

use Memex\CPT;
use Memex\WikiLinks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$memex_title = __( 'Broken links', 'memex' );
include __DIR__ . '/_header.php';

// Find all stub notes — they were auto-created by the wiki-link parser.
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
			<?php $backlinks = WikiLinks::get_backlinks( (int) $s->ID ); ?>
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
