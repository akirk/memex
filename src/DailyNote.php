<?php
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Daily-note lookup intentionally uses note date metadata.
/**
 * Daily-notes helper: get-or-create one note per day keyed by YYYY-MM-DD.
 */

namespace Memex;

class DailyNote {
	public static function register(): void {
		add_action( 'added_post_meta', array( __CLASS__, 'on_daily_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_daily_meta' ), 10, 4 );
		add_action( 'set_object_terms', array( __CLASS__, 'on_tags' ), 10, 4 );
		add_action( 'wp_ajax_memex_daily_calendar', array( __CLASS__, 'ajax_calendar' ) );
		if ( ! get_option( 'memex_daily_tags_migrated' ) ) {
			$notes = get_posts( array( 'post_type' => CPT::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => CPT::META_DAILY ) );
			foreach ( $notes as $note ) {
				self::ensure_tag( $note->ID );
			}
			update_option( 'memex_daily_tags_migrated', 1, false );
		}
	}

	public static function ensure_tag( int $id ): void {
		$date = (string) get_post_meta( $id, CPT::META_DAILY, true );
		if ( CPT::is_note( $id ) && self::is_valid_date( $date ) && ! has_term( 'daily', CPT::TAXONOMY, $id ) ) {
			wp_set_object_terms( $id, 'daily', CPT::TAXONOMY, true );
		}
	}

	public static function on_daily_meta( $meta_id, $id, $key, $value ): void {
		if ( CPT::META_DAILY === $key ) {
			self::ensure_tag( (int) $id );
		}
	}

	public static function on_tags( $id, $terms, $tt_ids, $taxonomy ): void {
		if ( CPT::TAXONOMY === $taxonomy ) {
			self::ensure_tag( (int) $id );
		}
	}

	public static function today(): string {
		return wp_date( 'Y-m-d' );
	}

	/** Fetch daily-note links and current site time for one calendar month. */
	public static function calendar_data( string $month ): array {
		$today = self::today();
		if ( ! self::is_valid_date( $month . '-01' ) || $month > substr( $today, 0, 7 ) ) {
			$month = substr( $today, 0, 7 );
		}
		$first = new \DateTimeImmutable( $month . '-01', wp_timezone() );
		$notes = get_posts(
			array(
				'post_type'      => CPT::POST_TYPE,
				'post_status'    => CPT::readable_statuses(),
				'posts_per_page' => -1,
				'meta_key'       => CPT::META_DAILY,
				'meta_value'     => array( $first->format( 'Y-m-d' ), $first->format( 'Y-m-t' ) ),
				'meta_compare'   => 'BETWEEN',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$days = array();
		foreach ( $notes as $note ) {
			if ( current_user_can( 'read_post', $note->ID ) ) {
				$date = get_post_meta( $note->ID, CPT::META_DAILY, true );
				if ( $date <= $today ) {
					$days[ $date ] = CPT::url( $note );
				}
			}
		}
		return array(
			'month'         => $month,
			'today'         => $today,
			'days'          => $days,
			'serverTime'    => time(),
			'refreshAfter'  => ( new \DateTimeImmutable( $today, wp_timezone() ) )->modify( '+1 day' )->getTimestamp(),
		);
	}

	public static function render_calendar( string $month, string $selected = '' ): void {
		$data  = self::calendar_data( $month );
		$month = $data['month'];
		$today = $data['today'];
		$days  = $data['days'];
		$first = new \DateTimeImmutable( $month . '-01', wp_timezone() );
		include dirname( __DIR__ ) . '/templates/_daily-calendar-grid.php';
	}

	public static function ajax_calendar(): void {
		// Read-only and capability-gated, so long-lived pages need no expiring nonce.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'memex' ) ), 403 );
		}
		$month    = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		if ( ! self::is_valid_date( $month . '-01' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid month.', 'memex' ) ), 400 );
		}
		wp_send_json_success( self::calendar_data( $month ) );
	}

	public static function is_valid_date( string $date ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date )
			&& checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) );
	}

	/** Persist an explicitly saved backfill; opening its editor creates no post. */
	public static function save_new( string $date, string $title, string $content, bool $publish = false ) {
		if ( ! self::is_valid_date( $date ) || $date > self::today() || '' === trim( $title ) ) {
			return new \WP_Error( 'invalid_daily', __( 'Please enter a valid daily date and title.', 'memex' ) );
		}
		$existing = self::find( $date );
		if ( $existing ) {
			if ( ! current_user_can( 'edit_post', $existing->ID ) ) {
				return new \WP_Error( 'not_allowed', __( 'Not allowed.', 'memex' ) );
			}
			return $existing;
		}
		$id = wp_insert_post(
			wp_slash( array(
				'post_type'    => CPT::POST_TYPE,
				'post_title'   => $title,
				'post_content' => $content,
				'post_status'  => $publish ? 'publish' : 'draft',
				'post_name'    => 'daily-' . $date,
			) ),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, CPT::META_DAILY, $date );
		return get_post( $id );
	}

	/**
	 * Find an existing daily note for $date, or null.
	 */
	public static function find( string $date ): ?\WP_Post {
		if ( ! self::is_valid_date( $date ) ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 1,
				'meta_key'       => CPT::META_DAILY,
				'meta_value'     => $date,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Find or create the daily note for $date.
	 */
	public static function get_or_create( string $date ): ?\WP_Post {
		if ( ! self::is_valid_date( $date ) ) {
			return null;
		}
		$existing = self::find( $date );
		if ( $existing ) {
			return $existing;
		}
		$id = wp_insert_post(
			array(
				'post_type'    => CPT::POST_TYPE,
				'post_title'   => $date,
				'post_status'  => 'publish',
				'post_content' => '',
				'post_name'    => 'daily-' . $date,
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			return null;
		}
		update_post_meta( $id, CPT::META_DAILY, $date );
		return get_post( $id );
	}
}
