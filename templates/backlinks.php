<?php
/**
 * Dedicated backlinks page for a note: /memex/backlinks/{slug}
 *
 * Shows the same linked-mentions list as the note view, full-sized.
 */

use Memex\CPT;
use Memex\Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$slug = wp_app_get_route_var( 'slug' );
$post = null;
if ( $slug ) {
	$q    = get_posts(
		array(
			'post_type'      => CPT::POST_TYPE,
			'name'           => $slug,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 1,
		)
	);
	$post = $q ? $q[0] : null;
	if ( ! $post ) {
		$id = Links::resolve( rawurldecode( $slug ) );
		if ( $id ) {
			$post = get_post( $id );
		}
	}
}

if ( ! $post ) {
	status_header( 404 );
	$memex_title = __( 'Not found', 'memex' );
	include __DIR__ . '/_header.php';
	?>
	<p><?php esc_html_e( 'No note found.', 'memex' ); ?></p>
	<?php
	include __DIR__ . '/_footer.php';
	return;
}

$memex_title = sprintf( /* translators: %s: note title */ __( 'Backlinks to %s', 'memex' ), $post->post_title );
include __DIR__ . '/_header.php';
$backlinks = Links::get_backlinks( (int) $post->ID );
?>

<header class="memex-page-header">
	<h1 id="backlinks-heading">
		<?php
		printf(
			/* translators: %s: note title */
			esc_html__( 'Backlinks to "%s"', 'memex' ),
			esc_html( $post->post_title )
		);
		?>
	</h1>
	<p class="memex-muted"><a href="<?php echo esc_url( CPT::url( $post ) ); ?>">&larr; <?php esc_html_e( 'Back to the note', 'memex' ); ?></a></p>
</header>

<?php if ( $backlinks ) : ?>
	<section id="backlink-results" aria-labelledby="backlinks-heading" data-ai-assistant-important>
		<ul class="memex-note-list">
			<?php foreach ( $backlinks as $b ) : ?>
				<li class="memex-note-list-item">
					<a class="memex-note-list-title" href="<?php echo esc_url( CPT::url( $b ) ); ?>"><?php echo esc_html( $b->post_title ); ?></a>
					<p class="memex-note-list-excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $b->post_content ), 40, '…' ) ); ?></p>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php else : ?>
	<p class="memex-muted"><?php esc_html_e( 'No notes link to this one.', 'memex' ); ?></p>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
