<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template-local state.
use Memex\DailyNote;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$memex_calendar_date = $memex_calendar_date ?? DailyNote::today();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only month selection.
$memex_calendar_month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : substr( $memex_calendar_date, 0, 7 );
DailyNote::render_calendar( $memex_calendar_month, $memex_calendar_date );
