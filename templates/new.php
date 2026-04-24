<?php
/**
 * Quick-create form: /memex/new?title=...
 */

use Memex\WikiLinks;
use Memex\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$title = isset( $_GET['title'] ) ? sanitize_text_field( wp_unslash( $_GET['title'] ) ) : '';

$memex_title = __( 'New note', 'memex' );
include __DIR__ . '/_header.php';

$existing = $title ? WikiLinks::resolve( $title ) : 0;
if ( $existing ) :
	?>
	<p>
		<?php
		printf(
			/* translators: %s: note title */
			wp_kses_post( __( 'A note titled "%s" already exists.', 'memex' ) ),
			'<a href="' . esc_url( CPT::url( $existing ) ) . '">' . esc_html( $title ) . '</a>'
		);
		?>
	</p>
<?php else : ?>
	<form class="memex-create-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="memex_create_note">
		<?php wp_nonce_field( 'memex_create_note' ); ?>
		<label><?php esc_html_e( 'Title', 'memex' ); ?>
			<input type="text" name="title" value="<?php echo esc_attr( $title ); ?>" autofocus required>
		</label>
		<button type="submit"><?php esc_html_e( 'Create and edit →', 'memex' ); ?></button>
	</form>
<?php endif; ?>

<?php include __DIR__ . '/_footer.php'; ?>
