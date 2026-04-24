<?php
/**
 * Tag archive: /memex/tag/{slug}
 */

use Memex\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$slug = wp_app_get_route_var( 'slug' );
$term = $slug ? get_term_by( 'slug', $slug, CPT::TAXONOMY ) : null;

$memex_title = $term ? sprintf( /* translators: %s: tag name */ __( 'Tag: %s', 'memex' ), $term->name ) : __( 'Tag', 'memex' );
include __DIR__ . '/_header.php';

if ( ! $term ) :
	status_header( 404 );
	?>
	<p><?php esc_html_e( 'Tag not found.', 'memex' ); ?></p>
	<?php
	include __DIR__ . '/_footer.php';
	return;
endif;

$q = new WP_Query(
	array(
		'post_type'      => CPT::POST_TYPE,
		'posts_per_page' => 100,
		'post_status'    => array( 'publish', 'draft', 'private' ),
		'tax_query'      => array(
			array(
				'taxonomy' => CPT::TAXONOMY,
				'field'    => 'term_id',
				'terms'    => $term->term_id,
			),
		),
	)
);
?>
<header class="memex-page-header">
	<h1>#<?php echo esc_html( $term->name ); ?></h1>
	<p class="memex-muted">
		<?php
		printf(
			/* translators: %d: number of notes with this tag */
			esc_html( _n( '%d note', '%d notes', $q->found_posts, 'memex' ) ),
			(int) $q->found_posts
		);
		?>
	</p>
</header>

<ul class="memex-note-list">
	<?php
	while ( $q->have_posts() ) :
		$q->the_post();
		$post = get_post();
		?>
		<li class="memex-note-list-item">
			<a class="memex-note-list-title" href="<?php echo esc_url( CPT::url( $post ) ); ?>"><?php echo esc_html( $post->post_title ); ?></a>
			<p class="memex-note-list-excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '…' ) ); ?></p>
		</li>
	<?php endwhile; ?>
</ul>

<?php
wp_reset_postdata();
include __DIR__ . '/_footer.php';
