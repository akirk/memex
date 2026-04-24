<?php
/**
 * Single-note view.
 *
 * Route var: `slug` (resolved by WpApp from /memex/note/{slug}).
 */

use Memex\CPT;
use Memex\WikiLinks;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$slug = wp_app_get_route_var( 'slug' );
$post = null;

if ( $slug ) {
	// Try exact slug (incl. `daily-YYYY-MM-DD`).
	$q = get_posts(
		array(
			'post_type'        => CPT::POST_TYPE,
			'name'             => $slug,
			'post_status'      => array( 'publish', 'draft', 'private' ),
			'posts_per_page'   => 1,
			'suppress_filters' => false,
		)
	);
	$post = $q ? $q[0] : null;

	// Fallback: numeric ID.
	if ( ! $post && ctype_digit( $slug ) ) {
		$p = get_post( (int) $slug );
		if ( $p && CPT::POST_TYPE === $p->post_type ) {
			$post = $p;
		}
	}

	// Fallback: resolve by title (case-insensitive).
	if ( ! $post ) {
		$id = WikiLinks::resolve( rawurldecode( $slug ) );
		if ( $id ) {
			$post = get_post( $id );
		}
	}
}

if ( ! $post ) {
	status_header( 404 );
	$memex_title = __( 'Note not found', 'memex' );
	include __DIR__ . '/_header.php';
	?>
	<header class="memex-page-header"><h1><?php esc_html_e( 'Note not found', 'memex' ); ?></h1></header>
	<p>
		<?php
		printf(
			/* translators: %s is the slug the user was looking for */
			esc_html__( 'No note with slug or title "%s".', 'memex' ),
			esc_html( (string) $slug )
		);
		?>
	</p>
	<p>
		<a class="memex-button" href="<?php echo esc_url( add_query_arg( 'title', rawurlencode( (string) $slug ), home_url( '/memex/new' ) ) ); ?>">
			<?php esc_html_e( 'Create this note', 'memex' ); ?>
		</a>
	</p>
	<?php
	include __DIR__ . '/_footer.php';
	return;
}

$memex_title = $post->post_title;
include __DIR__ . '/_header.php';

$is_stub      = (bool) get_post_meta( $post->ID, CPT::META_STUB, true );
$daily        = (string) get_post_meta( $post->ID, CPT::META_DAILY, true );
$parent       = $post->post_parent ? get_post( $post->post_parent ) : null;
$children     = get_posts(
	array(
		'post_type'      => CPT::POST_TYPE,
		'post_parent'    => $post->ID,
		'posts_per_page' => 50,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'post_status'    => array( 'publish', 'draft', 'private' ),
	)
);
$backlinks    = WikiLinks::get_backlinks( (int) $post->ID );
$forward      = WikiLinks::get_forward_links( (int) $post->ID );
$tags         = get_the_terms( $post->ID, CPT::TAXONOMY );
$edit_link    = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
$import_src   = (string) get_post_meta( $post->ID, CPT::META_IMPORT_SOURCE, true );
?>

