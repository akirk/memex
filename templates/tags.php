<?php
/**
 * Tag browser: /memex/tags
 *
 * Lists every tag in use, sized by how many notes carry it.
 */

use Memex\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$memex_title = __( 'Tags', 'memex' );
include __DIR__ . '/_header.php';

// Term counts in WP only include published posts, but notes are often drafts or
// private (the tag archive lists all three), so count across those statuses here.
global $wpdb;
$rows  = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT p.ID) AS cnt
		 FROM $wpdb->terms t
		 INNER JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id AND tt.taxonomy = %s
		 INNER JOIN $wpdb->term_relationships tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
		 INNER JOIN $wpdb->posts p ON p.ID = tr.object_id AND p.post_type = %s AND p.post_status IN ('publish','draft','private')
		 GROUP BY t.term_id, t.name, t.slug
		 ORDER BY t.name ASC",
		CPT::TAXONOMY,
		CPT::POST_TYPE
	)
);
$terms = is_array( $rows ) ? $rows : array();

$max_count = 0;
foreach ( $terms as $t ) {
	$max_count = max( $max_count, (int) $t->cnt );
}
?>

<header class="memex-page-header">
	<h1 id="tags-heading"><?php esc_html_e( 'Tags', 'memex' ); ?></h1>
	<p class="memex-muted">
		<?php
		printf(
			/* translators: %d: number of tags */
			esc_html( _n( '%d tag', '%d tags', count( $terms ), 'memex' ) ),
			count( $terms )
		);
		?>
	</p>
</header>

<?php if ( ! $terms ) : ?>
	<p><?php esc_html_e( 'No tags yet. Add #tags to a note, or set them in the editor.', 'memex' ); ?></p>
<?php else : ?>
	<section id="tag-cloud" aria-labelledby="tags-heading" data-ai-assistant-important>
		<ul class="memex-tag-cloud">
			<?php
			foreach ( $terms as $t ) :
				$count = (int) $t->cnt;
				// Scale 0–1 on a log curve so a couple of huge tags don't flatten the rest.
				$weight = $max_count > 1 ? log( $count ) / log( $max_count ) : 0;
				?>
				<li>
					<a
						href="<?php echo esc_url( home_url( '/memex/tag/' . $t->slug ) ); ?>"
						style="--memex-tag-weight: <?php echo esc_attr( number_format( $weight, 2, '.', '' ) ); ?>"
						title="<?php echo esc_attr( sprintf( /* translators: %d: number of notes */ _n( '%d note', '%d notes', $count, 'memex' ), $count ) ); ?>"
					>#<?php echo esc_html( $t->name ); ?><span class="memex-tag-count"><?php echo esc_html( (string) $count ); ?></span></a>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
