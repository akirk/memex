<?php
/**
 * A chunked import.
 *
 * The browser drives the job: `create()` stores the upload, then `step()` is
 * called repeatedly, each call doing a bounded amount of work and returning a
 * status the UI can render. State lives in an option keyed by job ID so an
 * interrupted import (closed tab, PHP timeout) can be resumed.
 *
 * Phases: prepare → import → links → done.
 */

namespace Memex\Importer;

class Job {
	const OPTION_PREFIX = 'memex_import_job_';
	const ACTIVE_PREFIX = 'memex_import_active_';
	const CRON_HOOK     = 'memex_import_cleanup';

	/** Seconds of work per step by default. */
	const TIME_BUDGET = 5.0;
	/** Persist progress every N items so a fatal mid-step loses little work. */
	const SAVE_EVERY = 10;
	/** Abandoned jobs and work directories older than this are swept. */
	const STALE_AFTER = DAY_IN_SECONDS;

	/** @var array<string,mixed> */
	private array $data;

	private function __construct( array $data ) {
		$this->data = $data;
	}

	/* ─── Lifecycle ─── */

	/**
	 * Store an uploaded file and create a pending job for it.
	 *
	 * @return Job|\WP_Error
	 */
	public static function create( int $user_id, Importer $importer, string $upload_path, string $original_name ) {
		$active = self::active_for( $user_id );
		if ( $active ) {
			return new \WP_Error( 'import-in-progress', __( 'An import is already in progress.', 'memex' ), $active->status() );
		}

		$id       = wp_generate_password( 12, false, false );
		$work_dir = self::base_dir() . '/' . $id;
		if ( ! wp_mkdir_p( $work_dir ) ) {
			return new \WP_Error( 'no-work-dir', __( 'Could not create the import directory.', 'memex' ) );
		}
		$ext    = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		$source = $work_dir . '/source' . ( $ext ? '.' . $ext : '' );
		if ( ! self::store_upload( $upload_path, $source ) ) {
			Importer::rrmdir( $work_dir );
			return new \WP_Error( 'upload-failed', __( 'Could not store the uploaded file.', 'memex' ) );
		}

		$job = new self(
			array(
				'id'          => $id,
				'user'        => $user_id,
				'type'        => $importer->source(),
				'file'        => $original_name,
				'source'      => $source,
				'work_dir'    => $work_dir,
				'phase'       => 'prepare',
				'items'       => array(),
				'cursor'      => 0,
				'ids'         => array(),
				'link_cursor' => 0,
				'state'       => Importer::initial_state(),
				'errors'      => array(),
				'created'     => time(),
				'updated'     => time(),
			)
		);
		$job->save();
		update_option( self::ACTIVE_PREFIX . $user_id, $id, false );
		self::schedule_cleanup();
		return $job;
	}

	public static function load( string $id ): ?Job {
		$id = preg_replace( '/[^A-Za-z0-9]/', '', $id );
		if ( '' === $id ) {
			return null;
		}
		$data = get_option( self::OPTION_PREFIX . $id );
		return is_array( $data ) ? new self( $data ) : null;
	}

	public static function active_for( int $user_id ): ?Job {
		$id  = (string) get_option( self::ACTIVE_PREFIX . $user_id, '' );
		$job = '' !== $id ? self::load( $id ) : null;
		if ( ! $job ) {
			delete_option( self::ACTIVE_PREFIX . $user_id );
		}
		return $job;
	}

	public function id(): string {
		return $this->data['id'];
	}

	public function owner(): int {
		return (int) $this->data['user'];
	}

	/**
	 * Abandon the job: remove its files and state. Already-imported notes stay.
	 */
	public function cancel(): void {
		Importer::rrmdir( $this->data['work_dir'] );
		delete_option( self::OPTION_PREFIX . $this->data['id'] );
		if ( $this->data['id'] === get_option( self::ACTIVE_PREFIX . $this->data['user'] ) ) {
			delete_option( self::ACTIVE_PREFIX . $this->data['user'] );
		}
	}

	/* ─── Work ─── */