<article class="memex-note">
	<?php if ( $parent ) : ?>
		<nav class="memex-breadcrumb">
			<a href="<?php echo esc_url( CPT::url( $parent ) ); ?>">&larr; <?php echo esc_html( $parent->post_title ); ?></a>
		</nav>
	<?php endif; ?>

	<header class="memex-note-header">
		<h1>
			<?php echo esc_html( $post->post_title ); ?>
			<?php if ( $is_stub ) : ?>
				<span class="memex-badge memex-badge-stub"><?php esc_html_e( 'stub', 'memex' ); ?></span>
			<?php endif; ?>
			<?php if ( $daily ) : ?>
				<span class="memex-badge memex-badge-daily"><?php esc_html_e( 'daily', 'memex' ); ?></span>
			<?php endif; ?>
		</h1>
		<div class="memex-note-meta">
			<span><?php echo esc_html( get_the_modified_date( '', $post ) ); ?></span>
			<?php if ( $import_src ) : ?>
				<span class="memex-import-src"><?php echo esc_html( sprintf( /* translators: %s: import source name */ __( 'imported from %s', 'memex' ), $import_src ) ); ?></span>
			<?php endif; ?>
			<a class="memex-edit-link" href="<?php echo esc_url( $edit_link ); ?>"><?php esc_html_e( 'Edit', 'memex' ); ?></a>
			<?php if ( $tags ) : ?>
				<span class="memex-tags">
					<?php foreach ( $tags as $t ) : ?>
						<a href="<?php echo esc_url( home_url( '/memex/tag/' . $t->slug ) ); ?>">#<?php echo esc_html( $t->name ); ?></a>
					<?php endforeach; ?>
				</span>
			<?php endif; ?>
		</div>
	</header>

	<div class="memex-note-body">
		<?php
		if ( '' === trim( $post->post_content ) ) {
			echo '<p class="memex-muted"><em>' . esc_html__( 'This note is empty.', 'memex' ) . '</em> <a href="' . esc_url( $edit_link ) . '">' . esc_html__( 'Start writing →', 'memex' ) . '</a></p>';
		} else {
			echo apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		?>
	</div>

	<?php if ( $children ) : ?>
		<section class="memex-panel memex-children">
			<h2><?php esc_html_e( 'Nested notes', 'memex' ); ?></h2>
			<ul>
				<?php foreach ( $children as $c ) : ?>
					<li><a href="<?php echo esc_url( CPT::url( $c ) ); ?>"><?php echo esc_html( $c->post_title ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<?php if ( $forward ) : ?>
		<section class="memex-panel memex-forward">
			<h2><?php esc_html_e( 'Links from here', 'memex' ); ?> <span class="memex-muted">(<?php echo count( $forward ); ?>)</span></h2>
			<ul>
				<?php foreach ( $forward as $f ) : ?>
					<li><a href="<?php echo esc_url( CPT::url( $f ) ); ?>"><?php echo esc_html( $f->post_title ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</section>
	<?php endif; ?>

	<section class="memex-panel memex-backlinks">
		<h2>
			<?php esc_html_e( 'Linked mentions', 'memex' ); ?>
			<span class="memex-muted">(<?php echo count( $backlinks ); ?>)</span>
		</h2>
		<?php if ( $backlinks ) : ?>
			<ul>
				<?php foreach ( $backlinks as $b ) : ?>
					<li>
						<a class="memex-backlink-title" href="<?php echo esc_url( CPT::url( $b ) ); ?>"><?php echo esc_html( $b->post_title ); ?></a>
						<p class="memex-backlink-context"><?php echo esc_html( memex_snippet_around( $b->post_content, $post->post_title ) ); ?></p>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="memex-muted"><?php esc_html_e( 'No notes link to this one yet.', 'memex' ); ?></p>
		<?php endif; ?>
	</section>
</article>

<?php
if ( ! function_exists( 'memex_snippet_around' ) ) {
	function memex_snippet_around( string $haystack, string $needle, int $pad = 50 ): string {
		$text = wp_strip_all_tags( $haystack );
		$text = preg_replace( '/\[\[([^\]\|]+?)(?:\|([^\]]+?))?\]\]/', '$1', $text );
		$pos  = stripos( $text, $needle );
		if ( false === $pos ) {
			return wp_trim_words( $text, 20, '…' );
		}
		$start = max( 0, $pos - $pad );
		$end   = min( strlen( $text ), $pos + strlen( $needle ) + $pad );
		$snip  = substr( $text, $start, $end - $start );
		if ( $start > 0 ) {
			$snip = '…' . ltrim( $snip );
		}
		if ( $end < strlen( $text ) ) {
			$snip = rtrim( $snip ) . '…';
		}
		return $snip;
	}
}
include __DIR__ . '/_footer.php';
