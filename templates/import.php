<?php
/**
 * Import form + result display.
 */

use Memex\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$memex_title = __( 'Import', 'memex' );
include __DIR__ . '/_header.php';

$result = get_transient( 'memex_import_result_' . get_current_user_id() );
if ( $result ) {
	delete_transient( 'memex_import_result_' . get_current_user_id() );
}
$error = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';

$max_upload = wp_max_upload_size();
?>

<header class="memex-page-header">
	<h1 id="import-notes-heading"><?php esc_html_e( 'Import notes', 'memex' ); ?></h1>
	<p class="memex-muted"><?php esc_html_e( 'Bring in your knowledge from another app. Wiki-links, tags, and folder hierarchy are preserved.', 'memex' ); ?></p>
</header>

<?php if ( $result ) : ?>
	<div class="memex-notice memex-notice-success" role="status">
		<p>
			<?php
			printf(
				/* translators: 1: number of notes, 2: source name */
				esc_html( _n( 'Imported %1$d note from %2$s.', 'Imported %1$d notes from %2$s.', (int) $result['count'], 'memex' ) ),
				(int) $result['count'],
				esc_html( $result['source'] )
			);
			?>
		</p>
		<?php if ( ! empty( $result['errors'] ) ) : ?>
			<details>
				<summary><?php esc_html_e( 'View warnings', 'memex' ); ?> (<?php echo count( $result['errors'] ); ?>)</summary>
				<ul>
					<?php foreach ( array_slice( $result['errors'], 0, 50 ) as $e ) : ?>
						<li><?php echo esc_html( $e ); ?></li>
					<?php endforeach; ?>
				</ul>
			</details>
		<?php endif; ?>
	</div>
<?php elseif ( 'no-file' === $error ) : ?>
	<div class="memex-notice memex-notice-error" role="alert"><p><?php esc_html_e( 'Please select a file to import.', 'memex' ); ?></p></div>
<?php elseif ( 'unknown-type' === $error ) : ?>
	<div class="memex-notice memex-notice-error" role="alert"><p><?php esc_html_e( 'Could not detect the file type. Please pick one explicitly below.', 'memex' ); ?></p></div>
<?php endif; ?>

<form class="memex-import-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" aria-labelledby="import-notes-heading" data-ai-assistant-important>
	<input type="hidden" name="action" value="memex_import">
	<?php wp_nonce_field( 'memex_import' ); ?>

	<fieldset class="memex-import-types">
		<legend><?php esc_html_e( 'Import format', 'memex' ); ?></legend>
		<label><input type="radio" name="type" value="auto" checked> <strong><?php esc_html_e( 'Auto-detect', 'memex' ); ?></strong> <span class="memex-muted"><?php esc_html_e( '(recommended)', 'memex' ); ?></span></label>
		<label><input type="radio" name="type" value="obsidian"> Obsidian <span class="memex-muted"><?php esc_html_e( '(ZIP of markdown vault)', 'memex' ); ?></span></label>
		<label><input type="radio" name="type" value="notion"> Notion <span class="memex-muted"><?php esc_html_e( '(HTML or Markdown export ZIP)', 'memex' ); ?></span></label>
		<label><input type="radio" name="type" value="evernote"> Evernote <span class="memex-muted">(.enex)</span></label>
		<label><input type="radio" name="type" value="roam"> Roam Research <span class="memex-muted">(.json)</span></label>
		<label><input type="radio" name="type" value="markdown"> Markdown <span class="memex-muted"><?php esc_html_e( '(single .md or ZIP)', 'memex' ); ?></span></label>
	</fieldset>

	<div class="memex-upload">
		<label for="memex-import-file"><?php esc_html_e( 'Import file', 'memex' ); ?></label>
		<input id="memex-import-file" type="file" name="import_file" accept=".zip,.enex,.xml,.json,.md,.markdown,.txt" aria-describedby="memex-upload-limit" required>
		<p id="memex-upload-limit" class="memex-muted">
			<?php
			printf(
				/* translators: %s: max upload size */
				esc_html__( 'Max upload size: %s', 'memex' ),
				esc_html( size_format( $max_upload ) )
			);
			?>
		</p>
	</div>

	<button type="submit" class="memex-button memex-button-primary"><?php esc_html_e( 'Import', 'memex' ); ?></button>
</form>

<section class="memex-panel" aria-labelledby="import-details-heading">
	<h2 id="import-details-heading"><?php esc_html_e( 'What gets imported?', 'memex' ); ?></h2>
	<ul>
		<li><strong>Obsidian / Markdown</strong> — <?php esc_html_e( 'YAML frontmatter (title, tags, aliases), #hashtags, [[wiki-links]], and folder hierarchy.', 'memex' ); ?></li>
		<li><strong>Notion</strong> — <?php esc_html_e( 'Page hierarchy, HTML/Markdown body, internal page links rewritten as memex links between notes.', 'memex' ); ?></li>
		<li><strong>Evernote</strong> — <?php esc_html_e( 'Note title, HTML body (ENML), tags, created/updated timestamps.', 'memex' ); ?></li>
		<li><strong>Roam Research</strong> — <?php esc_html_e( 'Pages, nested bullet outline, [[page-links]], #hashtags, TODO/DONE markers.', 'memex' ); ?></li>
	</ul>
	<p class="memex-muted"><?php esc_html_e( 'Attachments (images, PDFs) are skipped in this first pass — the text is the important part.', 'memex' ); ?></p>
</section>

<?php include __DIR__ . '/_footer.php'; ?>
