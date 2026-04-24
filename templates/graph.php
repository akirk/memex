<?php
/**
 * Simple force-directed graph of notes and their [[wiki-links]].
 *
 * Renders a static SVG via D3-less layout using a naive fruchterman-ish
 * iteration entirely in-browser. Data is emitted as JSON and the JS file
 * picks it up.
 */

use Memex\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$memex_title = __( 'Graph', 'memex' );
include __DIR__ . '/_header.php';

$nodes_data = array();
$edges_data = array();

$all = get_posts(
	array(
		'post_type'      => CPT::POST_TYPE,
		'post_status'    => array( 'publish', 'draft', 'private' ),
		'posts_per_page' => 500,
		'fields'         => 'ids',
	)
);
foreach ( $all as $id ) {
	$post         = get_post( $id );
	$nodes_data[] = array(
		'id'    => (int) $id,
		'title' => $post ? $post->post_title : '',
		'url'   => CPT::url( $id ),
		'stub'  => (bool) get_post_meta( $id, CPT::META_STUB, true ),
	);
	$targets = get_post_meta( $id, CPT::META_LINKS_TO );
	foreach ( $targets as $t ) {
		$edges_data[] = array( 'from' => (int) $id, 'to' => (int) $t );
	}
}
?>

<header class="memex-page-header">
	<h1><?php esc_html_e( 'Graph', 'memex' ); ?></h1>
	<p class="memex-muted">
		<?php
		printf(
			/* translators: 1: node count, 2: edge count */
			esc_html__( '%1$d notes, %2$d links', 'memex' ),
			count( $nodes_data ),
			count( $edges_data )
		);
		?>
	</p>
</header>

<div id="memex-graph" data-graph="<?php echo esc_attr( wp_json_encode( array( 'nodes' => $nodes_data, 'edges' => $edges_data ) ) ); ?>" style="height: 70vh;"></div>

<?php include __DIR__ . '/_footer.php'; ?>
