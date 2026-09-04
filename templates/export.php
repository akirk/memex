<?php
/**
 * Export page: download all notes as a ZIP of Markdown files.
 */

use Memex\Exporter;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$memex_title = __( 'Export', 'memex' );
include __DIR__ . '/_header.php';

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag that only picks which notice to render.
$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
$count = count( Exporter::notes() );
?>

<header class="memex-page-header">
	<h1 id="export-notes-heading"><?php esc_html_e( 'Export notes', 'memex' ); ?></h1>
	<p class="memex-muted"><?php esc_html_e( 'Take your notes with you as plain Markdown files. The archive opens as an Obsidian vault and can be imported back into Memex.', 'memex' ); ?></p>
</header>

<?php if ( 'memex_no_zip' === $error ) : ?>
	<div class="memex-notice memex-notice-error" role="alert"><p><?php esc_html_e( 'ZIP support is not available on this server, so the archive could not be created. You can still download single notes from their page.', 'memex' ); ?></p></div>
<?php elseif ( '' !== $error ) : ?>
	<div class="memex-notice memex-notice-error" role="alert"><p><?php esc_html_e( 'The export archive could not be created.', 'memex' ); ?></p></div>
<?php endif; ?>

<form class="memex-import-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" aria-labelledby="export-notes-heading" data-ai-assistant-important>
	<input type="hidden" name="action" value="memex_export">
	<?php wp_nonce_field( 'memex_export' ); ?>
	<p>
		<?php
		printf(
			/* translators: %d: number of notes */
			esc_html( _n( '%d note will be exported.', '%d notes will be exported.', $count, 'memex' ) ),
			(int) $count
		);
		?>
	</p>
	<button type="submit" class="memex-button memex-button-primary" <?php disabled( 0 === $count ); ?>><?php esc_html_e( 'Download ZIP', 'memex' ); ?></button>
</form>

<section class="memex-panel" aria-labelledby="export-details-heading">
	<h2 id="export-details-heading"><?php esc_html_e( 'What gets exported?', 'memex' ); ?></h2>
	<ul>
		<li><?php esc_html_e( 'One .md file per note, with YAML frontmatter: title, tags, aliases, created and updated dates, and the daily-note date.', 'memex' ); ?></li>
		<li><?php esc_html_e( 'Links between notes are written as [[Note Title]] wiki-links.', 'memex' ); ?></li>
		<li><?php esc_html_e( 'Child notes are placed in a folder named after their parent note.', 'memex' ); ?></li>
		<li><?php esc_html_e( 'Stub notes (created by links but never written) are skipped.', 'memex' ); ?></li>
	</ul>
	<p class="memex-muted"><?php esc_html_e( 'Attachments and reminders are not part of the export.', 'memex' ); ?></p>
</section>

<?php include __DIR__ . '/_footer.php'; ?>
