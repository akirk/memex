<?php
/**
 * Daily note route — `/memex/daily` (today) and `/memex/daily/{date}`.
 */

use Memex\CPT;
use Memex\DailyNote;

if ( ! defined( 'ABSPATH' ) ) {
	exit; }

$date = wp_app_get_route_var( 'date' );
if ( '' === $date ) {
	$date = DailyNote::today();
}

if ( ! DailyNote::is_valid_date( $date ) ) {
	status_header( 400 );
	$memex_title = __( 'Invalid date', 'memex' );
	include __DIR__ . '/_header.php';
	?>
	<p><?php esc_html_e( 'Daily notes use YYYY-MM-DD.', 'memex' ); ?></p>
	<?php
	include __DIR__ . '/_footer.php';
	return;
}

$note = DailyNote::get_or_create( $date );
if ( ! $note ) {
	wp_die( esc_html__( 'Could not open daily note.', 'memex' ) );
}

// For consistency, redirect to the canonical note URL.
wp_safe_redirect( CPT::url( $note ) );
exit;
