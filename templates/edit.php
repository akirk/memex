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
include __DIR__ . '/_header.php';
?>

<header class="memex-page-header">
	<h1 id="memex-page-title"><?php echo esc_html( $memex_title ); ?></h1>
	<p class="memex-muted"><a href="<?php echo esc_url( CPT::url( $post ) ); ?>">&larr; <?php esc_html_e( 'Back to the note', 'memex' ); ?></a></p>
</header>

<?php if ( ! current_user_can( 'edit_post', $post->ID ) ) : ?>
	<p class="memex-error" role="alert"><?php esc_html_e( 'You are not allowed to edit this note.', 'memex' ); ?></p>
<?php else : ?>
	<?php if ( isset( $_GET['error'] ) && 'missing-title' === $_GET['error'] ) : ?>
		<div class="memex-notice memex-notice-error" role="alert"><?php esc_html_e( 'Please enter a title.', 'memex' ); ?></div>
	<?php endif; ?>
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
			<textarea name="content" rows="24" autofocus><?php echo esc_textarea( App::content_to_editor_text( (string) $post->post_content ) ); ?></textarea>
		</label>

		<div class="memex-edit-actions">
			<button type="submit" class="memex-button memex-button-primary"><?php esc_html_e( 'Save note', 'memex' ); ?></button>
			<a class="memex-button" href="<?php echo esc_url( CPT::url( $post ) ); ?>"><?php esc_html_e( 'Cancel', 'memex' ); ?></a>
		</div>
	</form>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
