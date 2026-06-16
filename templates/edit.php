<?php
/**
 * In-app note editor: /memex/edit/{slug}
 */

use Memex\App;
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
	if ( ! $post && ctype_digit( (string) $slug ) ) {
		$p = get_post( (int) $slug );
		if ( $p && CPT::POST_TYPE === $p->post_type ) {
			$post = $p;
		}
	}
	if ( ! $post ) {
		$id = Links::resolve( rawurldecode( $slug ) );
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
	<header class="memex-page-header"><h1 id="memex-page-title"><?php esc_html_e( 'Note not found', 'memex' ); ?></h1></header>
	<p><a href="<?php echo esc_url( home_url( '/memex/' ) ); ?>"><?php esc_html_e( 'All notes', 'memex' ); ?></a></p>
	<?php
	include __DIR__ . '/_footer.php';
	return;
}

$memex_title = sprintf( /* translators: %s: note title */ __( 'Edit %s', 'memex' ), $post->post_title );
if ( ! function_exists( 'wp_text_diff' ) ) {
	require_once ABSPATH . WPINC . '/wp-diff.php';
}
if ( ! function_exists( 'memex_prepare_revision_diff' ) ) {
	function memex_prepare_revision_diff( string $diff, int $revision_number ): string {
		$diff = preg_replace( '/<caption class="diff-title">.*?<\/caption>\s*/s', '', $diff );
		$diff = preg_replace(
			'/(<tr\b[^>]*class="[^"]*\bdiff-sub-title\b[^"]*"[^>]*>\s*)<td\b[^>]*>\s*<\/td>/i',
			'$1<td class="memex-revision-diff-number">' . (int) $revision_number . '</td>',
			$diff,
			1
		);
		if ( false === strpos( $diff, 'memex-revision-diff-number' ) ) {
			$diff = preg_replace(
				'/<td\b[^>]*>\s*<\/td>/i',
				'<td class="memex-revision-diff-number">' . (int) $revision_number . '</td>',
				$diff,
				1
			);
		}
		return $diff;
	}
}
$revisions = current_user_can( 'edit_post', $post->ID ) ? wp_get_post_revisions(
	$post->ID,
	array(
		'orderby' => 'date ID',
		'order'   => 'DESC',
	)
) : array();
$revisions      = array_values( $revisions );
include __DIR__ . '/_header.php';
?>

	<header class="memex-page-header">
		<h1 id="memex-page-title"><?php echo esc_html( $memex_title ); ?></h1>
		<p class="memex-muted">
			<a href="<?php echo esc_url( CPT::url( $post ) ); ?>">&larr; <?php esc_html_e( 'Back to the note', 'memex' ); ?></a>
		</p>
	</header>

	<?php if ( ! current_user_can( 'edit_post', $post->ID ) ) : ?>
		<p class="memex-error" role="alert"><?php esc_html_e( 'You are not allowed to edit this note.', 'memex' ); ?></p>
	<?php else : ?>
		<?php if ( isset( $_GET['error'] ) && 'missing-title' === $_GET['error'] ) : ?>
			<div class="memex-notice memex-notice-error" role="alert"><?php esc_html_e( 'Please enter a title.', 'memex' ); ?></div>
		<?php endif; ?>
		<div class="memex-edit-shell">
			<form class="memex-edit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" aria-labelledby="memex-page-title" data-ai-assistant-important>
				<input type="hidden" name="action" value="memex_update_note">
				<input type="hidden" name="id" value="<?php echo (int) $post->ID; ?>">
				<?php wp_nonce_field( 'memex_update_note_' . $post->ID ); ?>

				<label>
					<span><?php esc_html_e( 'Title', 'memex' ); ?></span>
					<input type="text" name="title" value="<?php echo esc_attr( $post->post_title ); ?>" required>
				</label>

				<label>
					<span><?php esc_html_e( 'Note', 'memex' ); ?></span>
					<div class="memex-markdown-editor" data-memex-markdown-editor></div>
					<textarea name="content" rows="24" autofocus data-memex-markdown-source><?php echo esc_textarea( App::content_to_editor_text( (string) $post->post_content ) ); ?></textarea>
				</label>

				<div class="memex-edit-actions">
					<button type="submit" class="memex-button memex-button-primary"><?php esc_html_e( 'Save note', 'memex' ); ?></button>
					<a class="memex-button" href="<?php echo esc_url( CPT::url( $post ) ); ?>"><?php esc_html_e( 'Cancel', 'memex' ); ?></a>
				</div>
			</form>

			<aside class="memex-edit-revisions" aria-labelledby="memex-edit-revisions-heading" data-memex-revisions>
				<h2 id="memex-edit-revisions-heading"><?php esc_html_e( 'Revisions', 'memex' ); ?></h2>
				<?php if ( ! $revisions ) : ?>
					<p class="memex-muted"><?php esc_html_e( 'No revisions yet.', 'memex' ); ?></p>
				<?php else : ?>
					<ol class="memex-edit-revision-list">
						<?php foreach ( $revisions as $revision ) : ?>
							<?php
							$author = get_the_author_meta( 'display_name', (int) $revision->post_author );
							$date   = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $revision->post_modified );
							?>
							<li>
								<button type="button" data-memex-revision-trigger="<?php echo (int) $revision->ID; ?>" aria-controls="memex-revision-diff-<?php echo (int) $revision->ID; ?>" aria-expanded="false">
									<span><?php echo esc_html( $date ); ?></span>
									<?php if ( $author ) : ?>
										<small><?php echo esc_html( $author ); ?></small>
									<?php endif; ?>
								</button>
							</li>
						<?php endforeach; ?>
					</ol>

					<div class="memex-revision-diffs" data-memex-revision-diffs>
						<p class="memex-muted" data-memex-revision-empty><?php esc_html_e( 'Select a revision to see its diff.', 'memex' ); ?></p>
						<?php foreach ( $revisions as $revision ) : ?>
							<?php
							$title_diff      = memex_prepare_revision_diff(
								wp_text_diff(
									(string) $revision->post_title,
									(string) $post->post_title,
									array(
										'title'       => __( 'Revision title', 'memex' ),
										'title_right' => __( 'Current title', 'memex' ),
									)
								),
								(int) $revision->ID
							);
							$content_diff    = memex_prepare_revision_diff(
								wp_text_diff(
									App::content_to_editor_text( (string) $revision->post_content ),
									App::content_to_editor_text( (string) $post->post_content ),
									array(
										'title'       => __( 'Revision note', 'memex' ),
										'title_right' => __( 'Current note', 'memex' ),
									)
								),
								(int) $revision->ID
							);
							?>
							<div id="memex-revision-diff-<?php echo (int) $revision->ID; ?>" class="memex-revision-diff" data-memex-revision-panel="<?php echo (int) $revision->ID; ?>" hidden>
								<?php if ( $title_diff ) : ?>
									<div class="memex-diff-block"><?php echo $title_diff; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
								<?php endif; ?>
								<?php if ( $content_diff ) : ?>
									<div class="memex-diff-block"><?php echo $content_diff; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
								<?php endif; ?>
								<?php if ( ! $title_diff && ! $content_diff ) : ?>
									<p class="memex-muted"><?php esc_html_e( 'No differences from the current note.', 'memex' ); ?></p>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</aside>
		</div>
	<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
