<?php
/**
 * In-app note editor: /memex/edit/{slug}
 */

use Memex\App;
use Memex\CPT;
use Memex\Links;
use Memex\RevisionDiff;

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
		<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag that only picks which notice to render. ?>
		<?php if ( isset( $_GET['error'] ) && 'missing-title' === sanitize_key( wp_unslash( $_GET['error'] ) ) ) : ?>
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
					<span><?php esc_html_e( 'Nested under', 'memex' ); ?></span>
					<?php
					// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes the markup it prints.
					wp_dropdown_pages(
						array(
							'post_type'        => CPT::POST_TYPE,
							'post_status'      => CPT::readable_statuses(),
							'name'             => 'parent',
							'selected'         => (int) $post->post_parent,
							'exclude_tree'     => (int) $post->ID,
							'show_option_none' => __( '— Top level —', 'memex' ),
							'sort_column'      => 'post_title',
						)
					);
					// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
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
					<p class="memex-muted" data-memex-revision-empty><?php esc_html_e( 'Select a revision to see its diff.', 'memex' ); ?></p>
					<ol class="memex-edit-revision-list">
						<?php
						// The saved note itself, so the list starts at the last save
						// (the newest revision is a copy of it and is skipped below).
						// Its icon brings back whatever was in the form before an
						// older revision was loaded.
						$current_date = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post->post_modified );
						?>
						<li class="memex-edit-revision-current">
							<div class="memex-edit-revision-row">
								<div class="memex-edit-revision-label" title="<?php echo esc_attr( $current_date ); ?>">
									<span>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: human-readable time difference */
												__( '%s ago', 'memex' ),
												human_time_diff( mysql2date( 'U', $post->post_modified ), current_time( 'timestamp' ) )
											)
										);
										?>
									</span>
									<small><?php esc_html_e( 'Current version', 'memex' ); ?></small>
								</div>
								<button type="button" class="memex-edit-revision-load" data-memex-revision-load data-memex-revision-current data-title="<?php echo esc_attr( (string) $post->post_title ); ?>" data-content="<?php echo esc_attr( App::content_to_editor_text( (string) $post->post_content ) ); ?>" data-confirm="<?php esc_attr_e( 'Discard the changes made since loading that revision?', 'memex' ); ?>" title="<?php esc_attr_e( 'Back to the current version, including your unsaved edits', 'memex' ); ?>" aria-label="<?php esc_attr_e( 'Back to the current version', 'memex' ); ?>" hidden>
									<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M21 12a9 9 0 1 1-3-6.7"/><polyline points="21 3 21 9 15 9"/></svg>
								</button>
							</div>
						</li>
						<?php foreach ( $revisions as $revision ) : ?>
							<?php
							$title_diff   = RevisionDiff::render(
								(string) $revision->post_title,
								(string) $post->post_title,
								__( 'Current title', 'memex' ),
								(int) $revision->ID
							);
							$content_diff = RevisionDiff::render(
								App::content_to_editor_text( (string) $revision->post_content ),
								App::content_to_editor_text( (string) $post->post_content ),
								__( 'Current note', 'memex' ),
								(int) $revision->ID
							);
							$date          = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $revision->post_modified );
							$relative_date = sprintf(
								/* translators: %s: human-readable time difference */
								__( '%s ago', 'memex' ),
								human_time_diff( mysql2date( 'U', $revision->post_modified ), current_time( 'timestamp' ) )
							);
							if ( ! $title_diff && ! $content_diff ) {
								continue;
							}
							?>
							<li>
								<div class="memex-edit-revision-row">
									<button type="button" data-memex-revision-trigger="<?php echo (int) $revision->ID; ?>" aria-controls="memex-revision-diff-<?php echo (int) $revision->ID; ?>" aria-expanded="false" title="<?php echo esc_attr( $date ); ?>">
										<span><?php echo esc_html( $relative_date ); ?></span>
									</button>
									<button type="button" class="memex-edit-revision-load" data-memex-revision-load data-title="<?php echo esc_attr( (string) $revision->post_title ); ?>" data-content="<?php echo esc_attr( App::content_to_editor_text( (string) $revision->post_content ) ); ?>" data-confirm="<?php esc_attr_e( 'Discard the changes made since loading the previous revision?', 'memex' ); ?>" title="<?php esc_attr_e( 'Load this revision into the editor (nothing is saved until you click Save note)', 'memex' ); ?>" aria-label="<?php esc_attr_e( 'Load this revision into the editor', 'memex' ); ?>">
										<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 3 3 9 9 9"/></svg>
									</button>
								</div>
								<div id="memex-revision-diff-<?php echo (int) $revision->ID; ?>" class="memex-revision-diff" data-memex-revision-panel="<?php echo (int) $revision->ID; ?>" hidden>
									<?php if ( $title_diff ) : ?>
										<div class="memex-diff-block"><?php echo $title_diff; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
									<?php endif; ?>
									<?php if ( $content_diff ) : ?>
										<div class="memex-diff-block"><?php echo $content_diff; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
									<?php endif; ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
			</aside>
		</div>
	<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
