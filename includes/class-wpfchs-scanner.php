<?php
/**
 * Catalog Health Scanner for WooCommerce - Scanner Class
 *
 * Batch scan engine. A scan is started once, then advanced in short,
 * resumable steps (AJAX polling for manual scans, cron for scheduled
 * ones) so it never exhausts a request on large catalogs.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Scanner' ) ) :

class WPFCHS_Scanner {

	/**
	 * Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function __construct() {
		add_action( 'wpfchs_scan_step_event', array( $this, 'cron_step' ), 10, 1 );
	}

	/**
	 * table.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpfchs_scans';
	}

	/**
	 * Returns the currently running scan row, if any.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  object|null
	 */
	function get_running() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom scans table; static query.
		return $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}wpfchs_scans WHERE status IN ( 'running', 'paused' ) ORDER BY id DESC LIMIT 1"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * get_scan.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $scan_id
	 * @return  object|null
	 */
	function get_scan( $scan_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom scans table; no WP API exists.
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpfchs_scans WHERE id = %d", $scan_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Returns the most recent completed scan.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  object|null
	 */
	function get_last_completed() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom scans table; static query.
		return $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}wpfchs_scans WHERE status = 'complete' ORDER BY id DESC LIMIT 1"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Returns recent completed scans, newest first.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $limit
	 * @return  array
	 */
	function get_history( $limit = 20 ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom scans table; no WP API exists.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpfchs_scans WHERE status = 'complete' ORDER BY id DESC LIMIT %d",
				absint( $limit )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Starts a new scan.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $profile_id
	 * @param   string $trigger    'manual' or 'scheduled'.
	 * @return  int|WP_Error       Scan id.
	 */
	function start( $profile_id, $trigger = 'manual' ) {
		global $wpdb;

		if ( $this->get_running() ) {
			return new WP_Error( 'wpfchs_scan_running', __( 'A scan is already running.', 'catalog-health-scanner-for-woocommerce' ) );
		}

		$profile = wpfchs()->core->profiles->get( $profile_id );
		if ( null === $profile ) {
			return new WP_Error( 'wpfchs_bad_profile', __( 'Unknown scan profile.', 'catalog-health-scanner-for-woocommerce' ) );
		}

		$runnable = wpfchs()->core->checks->get_runnable( $profile['checks'] );
		if ( empty( $runnable ) ) {
			return new WP_Error( 'wpfchs_no_checks', __( 'No applicable checks in this profile.', 'catalog-health-scanner-for-woocommerce' ) );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Product count for progress display; one query per scan start.
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom scans table; no WP API exists.
		$wpdb->insert(
			$this->table(),
			array(
				'profile'        => $profile_id,
				'status'         => 'running',
				'started_at'     => current_time( 'mysql', true ),
				'products_total' => $total,
				'check_ids'      => wp_json_encode( array_keys( $runnable ) ),
				'score_data'     => wp_json_encode( array( 'counters' => array(), 'trigger' => $trigger ) ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$scan_id = (int) $wpdb->insert_id;

		// Remember the last manually-chosen profile so the dashboard dropdown
		// stays on it after the post-scan reload (scheduled scans don't count).
		if ( 'manual' === $trigger ) {
			update_option( 'wpfchs_last_profile', $profile_id, false );
		}

		// Re-detect applicability against the current catalog: an import or a
		// settings change since the last scan may have turned a group on/off.
		wpfchs()->core->applicability->flush_cache();

		do_action( 'wpfchs_scan_started', $scan_id, $profile_id, $trigger );

		return $scan_id;

	}

	/**
	 * Timestamp before which a product is old enough to always count.
	 *
	 * A product created after this boundary is inside its grace window and is
	 * skipped. Returns 0 (grace disabled) when the grace window is off OR when
	 * there is no previous completed scan — the first scan always counts the
	 * whole catalog.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $scan_id
	 * @param   int $grace_days
	 * @return  int Unix timestamp, or 0 to disable grace for this scan.
	 */
	function get_grace_boundary( $scan_id, $grace_days ) {
		global $wpdb;

		if ( $grace_days < 1 ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom scans table; no WP API exists.
		$previous_started = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT started_at FROM {$wpdb->prefix}wpfchs_scans WHERE status = 'complete' AND id < %d ORDER BY id DESC LIMIT 1",
				$scan_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( ! $previous_started ) {
			return 0;
		}

		$previous_ts = strtotime( $previous_started . ' UTC' );
		$window_ts   = time() - $grace_days * DAY_IN_SECONDS;

		// Grace only products that are both new since the last scan and still
		// within the configured window.
		return max( $previous_ts, $window_ts );

	}

	/**
	 * Advances a running scan within a time budget.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int   $scan_id
	 * @param   float $time_budget Seconds to spend in this step.
	 * @return  array|WP_Error     Progress payload.
	 */
	function step( $scan_id, $time_budget = 8.0 ) {

		$scan = $this->get_scan( $scan_id );
		if ( ! $scan ) {
			return new WP_Error( 'wpfchs_no_scan', __( 'Scan not found.', 'catalog-health-scanner-for-woocommerce' ) );
		}
		if ( 'running' !== $scan->status ) {
			return $this->progress( $scan );
		}

		$started    = microtime( true );
		$batch_size = (int) apply_filters( 'wpfchs_scan_batch_size', 25 );
		$checks     = $this->get_scan_checks( $scan );
		$data       = json_decode( (string) $scan->score_data, true );
		$data       = ( is_array( $data ) ? $data : array( 'counters' => array() ) );

		$last_id        = (int) $scan->last_product_id;
		$scanned        = (int) $scan->products_scanned;
		$issues_found   = (int) $scan->issues_found;
		$skipped        = (int) ( $data['skipped'] ?? 0 );
		$grace_days     = (int) wpfchs()->core->get_threshold( 'grace_period_days' );
		$grace_boundary = $this->get_grace_boundary( $scan_id, $grace_days );
		$exclude_cats   = $this->get_excluded_category_ids();
		$done           = false;

		do {

			$product_ids = $this->get_next_product_ids( $last_id, $batch_size );

			if ( empty( $product_ids ) ) {
				$done = true;
				break;
			}

			foreach ( $product_ids as $product_id ) {

				$last_id = (int) $product_id;

				$product = wc_get_product( $product_id );
				if ( ! $product ) {
					continue;
				}

				// Grace period: a product created after the previous completed
				// scan and still inside its grace window doesn't count yet, so a
				// genuine work-in-progress product doesn't tank the score. The
				// first scan (no prior scan) always counts the whole catalog —
				// otherwise a freshly imported or newly activated store would
				// scan zero products and report a meaningless 100%.
				//
				// Skipped products are counted separately and never reported as
				// scanned: a skipped product has been checked against nothing,
				// and saying otherwise turns "we ignored your new import" into
				// "your new import is healthy".
				if ( $grace_boundary > 0 ) {
					$created = $product->get_date_created( 'edit' );
					if ( $created && $created->getTimestamp() > $grace_boundary ) {
						$skipped++;
						continue;
					}
				}

				// Scan scope: excluded product categories.
				if ( ! empty( $exclude_cats ) && ! empty( array_intersect( $product->get_category_ids(), $exclude_cats ) ) ) {
					$skipped++;
					continue;
				}

				$scanned++;

				foreach ( $checks as $check_id => $check ) {

					if ( $check->is_catalog_pass() || ! $check->applies_to( $product ) ) {
						continue;
					}

					if ( ! isset( $data['counters'][ $check_id ] ) ) {
						$data['counters'][ $check_id ] = array( 'checked' => 0, 'failed' => 0 );
					}
					$data['counters'][ $check_id ]['checked']++;

					$value = $check->run( $product );

					if ( false !== $value && null !== $value && '' !== $value ) {
						$status = wpfchs()->core->issues->record( $product_id, $product_id, $check, $value, $scan_id );
						if ( 'open' === $status ) {
							$data['counters'][ $check_id ]['failed']++;
							$issues_found++;
						}
					}
				}
			}

		} while ( ( microtime( true ) - $started ) < $time_budget );

		$data['skipped'] = $skipped;

		if ( $done ) {
			$this->run_catalog_passes( $scan_id, $checks, $data, $issues_found );
			$this->finish( $scan_id, $checks, $data, $scanned, $last_id, $issues_found );
		} else {
			$this->save_progress( $scan_id, $data, $scanned, $last_id, $issues_found );
		}

		return $this->progress( $this->get_scan( $scan_id ) );

	}

	/**
	 * pause / resume / cancel.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int    $scan_id
	 * @param   string $status  'paused', 'running', or 'cancelled'.
	 * @return  bool
	 */
	function set_status( $scan_id, $status ) {
		global $wpdb;

		if ( ! in_array( $status, array( 'paused', 'running', 'cancelled' ), true ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom scans table; no WP API exists.
		return (bool) $wpdb->update(
			$this->table(),
			array( 'status' => $status ),
			array( 'id' => $scan_id ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	}

	/**
	 * Cron entry point: advances a scheduled scan and re-schedules itself
	 * until the scan completes.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $scan_id
	 */
	function cron_step( $scan_id ) {
		$result = $this->step( (int) $scan_id, 20.0 );
		if ( ! is_wp_error( $result ) && ! empty( $result['running'] ) ) {
			wp_schedule_single_event( time() + 30, 'wpfchs_scan_step_event', array( (int) $scan_id ) );
		}
	}

	/**
	 * Runs the cross-product (catalog-wide) passes.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int   $scan_id
	 * @param   array $checks
	 * @param   array $data          Passed by reference via return.
	 * @param   int   $issues_found  Passed by reference via return.
	 */
	protected function run_catalog_passes( $scan_id, $checks, &$data, &$issues_found ) {

		$scan  = $this->get_scan( $scan_id );
		$total = max( 1, (int) $scan->products_total );

		foreach ( $checks as $check_id => $check ) {

			if ( ! $check->is_catalog_pass() ) {
				continue;
			}

			$results          = $check->run_catalog();
			$failed_products  = array();

			foreach ( $results as $object_id => $result ) {
				$status = wpfchs()->core->issues->record(
					(int) $object_id,
					(int) ( $result['product_id'] ?? $object_id ),
					$check,
					(string) ( $result['value'] ?? '' ),
					$scan_id
				);
				if ( 'open' === $status ) {
					$failed_products[ (int) ( $result['product_id'] ?? $object_id ) ] = true;
					$issues_found++;
				}
			}

			$data['counters'][ $check_id ] = array(
				'checked' => $total,
				'failed'  => min( $total, count( $failed_products ) ),
			);

		}

	}

	/**
	 * Completes a scan: resolves stale issues, computes and stores the score.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int   $scan_id
	 * @param   array $checks
	 * @param   array $data
	 * @param   int   $scanned
	 * @param   int   $last_id
	 * @param   int   $issues_found
	 */
	protected function finish( $scan_id, $checks, $data, $scanned, $last_id, $issues_found ) {
		global $wpdb;

		// An issue on a product that was not scanned must never be marked
		// fixed. That applies to every reason a product can be missed —
		// excluded categories AND the grace period — because stale resolution
		// works from "not seen in this scan", which a skipped product also
		// satisfies. Reporting a still-broken product as fixed is the worst
		// failure this plugin can have, so when anything was skipped we leave
		// resolution to the next complete pass.
		if ( empty( $this->get_excluded_category_ids() ) && empty( $data['skipped'] ) ) {
			wpfchs()->core->issues->resolve_stale( $scan_id, array_keys( $checks ) );
		}

		$scores = wpfchs()->core->scores->compute( $data['counters'], $checks );

		$data['categories'] = $scores['categories'];

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom scans table; no WP API exists.
		$wpdb->update(
			$this->table(),
			array(
				'status'           => 'complete',
				'completed_at'     => current_time( 'mysql', true ),
				'products_scanned' => $scanned,
				'last_product_id'  => $last_id,
				'issues_found'     => $issues_found,
				'score'            => $scores['total'],
				'score_data'       => wp_json_encode( $data ),
			),
			array( 'id' => $scan_id ),
			array( '%s', '%s', '%d', '%d', '%d', '%f', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		wpfchs()->core->applicability->flush_cache();
		wpfchs()->core->issues->sync_product_counts();

		do_action( 'wpfchs_scan_completed', $scan_id );

	}

	/**
	 * save_progress.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int   $scan_id
	 * @param   array $data
	 * @param   int   $scanned
	 * @param   int   $last_id
	 * @param   int   $issues_found
	 */
	protected function save_progress( $scan_id, $data, $scanned, $last_id, $issues_found ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom scans table; no WP API exists.
		$wpdb->update(
			$this->table(),
			array(
				'products_scanned' => $scanned,
				'last_product_id'  => $last_id,
				'issues_found'     => $issues_found,
				'score_data'       => wp_json_encode( $data ),
			),
			array( 'id' => $scan_id ),
			array( '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Progress payload for the UI.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   object $scan
	 * @return  array
	 */
	function progress( $scan ) {

		$total = max( 1, (int) $scan->products_total );
		$data  = json_decode( (string) $scan->score_data, true );

		// Skipped products are not scanned, but they have been dealt with, so
		// they count towards progress. Otherwise the bar never reaches 100%.
		$skipped   = (int) ( is_array( $data ) ? ( $data['skipped'] ?? 0 ) : 0 );
		$processed = (int) $scan->products_scanned + $skipped;

		return array(
			'id'      => (int) $scan->id,
			'status'  => $scan->status,
			'running' => ( 'running' === $scan->status ),
			'total'   => (int) $scan->products_total,
			'scanned' => (int) $scan->products_scanned,
			'skipped' => $skipped,
			'percent' => min( 100, (int) round( ( $processed / $total ) * 100 ) ),
			'issues'  => (int) $scan->issues_found,
			'score'   => ( null !== $scan->score ? (float) $scan->score : null ),
		);

	}

	/**
	 * Number of products the given scan skipped (grace window or excluded
	 * categories), for the dashboard's "not everything was checked" notice.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   object|null $scan
	 * @return  int
	 */
	function get_skipped_count( $scan ) {
		if ( ! $scan ) {
			return 0;
		}
		$data = json_decode( (string) $scan->score_data, true );
		return (int) ( is_array( $data ) ? ( $data['skipped'] ?? 0 ) : 0 );
	}

	/**
	 * Resolves the checks stored on a scan row back to check objects.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   object $scan
	 * @return  array id => WPFCHS_Check
	 */
	function get_scan_checks( $scan ) {
		$ids    = (array) json_decode( (string) $scan->check_ids, true );
		$all    = wpfchs()->core->checks->get_all();
		$checks = array();
		foreach ( $ids as $id ) {
			if ( isset( $all[ $id ] ) ) {
				$checks[ $id ] = $all[ $id ];
			}
		}
		return $checks;
	}

	/**
	 * Keyset pagination over published products: deterministic and safe to
	 * resume even if products are added or deleted mid-scan.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $last_id
	 * @param   int $limit
	 * @return  array of ids
	 */
	protected function get_next_product_ids( $last_id, $limit ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- WP_Query has no keyset (ID > x) pagination; required for resumable scans.
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
					$last_id,
					$limit
				)
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * get_excluded_category_ids.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array
	 */
	function get_excluded_category_ids() {
		$scope = get_option( 'wpfchs_scan_scope', array() );
		return array_filter( array_map( 'absint', (array) ( $scope['exclude_cats'] ?? array() ) ) );
	}

}

endif;

return new WPFCHS_Scanner();