	/**
	 * Do up to `$seconds` of work (and at most `$max_items` items) and return
	 * the status afterwards. Call until `phase` is `done`.
	 */
	public function step( float $seconds = self::TIME_BUDGET, int $max_items = PHP_INT_MAX ): array {
		$importer = Importer::from_type( $this->data['type'] );
		if ( ! $importer ) {
			$this->data['errors'][] = 'Unknown importer: ' . $this->data['type'];
			$this->finish();
			return $this->status();
		}

		$started = microtime( true );
		$within  = function () use ( $started, $seconds ) {
			return ( microtime( true ) - $started ) < $seconds;
		};

		if ( 'prepare' === $this->data['phase'] ) {
			$prepared = $importer->prepare( $this->data['source'], $this->data['work_dir'] );
			$this->data['items']  = $prepared['items'];
			$this->data['state']  = $prepared['state'];
			$this->data['errors'] = $prepared['errors'];
			$this->data['phase']  = $prepared['errors'] && ! $prepared['items'] ? 'links' : 'import';
			$this->save();
		}

		if ( 'import' === $this->data['phase'] ) {
			Importer::begin();
			$done  = 0;
			$total = count( $this->data['items'] );
			while ( $this->data['cursor'] < $total && $done < $max_items && ( 0 === $done || $within() ) ) {
				$item = $this->data['items'][ $this->data['cursor'] ];
				$id   = $importer->import_item( $item, $this->data['state'] );
				if ( $id > 0 && ! in_array( $id, $this->data['ids'], true ) ) {
					$this->data['ids'][] = $id;
				}
				++$this->data['cursor'];
				++$done;
				if ( 0 === $done % self::SAVE_EVERY ) {
					$this->save();
				}
			}
			Importer::end();
			if ( $this->data['cursor'] >= $total ) {
				$this->data['phase'] = 'links';
			}
			$this->save();
		}

		if ( 'links' === $this->data['phase'] && $within() ) {
			$done  = 0;
			$total = count( $this->data['ids'] );
			while ( $this->data['link_cursor'] < $total && $done < $max_items && ( 0 === $done || $within() ) ) {
				Importer::resolve_links( (int) $this->data['ids'][ $this->data['link_cursor'] ] );
				++$this->data['link_cursor'];
				++$done;
				if ( 0 === $done % self::SAVE_EVERY ) {
					$this->save();
				}
			}
			if ( $this->data['link_cursor'] >= $total ) {
				$this->finish();
			} else {
				$this->save();
			}
		}

		return $this->status();
	}

	/**
	 * Progress snapshot for the UI.
	 */
	public function status(): array {
		$errors = array_merge( $this->data['errors'], $this->data['state']['errors'] ?? array() );
		$links  = 'links' === $this->data['phase'] || 'done' === $this->data['phase'];
		return array(
			'job'     => $this->data['id'],
			'type'    => $this->data['type'],
			'file'    => $this->data['file'],
			'phase'   => $this->data['phase'],
			'done'    => $links ? (int) $this->data['link_cursor'] : (int) $this->data['cursor'],
			'total'   => $links ? count( $this->data['ids'] ) : count( $this->data['items'] ),
			'count'   => count( $this->data['ids'] ),
			'skipped' => (int) ( $this->data['state']['skipped'] ?? 0 ),
			'errors'  => array_values( $errors ),
			'updated' => (int) $this->data['updated'],
		);
	}

	private function finish(): void {
		$this->data['phase'] = 'done';
		Importer::rrmdir( $this->data['work_dir'] );
		delete_option( self::OPTION_PREFIX . $this->data['id'] );
		if ( $this->data['id'] === get_option( self::ACTIVE_PREFIX . $this->data['user'] ) ) {
			delete_option( self::ACTIVE_PREFIX . $this->data['user'] );
		}
	}

	private function save(): void {
		$this->data['updated'] = time();
		update_option( self::OPTION_PREFIX . $this->data['id'], $this->data, false );
	}

	/* ─── Housekeeping ─── */

	public static function base_dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'memex-import';
	}

	private static function store_upload( string $from, string $to ): bool {
		if ( is_uploaded_file( $from ) ) {
			return move_uploaded_file( $from, $to );
		}
		return (bool) copy( $from, $to );
	}

	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + self::STALE_AFTER, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule_cleanup(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Remove work directories and job state that nobody touched for a day
	 * (closed tabs, crashed requests).
	 */
	public static function cleanup_stale(): void {
		$base = self::base_dir();
		if ( ! is_dir( $base ) ) {
			return;
		}
		$cutoff = time() - self::STALE_AFTER;
		foreach ( scandir( $base ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$dir = $base . '/' . $entry;
			$job = self::load( $entry );
			if ( $job && (int) $job->data['updated'] > $cutoff ) {
				continue;
			}
			if ( $job ) {
				$job->cancel();
			} elseif ( is_dir( $dir ) && filemtime( $dir ) < $cutoff ) {
				Importer::rrmdir( $dir );
			}
		}
	}
}
