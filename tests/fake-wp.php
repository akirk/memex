<?php
/**
 * Just enough of WordPress, in memory, for the importers and the import job
 * to run under PHPUnit without a database: posts, post meta, terms, options
 * and the few helpers they call.
 */

final class FakeWP {
	public static array $posts   = array();
	public static array $meta    = array();
	public static array $terms   = array();
	public static array $options = array();
	public static array $actions = array();
	public static int $next_id   = 1;
	public static string $upload_dir = '';
	public static array $last_update = array();

	public static function reset(): void {
		self::$posts   = array();
		self::$meta    = array();
		self::$terms   = array();
		self::$options = array();
		self::$actions = array();
		self::$next_id = 1;
		if ( '' === self::$upload_dir ) {
			self::$upload_dir = sys_get_temp_dir() . '/memex-tests-' . getmypid();
		}
		if ( is_dir( self::$upload_dir ) ) {
			\Memex\Importer\Importer::rrmdir( self::$upload_dir );
		}
		mkdir( self::$upload_dir, 0777, true );
	}

	public static function find_by_title( string $title ): int {
		$title = strtolower( trim( $title ) );
		$found = 0;
		foreach ( self::$posts as $p ) {
			if ( 'memex_note' === $p->post_type && strtolower( $p->post_title ) === $title ) {
				if ( ! $found || ( 'publish' === $p->post_status && 'publish' !== self::$posts[ $found ]->post_status ) ) {
					$found = $p->ID;
				}
			}
		}
		return $found;
	}

	/** @return object[] */
	public static function notes(): array {
		return array_values( array_filter( self::$posts, static fn( $p ) => 'memex_note' === $p->post_type ) );
	}
}

final class FakeWPDB {
	public string $posts = 'wp_posts';

	public function prepare( string $sql, ...$args ) {
		return array( $sql, $args );
	}

	/** Only `Links::resolve()` queries here: look a title up. */
	public function get_var( $query ) {
		$title = end( $query[1] );
		return FakeWP::find_by_title( (string) $title ) ?: null;
	}
}

$GLOBALS['wpdb'] = new FakeWPDB();

define( 'DAY_IN_SECONDS', 86400 );

class WP_Error {
	private string $code;
	private string $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = (string) $code;
		$this->message = (string) $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_insert_post( array $data, $wp_error = false ) {
	$id   = FakeWP::$next_id++;
	$post = (object) array_merge(
		array(
			'ID'            => $id,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => '',
			'post_content'  => '',
			'post_parent'   => 0,
			'post_date'     => '',
			'post_date_gmt' => '',
			'post_name'     => '',
		),
		$data
	);
	$post->ID            = $id;
	if ( '' === $post->post_name ) {
		$post->post_name = sanitize_title( $post->post_title );
	}
	FakeWP::$posts[ $id ] = $post;
	return $id;
}

function wp_update_post( array $data ) {
	FakeWP::$last_update = $data;
	$id = (int) ( $data['ID'] ?? 0 );
	if ( ! isset( FakeWP::$posts[ $id ] ) ) {
		return 0;
	}
	foreach ( $data as $k => $v ) {
		FakeWP::$posts[ $id ]->$k = $v;
	}
	return $id;
}

function get_post( $id ) {
	return FakeWP::$posts[ (int) $id ] ?? null;
}

function get_post_meta( $id, $key, $single = false ) {
	$rows = FakeWP::$meta[ (int) $id ][ $key ] ?? array();
	return $single ? ( $rows[0] ?? '' ) : $rows;
}

function add_post_meta( $id, $key, $value ) {
	FakeWP::$meta[ (int) $id ][ $key ][] = $value;
	return true;
}

function update_post_meta( $id, $key, $value ) {
	FakeWP::$meta[ (int) $id ][ $key ] = array( $value );
	return true;
}

function delete_post_meta( $id, $key ) {
	unset( FakeWP::$meta[ (int) $id ][ $key ] );
	return true;
}

function wp_set_object_terms( $id, $terms, $taxonomy, $append = false ) {
	FakeWP::$terms[ (int) $id ] = array_values( (array) $terms );
	return $terms;
}

function get_posts( $args ) {
	$out = array();
	foreach ( FakeWP::$posts as $post ) {
		if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
			continue;
		}
		if ( isset( $args['name'] ) && $post->post_name !== $args['name'] ) {
			continue;
		}
		$out[] = ( $args['fields'] ?? '' ) === 'ids' ? $post->ID : $post;
	}
	$limit = (int) ( $args['numberposts'] ?? $args['posts_per_page'] ?? -1 );
	return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
}

function get_option( $key, $default = false ) {
	return FakeWP::$options[ $key ] ?? $default;
}

function update_option( $key, $value, $autoload = null ) {
	// Options round-trip through serialisation in WordPress; mimic that so
	// objects or references can't leak between steps.
	FakeWP::$options[ $key ] = unserialize( serialize( $value ) );
	return true;
}

function delete_option( $key ) {
	unset( FakeWP::$options[ $key ] );
	return true;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	FakeWP::$actions[ $hook ] = true;
}

function remove_action( $hook, $callback, $priority = 10 ) {
	unset( FakeWP::$actions[ $hook ] );
}

function wp_next_scheduled( $hook ) {
	return FakeWP::$options[ '_cron_' . $hook ] ?? false;
}

function wp_schedule_event( $ts, $recurrence, $hook ) {
	FakeWP::$options[ '_cron_' . $hook ] = $ts;
	return true;
}

function wp_unschedule_event( $ts, $hook ) {
	unset( FakeWP::$options[ '_cron_' . $hook ] );
	return true;
}

function wp_upload_dir() {
	return array( 'basedir' => FakeWP::$upload_dir );
}

function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}

function trailingslashit( $s ) {
	return rtrim( $s, '/\\' ) . '/';
}

function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	return substr( bin2hex( random_bytes( $length ) ), 0, $length );
}

function get_date_from_gmt( $gmt ) {
	return $gmt;
}

function esc_url( $url ) {
	return $url;
}

function esc_attr( $text ) {
	return esc_html( $text );
}

function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options );
}

function sanitize_title( $title ) {
	$t = strtolower( trim( preg_replace( '/[^A-Za-z0-9]+/', '-', (string) $title ), '-' ) );
	return $t;
}

function get_the_title( $id ) {
	return FakeWP::$posts[ (int) $id ]->post_title ?? '';
}
