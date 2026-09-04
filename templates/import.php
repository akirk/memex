<?php
/**
 * Import form + result display.
 */

use Memex\CPT;
use Memex\Importer\Job;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$memex_title = __( 'Import', 'memex' );
include __DIR__ . '/_header.php';

$active     = Job::active_for( get_current_user_id() );
$max_upload = wp_max_upload_size();
?>

<header class="memex-page-header">
	<h1 id="import-notes-heading"><?php esc_html_e( 'Import notes', 'memex' ); ?></h1>
	<p class="memex-muted"><?php esc_html_e( 'Bring in your knowledge from another app. Wiki-links, tags, and folder hierarchy are preserved.', 'memex' ); ?></p>
</header>

<div class="memex-import-progress" id="memex-import-progress" hidden>
	<p class="memex-import-progress-label" aria-live="polite"></p>
	<progress max="100" value="0"></progress>
	<p class="memex-muted memex-import-progress-detail"></p>
</div>

<div class="memex-notice memex-notice-success memex-import-result" id="memex-import-result" role="status" hidden>
	<p class="memex-import-result-summary"></p>
	<details hidden>
		<summary></summary>
		<ul></ul>
	</details>
</div>

<div class="memex-notice memex-notice-error memex-import-error" id="memex-import-error" role="alert" hidden>
	<p></p>
	<p><button type="button" class="memex-button" data-import-retry><?php esc_html_e( 'Resume', 'memex' ); ?></button></p>
</div>

<?php if ( $active ) : ?>
	<?php $status = $active->status(); ?>
	<div class="memex-notice memex-notice-error memex-import-resume" id="memex-import-resume" role="status" data-job="<?php echo esc_attr( $status['job'] ); ?>" data-phase="<?php echo esc_attr( $status['phase'] ); ?>" data-done="<?php echo (int) $status['done']; ?>" data-total="<?php echo (int) $status['total']; ?>" data-file="<?php echo esc_attr( $status['file'] ); ?>">
		<p>
			<?php
			printf(
				/* translators: 1: file name, 2: items done, 3: items total */
				esc_html__( 'An import of %1$s was interrupted (%2$d of %3$d done). You can resume it, or discard it and start over.', 'memex' ),
				'<strong>' . esc_html( $status['file'] ) . '</strong>',
				(int) $status['done'],
				(int) $status['total']
			);
			?>
		</p>
		<p>
			<button type="button" class="memex-button memex-button-primary" data-import-resume><?php esc_html_e( 'Resume import', 'memex' ); ?></button>
			<button type="button" class="memex-button" data-import-discard><?php esc_html_e( 'Discard', 'memex' ); ?></button>
		</p>
	</div>
<?php endif; ?>

<form class="memex-import-form" id="memex-import-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" aria-labelledby="import-notes-heading" data-ai-assistant-important
	data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
	data-nonce="<?php echo esc_attr( wp_create_nonce( 'memex_import' ) ); ?>"
	<?php /* translators: %s: name of the file being uploaded. */ ?>
	data-i18n-uploading="<?php esc_attr_e( 'Uploading %s…', 'memex' ); ?>"
	<?php /* translators: %s: name of the file being read. */ ?>
	data-i18n-preparing="<?php esc_attr_e( 'Reading %s…', 'memex' ); ?>"
	<?php /* translators: %s: number of notes found so far. */ ?>
	data-i18n-found="<?php esc_attr_e( '%s notes found so far', 'memex' ); ?>"
	data-i18n-importing="<?php esc_attr_e( 'Importing notes…', 'memex' ); ?>"
	data-i18n-resuming="<?php esc_attr_e( 'Resuming…', 'memex' ); ?>"
	<?php /* translators: 1: current retry number, 2: maximum number of retries. */ ?>
	data-i18n-retrying="<?php esc_attr_e( 'Connection lost, retrying (%1$s of %2$s)…', 'memex' ); ?>"
	data-i18n-links="<?php esc_attr_e( 'Resolving links…', 'memex' ); ?>"
	<?php /* translators: 1: number of notes imported, 2: total number of notes. */ ?>
	data-i18n-progress="<?php esc_attr_e( '%1$s of %2$s', 'memex' ); ?>"
	<?php /* translators: 1: number of notes imported, 2: name of the imported file. */ ?>
	data-i18n-done="<?php esc_attr_e( 'Imported %1$s notes from %2$s.', 'memex' ); ?>"
	<?php /* translators: %s: number of warnings. */ ?>
	data-i18n-warnings="<?php esc_attr_e( 'View warnings (%s)', 'memex' ); ?>"
	<?php /* translators: %s: error message. */ ?>
	data-i18n-failed="<?php esc_attr_e( 'The import was interrupted: %s', 'memex' ); ?>"
	data-i18n-leave="<?php esc_attr_e( 'An import is still running. Leave anyway?', 'memex' ); ?>"
	<?php echo $active ? 'hidden' : ''; ?>>
	<input type="hidden" name="action" value="memex_import_start">
	<input type="hidden" name="_ajax_nonce" value="<?php echo esc_attr( wp_create_nonce( 'memex_import' ) ); ?>">

	<fieldset class="memex-import-types">
		<legend><?php esc_html_e( 'Import format', 'memex' ); ?></legend>
		<label><input type="radio" name="type" value="auto" checked> <strong><?php esc_html_e( 'Auto-detect', 'memex' ); ?></strong> <span class="memex-muted"><?php esc_html_e( '(recommended)', 'memex' ); ?></span></label>
		<label><input type="radio" name="type" value="obsidian"> Obsidian <span class="memex-muted"><?php esc_html_e( '(ZIP of markdown vault)', 'memex' ); ?></span></label>
		<label><input type="radio" name="type" value="notion"> Notion <span class="memex-muted"><?php esc_html_e( '(HTML or Markdown export ZIP)', 'memex' ); ?></span></label>
		<label><input type="radio" name="type" value="evernote"> Evernote <span class="memex-muted">(.enex)</span></label>
		<label><input type="radio" name="type" value="roam"> Roam Research <span class="memex-muted">(.json)</span></label>
		<label><input type="radio" name="type" value="thinkery"> Thinkery <span class="memex-muted">(.xml <?php esc_html_e( 'or', 'memex' ); ?> .json)</span></label>
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
		<li><strong>Thinkery</strong> — <?php esc_html_e( 'Things (notes and bookmarks) with title, HTML body, tags, and creation date; bookmark URLs are linked at the top of the note.', 'memex' ); ?></li>
	</ul>
	<p class="memex-muted"><?php esc_html_e( 'Attachments (images, PDFs) are skipped in this first pass — the text is the important part.', 'memex' ); ?></p>
</section>

<?php include __DIR__ . '/_footer.php'; ?>
