<?php
/**
 * All-notes landing page.
 */
use Memex\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$memex_title = __( 'All Notes', 'memex' );
include __DIR__ . '/_header.php';

$paged = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
$q     = new WP_Query(
	array(
		'post_type'      => CPT::POST_TYPE,
		'post_status'    => CPT::readable_statuses(),
		'posts_per_page' => 25,
		'orderby'        => 'modified',
		'order'          => 'DESC',
		'paged'          => $paged,
	)
);

$counts        = wp_count_posts( CPT::POST_TYPE );
$total_notes   = (int) ( $counts->publish ?? 0 ) + (int) ( $counts->draft ?? 0 ) + (int) ( $counts->private ?? 0 ) + (int) ( $counts->pending ?? 0 );
?>
<header class="memex-page-header">
	<h1><?php echo esc_html( $memex_title ); ?></h1>
	<p class="memex-muted">
		<?php
		printf(
			/* translators: %d is the number of notes */
			esc_html( _n( '%d note in your memex', '%d notes in your memex', $total_notes, 'memex' ) ),
			(int) $total_notes
		);
		?>
	</p>
</header>

<?php if ( ! $q->have_posts() ) : ?>
	<div class="memex-empty">
		<h2><?php esc_html_e( 'Your memex is empty.', 'memex' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s is a link to the import page */
				wp_kses_post( __( 'Create your first note on the left, or %s from Obsidian, Notion, Evernote, or Roam.', 'memex' ) ),
				'<a href="' . esc_url( home_url( '/memex/import' ) ) . '">' . esc_html__( 'import an existing vault', 'memex' ) . '</a>'
			);
			?>
		</p>
	</div>
<?php else : ?>
	<section id="recent-notes" aria-labelledby="recent-notes-heading" data-ai-assistant-important>
		<h2 id="recent-notes-heading" class="screen-reader-text"><?php esc_html_e( 'Recent notes', 'memex' ); ?></h2>
		<ul class="memex-note-list">
		<?php
		while ( $q->have_posts() ) :
			$q->the_post();
			$post    = get_post();
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '…' );
			$is_stub = (bool) get_post_meta( $post->ID, CPT::META_STUB, true );
			$daily   = (string) get_post_meta( $post->ID, CPT::META_DAILY, true );
			$tags    = get_the_terms( $post->ID, CPT::TAXONOMY );
			?>
			<li class="memex-note-list-item <?php echo $is_stub ? 'is-stub' : ''; ?>" data-note-id="<?php echo (int) $post->ID; ?>" data-note-status="<?php echo esc_attr( $post->post_status ); ?>">
				<a class="memex-note-list-title" href="<?php echo esc_url( CPT::url( $post ) ); ?>">
					<?php echo esc_html( $post->post_title ); ?>
					<?php if ( $daily ) : ?>
						<span class="memex-badge memex-badge-daily"><?php esc_html_e( 'daily', 'memex' ); ?></span>
					<?php endif; ?>
					<?php if ( $is_stub ) : ?>
						<span class="memex-badge memex-badge-stub"><?php esc_html_e( 'stub', 'memex' ); ?></span>
					<?php endif; ?>
				</a>
				<?php if ( $excerpt ) : ?>
					<p class="memex-note-list-excerpt"><?php echo esc_html( $excerpt ); ?></p>
				<?php endif; ?>
				<div class="memex-note-list-meta">
					<span><?php echo esc_html( get_the_modified_date( '', $post ) ); ?></span>
					<?php if ( $is_stub ) : ?>
						<span><?php echo esc_html( sprintf( /* translators: %1$d: note ID, %2$s: post status */ __( 'ID %1$d · %2$s', 'memex' ), (int) $post->ID, $post->post_status ) ); ?></span>
					<?php endif; ?>
					<?php if ( $tags ) : ?>
						<span class="memex-tags">
							<?php foreach ( $tags as $t ) : ?>
								<a href="<?php echo esc_url( home_url( '/memex/tag/' . $t->slug ) ); ?>">#<?php echo esc_html( $t->name ); ?></a>
							<?php endforeach; ?>
						</span>
					<?php endif; ?>
				</div>
			</li>
		<?php endwhile; ?>
		</ul>
	</section>

	<?php if ( $q->max_num_pages > 1 ) : ?>
		<nav class="memex-pagination">
			<?php if ( $paged > 1 ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'paged', $paged - 1, home_url( '/memex/' ) ) ); ?>">&larr; <?php esc_html_e( 'Newer', 'memex' ); ?></a>
			<?php endif; ?>
			<span class="memex-muted">
				<?php
				printf(
					/* translators: %1$d is the current page, %2$d is the last page */
					esc_html__( 'Page %1$d of %2$d', 'memex' ),
					(int) $paged,
					(int) $q->max_num_pages
				);
				?>
			</span>
			<?php if ( $paged < $q->max_num_pages ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'paged', $paged + 1, home_url( '/memex/' ) ) ); ?>"><?php esc_html_e( 'Older', 'memex' ); ?> &rarr;</a>
			<?php endif; ?>
		</nav>
	<?php endif; ?>
<?php endif; ?>
<?php
wp_reset_postdata();
include __DIR__ . '/_footer.php';
