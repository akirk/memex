<?php
/**
 * Search results.
 */

use Memex\CPT;
use Memex\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$q           = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$memex_title = $q ? sprintf( /* translators: %s: query */ __( 'Search: %s', 'memex' ), $q ) : __( 'Search', 'memex' );
include __DIR__ . '/_header.php';
$results = $q ? Search::query( $q, 100 ) : array();
?>

<header class="memex-page-header">
	<h1><?php esc_html_e( 'Search', 'memex' ); ?></h1>
	<form class="memex-search" method="get" action="<?php echo esc_url( home_url( '/memex/search' ) ); ?>">
		<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Search your notes…', 'memex' ); ?>" autofocus>
		<button type="submit"><?php esc_html_e( 'Search', 'memex' ); ?></button>
	</form>
</header>

<?php if ( '' === $q ) : ?>
	<p class="memex-muted"><?php esc_html_e( 'Enter a query to search across note titles and content.', 'memex' ); ?></p>
<?php elseif ( ! $results ) : ?>
	<p class="memex-muted">
		<?php
		printf(
			/* translators: %s: search query */
			esc_html__( 'No notes match "%s".', 'memex' ),
			esc_html( $q )
		);
		?>
	</p>
<?php else : ?>
	<p class="memex-muted">
		<?php
		printf(
			/* translators: 1: number of results 2: query */
			esc_html( _n( '%1$d match for "%2$s"', '%1$d matches for "%2$s"', count( $results ), 'memex' ) ),
			count( $results ),
			esc_html( $q )
		);
		?>
	</p>
	<ul class="memex-note-list">
		<?php foreach ( $results as $post ) : ?>
			<li class="memex-note-list-item">
				<a class="memex-note-list-title" href="<?php echo esc_url( CPT::url( $post ) ); ?>"><?php echo esc_html( $post->post_title ); ?></a>
				<p class="memex-note-list-excerpt"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '…' ) ); ?></p>
				<div class="memex-note-list-meta"><?php echo esc_html( get_the_modified_date( '', $post ) ); ?></div>
			</li>
		<?php endforeach; ?>
	</ul>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
