<?php
/**
 * `/memex/reminders` — list and create reminders.
 */

use Memex\CPT;
use Memex\Reminder;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$memex_title = __( 'Reminders', 'memex' );
include __DIR__ . '/_header.php';

$groups = Reminder::for_current_user();
$error  = isset( $_GET['error'] ) ? sanitize_key( $_GET['error'] ) : '';
?>
<header class="memex-page-header">
	<h1><?php esc_html_e( 'Reminders', 'memex' ); ?></h1>
	<p class="memex-muted"><?php esc_html_e( "You'll get an email when each reminder is due.", 'memex' ); ?></p>
</header>

<?php if ( 'missing' === $error ) : ?>
	<p class="memex-error"><?php esc_html_e( 'Please give the reminder both a label and a due date.', 'memex' ); ?></p>
<?php elseif ( 'baddate' === $error ) : ?>
	<p class="memex-error"><?php esc_html_e( 'Could not parse that due date.', 'memex' ); ?></p>
<?php endif; ?>

<form class="memex-reminder-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="memex_create_reminder">
	<?php wp_nonce_field( 'memex_create_reminder' ); ?>
	<input class="memex-reminder-form-title" type="text" name="title" placeholder="<?php esc_attr_e( 'Remind me to…', 'memex' ); ?>" required>
	<input class="memex-reminder-form-due" type="datetime-local" name="due" required>
	<button type="submit" class="memex-button memex-button-primary"><?php esc_html_e( 'Add reminder', 'memex' ); ?></button>
	<div class="memex-reminder-quick">
		<span class="memex-muted"><?php esc_html_e( 'Quick set:', 'memex' ); ?></span>
		<button type="button" data-quick-due="+15min"><?php esc_html_e( '+15 min', 'memex' ); ?></button>
		<button type="button" data-quick-due="+1hour"><?php esc_html_e( '+1 hour', 'memex' ); ?></button>
		<button type="button" data-quick-due="+3hour"><?php esc_html_e( '+3 hours', 'memex' ); ?></button>
		<button type="button" data-quick-due="today 18:00"><?php esc_html_e( 'Tonight', 'memex' ); ?></button>
		<button type="button" data-quick-due="tomorrow 09:00"><?php esc_html_e( 'Tomorrow 9am', 'memex' ); ?></button>
		<button type="button" data-quick-due="weekend 09:00"><?php esc_html_e( 'This weekend', 'memex' ); ?></button>
		<button type="button" data-quick-due="monday 09:00"><?php esc_html_e( 'Next Monday', 'memex' ); ?></button>
		<button type="button" data-quick-due="+7day 09:00"><?php esc_html_e( 'Next week', 'memex' ); ?></button>
	</div>
	<p class="memex-reminder-readout memex-muted" data-quick-readout></p>
</form>

<?php
$render_group = static function ( array $items, string $heading, bool $is_done = false ) {
	if ( empty( $items ) ) {
		return;
	}
	?>
	<h2 class="memex-reminder-heading"><?php echo esc_html( $heading ); ?></h2>
	<ul class="memex-reminder-list <?php echo $is_done ? 'is-done' : ''; ?>">
		<?php
		foreach ( $items as $r ) :
			$due    = (string) get_post_meta( $r->ID, Reminder::META_DUE_AT, true );
			$local  = $due ? get_date_from_gmt( $due, 'Y-m-d H:i' ) : '';
			$parent = $r->post_parent ? get_post( $r->post_parent ) : null;
			?>
			<li class="memex-reminder">
				<span class="memex-reminder-due"><?php echo esc_html( $local ); ?></span>
				<span class="memex-reminder-title"><?php echo esc_html( $r->post_title ); ?></span>
				<?php if ( $parent ) : ?>
					<a class="memex-reminder-source" href="<?php echo esc_url( CPT::url( $parent ) ); ?>">
						<?php echo esc_html( get_the_title( $parent ) ); ?>
					</a>
				<?php endif; ?>
				<?php if ( ! $is_done ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="memex-reminder-complete">
						<input type="hidden" name="action" value="memex_complete_reminder">
						<input type="hidden" name="id" value="<?php echo (int) $r->ID; ?>">
						<?php wp_nonce_field( 'memex_complete_reminder_' . $r->ID ); ?>
						<button type="submit"><?php esc_html_e( 'Done', 'memex' ); ?></button>
					</form>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
};

$render_group( $groups['overdue'], __( 'Overdue', 'memex' ) );
$render_group( $groups['upcoming'], __( 'Upcoming', 'memex' ) );
$render_group( $groups['done'], __( 'Done', 'memex' ), true );

if ( empty( $groups['overdue'] ) && empty( $groups['upcoming'] ) && empty( $groups['done'] ) ) :
	?>
	<div class="memex-empty">
		<h2><?php esc_html_e( 'No reminders yet.', 'memex' ); ?></h2>
		<p><?php esc_html_e( "Add one above and you'll get an email when it's due.", 'memex' ); ?></p>
	</div>
	<?php
endif;

include __DIR__ . '/_footer.php';
