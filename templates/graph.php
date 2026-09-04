<?php
/**
 * Simple force-directed graph of notes and the links between them.
 *
 * Renders a static SVG via D3-less layout using a naive fruchterman-ish
 * iteration entirely in-browser. Data is emitted as JSON and the JS file
 * picks it up.
 *
 * Only notes that link to, or are linked from, another note are drawn:
 * an unlinked note has nothing to say about structure and, in a corpus
 * of bookmarks, would drown the notes that do.
 */

use Memex\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$memex_title = __( 'Graph', 'memex' );
include __DIR__ . '/_header.php';

global $wpdb;

$total_notes = (int) $wpdb->get_var(
	$wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','private')",
		CPT::POST_TYPE
	)
);

// Every link row whose both ends are a readable note.
$rows = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT pm.post_id AS `from`, pm.meta_value AS `to`
		 FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} a ON a.ID = pm.post_id
		 INNER JOIN {$wpdb->posts} b ON b.ID = pm.meta_value
		 WHERE pm.meta_key = %s
		   AND a.post_type = %s AND a.post_status IN ('publish','draft','private')
		   AND b.post_type = %s AND b.post_status IN ('publish','draft','private')",
		CPT::META_LINKS_TO,
		CPT::POST_TYPE,
		CPT::POST_TYPE
	)
);

$edges_data = array();
$linked_ids = array();
$seen_edges = array();
foreach ( $rows as $row ) {
	$from = (int) $row->from;
	$to   = (int) $row->to;
	if ( $from === $to || isset( $seen_edges[ $from . '>' . $to ] ) ) {
		continue;
	}
	$seen_edges[ $from . '>' . $to ] = true;
	$edges_data[]                    = array(
		'from' => $from,
		'to'   => $to,
	);
	$linked_ids[ $from ]             = true;
	$linked_ids[ $to ]               = true;
}

$nodes_data = array();
if ( $linked_ids ) {
	$posts = get_posts(
		array(
			'post_type'      => CPT::POST_TYPE,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'post__in'       => array_keys( $linked_ids ),
			'posts_per_page' => -1,
			'orderby'        => 'post__in',
		)
	);
	foreach ( $posts as $post ) {
		$nodes_data[] = array(
			'id'    => (int) $post->ID,
			'title' => $post->post_title,
			'url'   => CPT::url( $post->ID ),
			'stub'  => (bool) get_post_meta( $post->ID, CPT::META_STUB, true ),
		);
	}
}
$unlinked_count = $total_notes - count( $nodes_data );
// The ten most recent unlinked notes; the full set could be most of the corpus.
$unlinked = $unlinked_count ? get_posts(
	array(
		'post_type'      => CPT::POST_TYPE,
		'post_status'    => array( 'publish', 'draft', 'private' ),
		'post__not_in'   => array_keys( $linked_ids ),
		'posts_per_page' => 10,
		'orderby'        => 'date',
		'order'          => 'DESC',
	)
) : array();
?>

<header class="memex-page-header">
	<h1 id="note-graph-heading"><?php esc_html_e( 'Graph', 'memex' ); ?></h1>
	<p class="memex-muted">
		<?php
		printf(
			/* translators: 1: linked note count, 2: link count, 3: unlinked note count */
			esc_html__( '%1$d linked notes, %2$d links; %3$d notes without links are not shown.', 'memex' ),
			count( $nodes_data ),
			count( $edges_data ),
			(int) $unlinked_count
		);
		?>
	</p>
</header>

<section id="note-graph" aria-labelledby="note-graph-heading" data-ai-assistant-important>
	<?php if ( $nodes_data ) : ?>
		<div id="memex-graph" role="img" aria-label="<?php esc_attr_e( 'Note graph', 'memex' ); ?>" data-graph="<?php echo esc_attr( wp_json_encode( array( 'nodes' => $nodes_data, 'edges' => $edges_data ) ) ); ?>" style="height: 70vh;"></div>
	<?php else : ?>
		<p class="memex-muted">
			<?php
			if ( $total_notes ) {
				printf(
					/* translators: %s: URL of the tag browser */
					wp_kses( __( 'None of your notes link to another note yet, so there is no graph to draw. Link notes to each other with <code>[[</code> in the editor, or browse them <a href="%s">by tag</a>.', 'memex' ), array( 'a' => array( 'href' => array() ), 'code' => array() ) ),
					esc_url( home_url( '/memex/tags' ) )
				);
			} else {
				esc_html_e( 'No notes to graph yet.', 'memex' );
			}
			?>
		</p>
	<?php endif; ?>
</section>

<?php if ( $unlinked ) : ?>
<section id="note-graph-unlinked" aria-labelledby="note-graph-unlinked-heading">
	<h2 id="note-graph-unlinked-heading">
		<?php
		if ( $unlinked_count > count( $unlinked ) ) {
			printf(
				/* translators: 1: notes shown, 2: total notes without links */
				esc_html__( 'Latest %1$d of %2$d notes without links', 'memex' ),
				count( $unlinked ),
				(int) $unlinked_count
			);
		} else {
			printf(
				/* translators: %d: number of notes without links */
				esc_html( _n( '%d note without links', '%d notes without links', $unlinked_count, 'memex' ) ),
				(int) $unlinked_count
			);
		}
		?>
	</h2>
	<ul class="memex-graph-unlinked">
		<?php foreach ( $unlinked as $post ) : ?>
			<li><a href="<?php echo esc_url( CPT::url( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ? $post->post_title : __( '(untitled)', 'memex' ) ); ?></a></li>
		<?php endforeach; ?>
	</ul>
</section>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
