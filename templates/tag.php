<?php
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Tag archive intentionally filters notes by taxonomy.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * Tag archive: /memex/tag/{slug}
 */

use Memex\CPT;
use Memex\DailyNote;

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

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
$paged = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
$q = new WP_Query(
	array(
		'post_type'      => CPT::POST_TYPE,
		'posts_per_page' => 25,
		'paged'          => $paged,
		'post_status'    => CPT::readable_statuses(),
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
<header class="memex-page-header<?php echo 'daily' === $term->slug ? ' memex-header-with-calendar' : ''; ?>">
<?php
if ( 'daily' === $term->slug ) {
	$memex_calendar_date = DailyNote::today();
	include __DIR__ . '/_daily-calendar.php';
}
?>

	<h1 id="tag-notes-heading">#<?php echo esc_html( $term->name ); ?></h1>
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

<section id="tag-notes" aria-labelledby="tag-notes-heading" data-ai-assistant-important>
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
</section>

<?php
wp_reset_postdata();
if ( $q->max_num_pages > 1 ) {
	echo '<nav class="memex-pagination">';
	echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', home_url( '/memex/tag/' . $term->slug ) ), 'current' => $paged, 'total' => $q->max_num_pages ) ) );
	echo '</nav>';
}
include __DIR__ . '/_footer.php';
