<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Render-local state from DailyNote::render_calendar().
use Memex\DailyNote;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$start_of_week = (int) get_option( 'start_of_week', 0 );
$offset        = ( (int) $first->format( 'w' ) - $start_of_week + 7 ) % 7;
$day_count     = (int) $first->format( 't' );
$refresh_after = ( new DateTimeImmutable( $today, wp_timezone() ) )->modify( '+1 day' )->getTimestamp();
/* translators: %s: daily note date in YYYY-MM-DD format. */
$date_label = __( 'Open daily note for %s', 'memex' );
/* translators: %s: daily note date in YYYY-MM-DD format. */
$draft_label = __( 'Write a draft daily note for %s', 'memex' );
global $wp_locale;
$month_names = array();
for ( $number = 1; $number <= 12; $number++ ) {
	$month_names[] = $wp_locale->get_month( $number );
}
?>
<section class="memex-daily-calendar" aria-label="<?php esc_attr_e( 'Daily notes calendar', 'memex' ); ?>" data-daily-calendar data-selected="<?php echo esc_attr( $selected ); ?>" data-month="<?php echo esc_attr( $month ); ?>" data-server-time="<?php echo (int) time(); ?>" data-refresh-after="<?php echo (int) $refresh_after; ?>" data-timezone="<?php echo esc_attr( wp_timezone()->getName() ); ?>" data-start-of-week="<?php echo (int) $start_of_week; ?>" data-month-names="<?php echo esc_attr( wp_json_encode( $month_names ) ); ?>" data-date-label="<?php echo esc_attr( $date_label ); ?>" data-draft-label="<?php echo esc_attr( $draft_label ); ?>" data-new-daily-url="<?php echo esc_url( home_url( '/memex/new?daily=' ) ); ?>">
	<div class="memex-calendar-heading">
		<?php $previous = $first->modify( '-1 month' )->format( 'Y-m' ); ?>
		<a href="<?php echo esc_url( add_query_arg( 'month', $previous ) ); ?>" data-calendar-month="<?php echo esc_attr( $previous ); ?>" aria-label="<?php esc_attr_e( 'Previous month', 'memex' ); ?>">&larr;</a>
		<strong aria-live="polite"><?php echo esc_html( wp_date( 'F Y', $first->getTimestamp() ) ); ?></strong>
		<?php $next = $first->modify( '+1 month' )->format( 'Y-m' ); ?>
		<a <?php if ( $next <= substr( $today, 0, 7 ) ) : ?>href="<?php echo esc_url( add_query_arg( 'month', $next ) ); ?>"<?php else : ?>aria-disabled="true" tabindex="-1"<?php endif; ?> data-calendar-month="<?php echo esc_attr( $next ); ?>" aria-label="<?php esc_attr_e( 'Next month', 'memex' ); ?>">&rarr;</a>
	</div>
	<table>
		<thead><tr>
			<?php for ( $column = 0; $column < 7; $column++ ) : ?>
				<?php $weekday = $wp_locale->get_weekday( ( $start_of_week + $column ) % 7 ); ?>
				<th scope="col"><abbr title="<?php echo esc_attr( $weekday ); ?>"><?php echo esc_html( $wp_locale->get_weekday_initial( $weekday ) ); ?></abbr></th>
			<?php endfor; ?>
		</tr></thead>
		<tbody>
			<?php for ( $cell = 0; $cell < (int) ceil( ( $offset + $day_count ) / 7 ) * 7; $cell++ ) : ?>
				<?php if ( 0 === $cell % 7 ) : ?><tr><?php endif; ?>
				<?php
				$day  = $cell - $offset + 1;
				$date = sprintf( '%s-%02d', $month, $day );
				?>
				<td data-calendar-date="<?php echo esc_attr( $date ); ?>"<?php echo $date === $today ? ' class="is-today"' : ''; ?>>
					<?php if ( $day > 0 && $day <= $day_count ) : ?>
						<?php if ( isset( $days[ $date ] ) ) : ?>
							<a class="has-daily" href="<?php echo esc_url( $days[ $date ] ); ?>"<?php echo $date === $selected ? ' aria-current="date"' : ''; ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: daily note date. */ __( 'Open daily note for %s', 'memex' ), $date ) ); ?>"><?php echo (int) $day; ?></a>
						<?php elseif ( $date <= $today ) : ?>
							<a href="<?php echo esc_url( add_query_arg( 'daily', $date, home_url( '/memex/new' ) ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: daily note date. */ __( 'Write a draft daily note for %s', 'memex' ), $date ) ); ?>"><?php echo (int) $day; ?></a>
						<?php else : ?>
							<span><?php echo (int) $day; ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</td>
				<?php if ( 6 === $cell % 7 ) : ?></tr><?php endif; ?>
			<?php endfor; ?>
		</tbody>
	</table>
	<p class="screen-reader-text"><?php esc_html_e( 'Highlighted days have daily notes.', 'memex' ); ?></p>
	<p class="memex-calendar-error" role="alert" hidden><?php esc_html_e( 'Could not load this month. Please try again.', 'memex' ); ?></p>
</section>
