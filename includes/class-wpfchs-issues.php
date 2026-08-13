<?php
/**
 * Catalog Health Scanner for WooCommerce - Issues Repository Class
 *
 * All reads/writes of the `wpfchs_issues` table live here. The table is a
 * custom store (issue lifecycle across scans) with no WP API equivalent,
 * so direct queries are used throughout, always prepared.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Issues' ) ) :

class WPFCHS_Issues {

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
		return $wpdb->prefix . 'wpfchs_issues';
	}

	/**
	 * Records a violation found during a scan.
	 *
	 * Keeps `ignored` issues ignored, reopens `resolved` ones, updates
	 * `open` ones in place.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int          $object_id  Product or variation id the issue sits on.
	 * @param   int          $product_id Parent product id (equals $object_id for products).
	 * @param   WPFCHS_Check $check
	 * @param   string       $value      Offending value snapshot.
	 * @param   int          $scan_id
	 * @return  string                   Resulting status: 'open' or 'ignored'.
	 */
	function record( $object_id, $product_id, $check, $value, $scan_id ) {
		global $wpdb;

		$value = ( true === $value ? '' : (string) $value );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom issues table; no WP API exists.
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$wpdb->prefix}wpfchs_issues WHERE object_id = %d AND check_id = %s",
				$object_id,
				$check->get_id()
			)
		);

		if ( $existing ) {
			$status = ( 'ignored' === $existing->status ? 'ignored' : 'open' );
			$data   = array(
				'status'         => $status,
				'issue_value'    => $value,
				'severity'       => $check->get_severity(),
				'last_seen_scan' => $scan_id,
				'resolved_at'    => null,
			);
			$format = array( '%s', '%s', '%s', '%d', null );
			// A resolved issue coming back is a regression; remember when.
			if ( 'resolved' === $existing->status ) {
				$data['last_reopened_scan'] = $scan_id;
				$format[]                   = '%d';
			}
			$wpdb->update(
				$this->table(),
				$data,
				array( 'id' => $existing->id ),
				$format,
				array( '%d' )
			);
			return $status;
		}

		$wpdb->insert(
			$this->table(),
			array(
				'object_id'       => $object_id,
				'product_id'      => $product_id,
				'check_id'        => $check->get_id(),
				'category'        => $check->get_category(),
				'severity'        => $check->get_severity(),
				'status'          => 'open',
				'issue_value'     => $value,
				'first_seen_scan' => $scan_id,
				'last_seen_scan'  => $scan_id,
				'first_seen'      => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
		// phpcs:enable

		return 'open';

	}

	/**
	 * Marks stale open issues resolved after a full-catalog scan: any open
	 * issue whose check ran this scan but which was not seen again.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int   $scan_id
	 * @param   array $check_ids Checks that ran in this scan.
	 * @return  int              Number of issues resolved.
	 */
	function resolve_stale( $scan_id, $check_ids ) {
		global $wpdb;

		if ( empty( $check_ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $check_ids ), '%s' ) );
		$params       = array_merge( array( current_time( 'mysql', true ), $scan_id ), $check_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; placeholders are generated `%s` markers, all values go through prepare().
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wpfchs_issues
				SET status = 'resolved', resolved_at = %s
				WHERE status = 'open' AND last_seen_scan < %d AND check_id IN ( $placeholders )",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	}

	/**
	 * Sets an issue to ignored (permanent, per product per check).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $issue_id
	 * @return  bool
	 */
	function ignore( $issue_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; no WP API exists.
		return (bool) $wpdb->update(
			$this->table(),
			array(
				'status'     => 'ignored',
				'ignored_by' => get_current_user_id(),
				'ignored_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $issue_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Restores a previously ignored issue to open.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $issue_id
	 * @return  bool
	 */
	function restore( $issue_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; no WP API exists.
		return (bool) $wpdb->update(
			$this->table(),
			array(
				'status'     => 'open',
				'ignored_by' => null,
				'ignored_at' => null,
			),
			array(
				'id'     => $issue_id,
				'status' => 'ignored',
			),
			array( '%s', null, null ),
			array( '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Bulk ignore: every issue matching the given filters, not just the page
	 * currently on screen. Product counters are refreshed for every product
	 * touched, so the dashboard and the products list stay in step.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $args  Same filters as query(); `status` is forced to open.
	 * @param   int   $limit Safety ceiling on one call.
	 * @return  int          Number of issues ignored.
	 */
	function ignore_matching( $args, $limit = 20000 ) {
		return $this->set_status_matching( $args, 'ignored', $limit );
	}

	/**
	 * Bulk restore: every ignored issue matching the given filters.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $args
	 * @param   int   $limit
	 * @return  int
	 */
	function restore_matching( $args, $limit = 20000 ) {
		return $this->set_status_matching( $args, 'open', $limit );
	}

	/**
	 * Shared implementation for the two bulk status transitions.
	 *
	 * Ids are collected first so the affected products can have their cached
	 * issue counts recalculated afterwards.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array  $args
	 * @param   string $to    Target status: `ignored` or `open`.
	 * @param   int    $limit
	 * @return  int
	 */
	protected function set_status_matching( $args, $to, $limit ) {
		global $wpdb;

		$args['status'] = ( 'ignored' === $to ? 'open' : 'ignored' );

		list( $where, $params ) = $this->build_where( $args );

		$params[] = absint( $limit );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; WHERE fragment is built from fixed column snippets with `%` placeholders, all values go through prepare().
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, object_id FROM {$wpdb->prefix}wpfchs_issues $where ORDER BY id ASC LIMIT %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( empty( $rows ) ) {
			return 0;
		}

		$ids        = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );
		$object_ids = array_unique( array_map( 'intval', wp_list_pluck( $rows, 'object_id' ) ) );

		$affected = $this->set_status_for_ids( $ids, $to );

		$this->update_counts_for_objects( $object_ids );

		return $affected;

	}

	/**
	 * Applies an ignore/restore transition to an explicit set of issue ids.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array  $ids
	 * @param   string $to `ignored` or `open`.
	 * @return  int
	 */
	function set_status_for_ids( $ids, $to ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( empty( $ids ) ) {
			return 0;
		}

		$affected = 0;

		// Chunked so a very large selection cannot build an oversized query.
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {

			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );

			if ( 'ignored' === $to ) {
				$params = array_merge( array( get_current_user_id(), current_time( 'mysql', true ) ), $chunk );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; placeholders are generated `%d` markers, all values go through prepare().
				$affected += (int) $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}wpfchs_issues
						SET status = 'ignored', ignored_by = %d, ignored_at = %s
						WHERE status = 'open' AND id IN ( $placeholders )",
						$params
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			} else {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; placeholders are generated `%d` markers, all values go through prepare().
				$affected += (int) $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}wpfchs_issues
						SET status = 'open', ignored_by = NULL, ignored_at = NULL
						WHERE status = 'ignored' AND id IN ( $placeholders )",
						$chunk
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			}
		}

		return $affected;

	}

	/**
	 * Object ids behind a set of issue ids (so counters can be refreshed
	 * after a selection-based bulk action).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $ids
	 * @return  array
	 */
	function get_object_ids_for_issues( $ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; placeholders are generated `%d` markers, all values go through prepare().
		$rows = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT object_id FROM {$wpdb->prefix}wpfchs_issues WHERE id IN ( $placeholders )",
				$ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return array_map( 'intval', $rows );

	}

	/**
	 * Open-issue counts grouped by check category, optionally for one
	 * severity. Powers the dashboard's critical banner, which needs to know
	 * *where* the criticals are rather than assuming one category.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $severity Optional severity filter.
	 * @return  array category => count
	 */
	/**
	 * Open-issue counts per category, counting only checks that are currently
	 * scored — i.e. their applicability group is on and not report-only.
	 *
	 * This is what user-facing alarms should count. A store that switches a
	 * group off (a catalog store turning off selling checks, say) still has
	 * the old issues in the table until the next scan resolves them; an alarm
	 * fed by raw counts would keep shouting about problems the user just said
	 * do not apply to them.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $severity Optional severity filter.
	 * @return  array  category => count, largest first.
	 */
	function count_open_scored_by_category( $severity = '' ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; no WP API exists.
		if ( '' !== $severity ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT check_id, category, COUNT(*) AS cnt FROM {$wpdb->prefix}wpfchs_issues WHERE status = 'open' AND severity = %s GROUP BY check_id, category",
					$severity
				)
			);
		} else {
			$rows = $wpdb->get_results(
				"SELECT check_id, category, COUNT(*) AS cnt FROM {$wpdb->prefix}wpfchs_issues WHERE status = 'open' GROUP BY check_id, category"
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$checks = wpfchs()->core->checks;
		$counts = array();
		foreach ( (array) $rows as $row ) {
			$check = $checks->get( $row->check_id );
			if ( ! $check || ! $checks->is_scored( $check ) ) {
				continue;
			}
			// A store-level finding is ONE issue however many products it
			// reaches. Counting its reach would let a single theme setting be
			// 40% of the report and dominate the severity distribution; the
			// reach is shown as context on the finding itself instead.
			$counts[ $row->category ] = ( $counts[ $row->category ] ?? 0 ) + ( $check->is_store_level() ? 1 : (int) $row->cnt );
		}
		arsort( $counts );
		return $counts;
	}

	/**
	 * The catalog's open-issue total, counted the way every user-facing
	 * surface must count: applicable and scored checks only, store-level
	 * findings collapsed to one.
	 *
	 * The dashboard headline and the PDF cover both read this, so the two
	 * cannot print different totals for the same scan.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  int
	 */
	function count_open_effective() {
		return (int) array_sum( $this->count_open_scored_by_category() );
	}

	function count_open_by_category( $severity = '' ) {
		global $wpdb;

		if ( '' !== $severity ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; no WP API exists.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT category, COUNT(*) AS cnt FROM {$wpdb->prefix}wpfchs_issues WHERE status = 'open' AND severity = %s GROUP BY category",
					$severity
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; static query.
			$rows = $wpdb->get_results(
				"SELECT category, COUNT(*) AS cnt FROM {$wpdb->prefix}wpfchs_issues WHERE status = 'open' GROUP BY category"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row->category ] = (int) $row->cnt;
		}
		arsort( $counts );
		return $counts;

	}

	/**
	 * Marks all open issues of a check resolved for the given objects
	 * (used after a successful fix, so the UI updates without a rescan).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $check_id
	 * @param   array  $object_ids
	 * @return  int
	 */
	function resolve_for_objects( $check_id, $object_ids ) {
		global $wpdb;

		$object_ids = array_filter( array_map( 'absint', (array) $object_ids ) );
		if ( empty( $object_ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $object_ids ), '%d' ) );
		$params       = array_merge( array( current_time( 'mysql', true ), $check_id ), $object_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; placeholders are generated `%d` markers, all values go through prepare().
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wpfchs_issues
				SET status = 'resolved', resolved_at = %s
				WHERE status = 'open' AND check_id = %s AND object_id IN ( $placeholders )",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	}

	/**
	 * Reopens issues (used by fix undo).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $check_id
	 * @param   array  $object_ids
	 * @return  int
	 */
	function reopen_for_objects( $check_id, $object_ids ) {
		global $wpdb;

		$object_ids = array_filter( array_map( 'absint', (array) $object_ids ) );
		if ( empty( $object_ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $object_ids ), '%d' ) );
		$params       = array_merge( array( $check_id ), $object_ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; placeholders are generated `%d` markers, all values go through prepare().
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}wpfchs_issues
				SET status = 'open', resolved_at = NULL
				WHERE status = 'resolved' AND check_id = %s AND object_id IN ( $placeholders )",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	}

	/**
	 * Returns a single issue row.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $issue_id
	 * @return  object|null
	 */
	function get( $issue_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; no WP API exists.
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpfchs_issues WHERE id = %d", $issue_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Queries issues with filters.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $args {check_id, category, status, severity, product_id, first_seen_scan, limit, offset}
	 * @return  array of row objects
	 */
	function query( $args = array() ) {
		global $wpdb;

		list( $where, $params ) = $this->build_where( $args );

		$limit  = ( isset( $args['limit'] ) ? absint( $args['limit'] ) : 50 );
		$offset = ( isset( $args['offset'] ) ? absint( $args['offset'] ) : 0 );

		$params[] = $limit;
		$params[] = $offset;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; WHERE fragment is built from fixed column snippets with `%` placeholders, all values go through prepare().
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}wpfchs_issues $where ORDER BY product_id ASC, id ASC LIMIT %d OFFSET %d",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	}

	/**
	 * Counts issues matching filters.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $args
	 * @return  int
	 */
	function count( $args = array() ) {
		global $wpdb;

		list( $where, $params ) = $this->build_where( $args );

		if ( empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; static query.
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wpfchs_issues $where" );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; WHERE fragment is built from fixed column snippets with `%` placeholders, all values go through prepare().
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpfchs_issues $where", $params )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	}

	/**
	 * Open-issue counts grouped by check id.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string|null $category
	 * @return  array check_id => count
	 */
	function count_open_by_check( $category = null ) {
		global $wpdb;

		if ( null !== $category ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; no WP API exists.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT check_id, COUNT(*) AS cnt FROM {$wpdb->prefix}wpfchs_issues WHERE status = 'open' AND category = %s GROUP BY check_id",
					$category
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		} else {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; static query.
			$rows = $wpdb->get_results(
				"SELECT check_id, COUNT(*) AS cnt FROM {$wpdb->prefix}wpfchs_issues WHERE status = 'open' GROUP BY check_id"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row->check_id ] = (int) $row->cnt;
		}
		return $counts;

	}

	/**
	 * Open-issue counts for a set of product ids (products list column).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $product_ids
	 * @return  array product_id => count
	 */
	function count_open_by_product( $product_ids ) {
		global $wpdb;

		$product_ids = array_filter( array_map( 'absint', (array) $product_ids ) );
		if ( empty( $product_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; placeholders are generated `%d` markers, all values go through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) AS cnt FROM {$wpdb->prefix}wpfchs_issues WHERE status = 'open' AND product_id IN ( $placeholders ) GROUP BY product_id",
				$product_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->product_id ] = (int) $row->cnt;
		}
		return $counts;

	}

	/**
	 * Whether any open critical issue exists (used for the score cap).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	function has_open_critical() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; static query.
		return (bool) $wpdb->get_var(
			"SELECT 1 FROM {$wpdb->prefix}wpfchs_issues WHERE status = 'open' AND severity = 'critical' LIMIT 1"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Builds the WHERE clause for query()/count().
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $args
	 * @return  array [string $where, array $params]
	 */
	protected function build_where( $args ) {
		global $wpdb;

		$where  = array();
		$params = array();

		if ( ! empty( $args['check_id'] ) ) {
			$where[]  = 'check_id = %s';
			$params[] = $args['check_id'];
		}
		if ( ! empty( $args['category'] ) ) {
			$where[]  = 'category = %s';
			$params[] = $args['category'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['severity'] ) ) {
			$where[]  = 'severity = %s';
			$params[] = $args['severity'];
		}
		if ( ! empty( $args['product_id'] ) ) {
			$where[]  = 'product_id = %d';
			$params[] = absint( $args['product_id'] );
		}
		if ( ! empty( $args['first_seen_scan'] ) ) {
			$where[]  = 'first_seen_scan >= %d';
			$params[] = absint( $args['first_seen_scan'] );
		}
		if ( ! empty( $args['first_seen_between'] ) ) {
			$where[]  = 'first_seen_scan > %d AND first_seen_scan <= %d';
			$params[] = absint( $args['first_seen_between'][0] );
			$params[] = absint( $args['first_seen_between'][1] );
		}
		if ( ! empty( $args['reopened_between'] ) ) {
			$where[]  = 'last_reopened_scan > %d AND last_reopened_scan <= %d';
			$params[] = absint( $args['reopened_between'][0] );
			$params[] = absint( $args['reopened_between'][1] );
		}
		if ( ! empty( $args['resolved_between'] ) ) {
			$where[]  = "status = 'resolved' AND resolved_at > %s AND resolved_at <= %s";
			$params[] = $args['resolved_between'][0];
			$params[] = $args['resolved_between'][1];
		}
		if ( ! empty( $args['product_cat'] ) ) {
			$term = get_term( absint( $args['product_cat'] ), 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$where[]  = "product_id IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d )";
				$params[] = (int) $term->term_taxonomy_id;
			}
		}
		if ( ! empty( $args['product_type'] ) ) {
			$where[]  = "product_id IN (
				SELECT tr.object_id FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE tt.taxonomy = 'product_type' AND t.slug = %s
			)";
			$params[] = sanitize_key( $args['product_type'] );
		}

		return array(
			( ! empty( $where ) ? 'WHERE ' . implode( ' AND ', $where ) : '' ),
			$params,
		);

	}

	/**
	 * Open-issue counts per check with arbitrary filters (category tabs).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $args
	 * @return  array check_id => count
	 */
	function count_open_by_check_filtered( $args ) {
		global $wpdb;

		$args['status'] = 'open';
		list( $where, $params ) = $this->build_where( $args );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; WHERE fragment is built from fixed column snippets with `%` placeholders, all values go through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT check_id, COUNT(*) AS cnt FROM {$wpdb->prefix}wpfchs_issues $where GROUP BY check_id",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row->check_id ] = (int) $row->cnt;
		}
		return $counts;

	}

	/**
	 * Comparison between two completed scans: what is new, what was
	 * fixed, and what regressed, grouped by check.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   object $scan_a Older scan.
	 * @param   object $scan_b Newer scan.
	 * @return  array {new: array, fixed: array, regressed: array} each check_id => count
	 */
	function compare_scans( $scan_a, $scan_b ) {
		return array(
			'new'       => $this->count_open_by_check_filtered(
				array( 'first_seen_between' => array( (int) $scan_a->id, (int) $scan_b->id ) )
			),
			'fixed'     => $this->count_grouped_by_check(
				array( 'resolved_between' => array( $scan_a->completed_at, $scan_b->completed_at ) )
			),
			'regressed' => $this->count_grouped_by_check(
				array( 'reopened_between' => array( (int) $scan_a->id, (int) $scan_b->id ) )
			),
		);
	}

	/**
	 * Counts grouped by check for arbitrary filters (no status forced).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $args
	 * @return  array check_id => count
	 */
	function count_grouped_by_check( $args ) {
		global $wpdb;

		list( $where, $params ) = $this->build_where( $args );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table; WHERE fragment is built from fixed column snippets with `%` placeholders, all values go through prepare().
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT check_id, COUNT(*) AS cnt FROM {$wpdb->prefix}wpfchs_issues $where GROUP BY check_id",
				$params
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ $row->check_id ] = (int) $row->cnt;
		}
		return $counts;

	}

	/**
	 * Full rebuild of the per-product open-issue count meta that powers
	 * the sortable Health column. Runs once per completed scan.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function sync_product_counts() {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom issues table aggregation; runs once per scan.
		$rows = $wpdb->get_results(
			"SELECT product_id, COUNT(*) AS cnt FROM {$wpdb->prefix}wpfchs_issues WHERE status = 'open' GROUP BY product_id"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// Set-based rewrite of what used to be one update_post_meta() per
		// product. The per-row version was the single slowest thing the
		// plugin did (~17s at 3k products, minutes at 30k) and it runs inside
		// the scan's final request, where a host's max_execution_time is the
		// difference between "scan complete" and "scan stuck at 99%".
		//
		// Products losing their count need their meta cache flushed too, so
		// collect them before deleting.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Bulk postmeta swap with explicit cache invalidation below; a per-row API call per product does not survive catalog scale.
		$stale = array_map(
			'intval',
			(array) $wpdb->get_col(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wpfchs_open_issues'"
			)
		);

		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_wpfchs_open_issues'" );

		$cache_keys = $stale;
		foreach ( array_chunk( (array) $rows, 500 ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $row ) {
				$values[]     = $wpdb->prepare( '(%d, %s, %d)', (int) $row->product_id, '_wpfchs_open_issues', (int) $row->cnt );
				$cache_keys[] = (int) $row->product_id;
			}
			$wpdb->query(
				"INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $values )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		wp_cache_delete_multiple( array_unique( $cache_keys ), 'post_meta' );

	}

	/**
	 * Recounts the meta for the products behind a set of objects
	 * (after a fix, ignore, restore, or undo).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $object_ids Product or variation ids.
	 */
	function update_counts_for_objects( $object_ids ) {

		$product_ids = array();
		foreach ( array_filter( array_map( 'absint', (array) $object_ids ) ) as $object_id ) {
			$parent_id                   = (int) wp_get_post_parent_id( $object_id );
			$product_ids[ $parent_id ? $parent_id : $object_id ] = true;
		}

		foreach ( array_keys( $product_ids ) as $product_id ) {
			$count = $this->count(
				array(
					'product_id' => $product_id,
					'status'     => 'open',
				)
			);
			if ( $count > 0 ) {
				update_post_meta( $product_id, '_wpfchs_open_issues', $count );
			} else {
				delete_post_meta( $product_id, '_wpfchs_open_issues' );
			}
		}

	}

}

endif;

return new WPFCHS_Issues();
