<?php
/**
 * Catalog Health Scanner for WooCommerce - Scores Class
 *
 * Scoring rules (spec section 9):
 * - each check contributes points equal to its severity weight, earned in
 *   proportion to the products that pass;
 * - excluded checks leave both sides of the fraction;
 * - ignored issues count as passing (they never increment `failed`);
 * - one open critical issue caps the total at 89.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Scores' ) ) :

class WPFCHS_Scores {

	/**
	 * Computes category scores and the weighted total from scan counters.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $counters check_id => {checked, failed}
	 * @param   array $checks   check_id => WPFCHS_Check
	 * @return  array {total: float, capped: bool, categories: array}
	 */
	function compute( $counters, $checks ) {

		$registry   = wpfchs()->core->checks;
		$categories = array();

		foreach ( $checks as $check_id => $check ) {

			if ( ! isset( $counters[ $check_id ] ) || $counters[ $check_id ]['checked'] < 1 ) {
				continue;
			}
			if ( ! $registry->is_scored( $check ) ) {
				continue;
			}

			$checked = (int) $counters[ $check_id ]['checked'];
			$failed  = min( $checked, (int) $counters[ $check_id ]['failed'] );

			// A store-level check is one finding, pass or fail: its single
			// root cause must not cost N products' worth of category score.
			if ( $check->is_store_level() ) {
				$checked = 1;
				$failed  = min( 1, $failed );
			}

			$weight  = $check->get_weight();
			$earned  = $weight * ( ( $checked - $failed ) / $checked );

			$category = $check->get_category();
			if ( ! isset( $categories[ $category ] ) ) {
				$categories[ $category ] = array( 'earned' => 0.0, 'possible' => 0.0 );
			}
			$categories[ $category ]['earned']   += $earned;
			$categories[ $category ]['possible'] += $weight;

		}

		$earned_total   = 0.0;
		$possible_total = 0.0;
		foreach ( $categories as $category => $points ) {
			$categories[ $category ]['earned']   = round( $points['earned'], 2 );
			$categories[ $category ]['possible'] = round( $points['possible'], 2 );
			$earned_total   += $points['earned'];
			$possible_total += $points['possible'];
		}

		$total = ( $possible_total > 0 ? ( $earned_total / $possible_total ) * 100 : 100.0 );

		// A store with an unpurchasable product is not in a healthy state,
		// regardless of arithmetic. Derived from the scan's own counters so
		// historic recalculations stay faithful to what that scan saw.
		$capped = false;
		if ( $total > 89 ) {
			foreach ( $checks as $check_id => $check ) {
				// Non-scored groups (not applicable / report-only) must not
				// influence the score in any way, including the cap.
				if ( ! $registry->is_scored( $check ) ) {
					continue;
				}
				if (
					'critical' === $check->get_severity() &&
					! empty( $counters[ $check_id ]['failed'] )
				) {
					$total  = 89.0;
					$capped = true;
					break;
				}
			}
		}

		return array(
			'total'      => round( $total, 2 ),
			'capped'     => $capped,
			'categories' => $categories,
		);

	}

	/**
	 * Recalculates every completed scan's score from its stored counters
	 * using the CURRENT applicability configuration, so the trend line
	 * stays comparable after a configuration change. Each recalculated
	 * scan is marked (spec section 9, score integrity rules).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  int Number of scans recalculated.
	 */
	function recalculate_history() {
		global $wpdb;

		$scanner = wpfchs()->core->scanner;
		$scans   = $scanner->get_history( 500 );
		$updated = 0;

		foreach ( $scans as $scan ) {

			$data = json_decode( (string) $scan->score_data, true );
			if ( ! is_array( $data ) || empty( $data['counters'] ) ) {
				continue;
			}

			$checks = $scanner->get_scan_checks( $scan );
			$scores = $this->compute( $data['counters'], $checks );

			if ( abs( $scores['total'] - (float) $scan->score ) < 0.01 ) {
				continue; // Unchanged by the new configuration.
			}

			$data['categories']     = $scores['categories'];
			$data['recalculated']   = current_time( 'mysql', true );
			$data['original_score'] = (float) $scan->score;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom scans table; no WP API exists.
			$wpdb->update(
				$wpdb->prefix . 'wpfchs_scans',
				array(
					'score'      => $scores['total'],
					'score_data' => wp_json_encode( $data ),
				),
				array( 'id' => $scan->id ),
				array( '%f', '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$updated++;

		}

		return $updated;

	}

	/**
	 * Score band for a total score.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   float $score
	 * @return  array {id, label, color}
	 */
	function get_band( $score ) {
		if ( $score >= 90 ) {
			return array(
				'id'    => 'healthy',
				'label' => __( 'Healthy', 'catalog-health-scanner-for-woocommerce' ),
				'color' => '#00a32b',
			);
		}
		if ( $score >= 75 ) {
			return array(
				'id'    => 'minor',
				'label' => __( 'Minor issues', 'catalog-health-scanner-for-woocommerce' ),
				'color' => '#dba617',
			);
		}
		if ( $score >= 50 ) {
			return array(
				'id'    => 'attention',
				'label' => __( 'Needs attention', 'catalog-health-scanner-for-woocommerce' ),
				'color' => '#e65054',
			);
		}
		return array(
			'id'    => 'critical',
			'label' => __( 'Critical', 'catalog-health-scanner-for-woocommerce' ),
			'color' => '#d63638',
		);
	}

	/**
	 * Band for a category fraction (earned / possible), for card badges.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   float $earned
	 * @param   float $possible
	 * @return  array {id, label, color}
	 */
	function get_category_band( $earned, $possible ) {
		return $this->get_band( $possible > 0 ? ( $earned / $possible ) * 100 : 100 );
	}

	/**
	 * The health badge: severity presence overrides the score band.
	 *
	 * Severity weighting can hold the arithmetic at 84% while a product
	 * nobody can buy sits open — and "Minor issues" a hundred pixels above a
	 * red critical banner reads as a contradiction. A badge may never say
	 * anything kinder than the worst open issue:
	 *
	 * - any open Critical  → "Critical issues open"
	 * - open High, no Crit → "Needs attention"
	 * - otherwise          → the score band as before
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   float $score
	 * @param   int   $critical_open Open critical issues in scope.
	 * @param   int   $high_open     Open high issues in scope.
	 * @return  array {id, label, color}
	 */
	function get_status_badge( $score, $critical_open, $high_open ) {
		if ( $critical_open > 0 ) {
			return array(
				'id'    => 'critical-open',
				'label' => __( 'Critical issues open', 'catalog-health-scanner-for-woocommerce' ),
				'color' => '#d63638',
			);
		}
		if ( $high_open > 0 ) {
			return array(
				'id'    => 'attention',
				'label' => __( 'Needs attention', 'catalog-health-scanner-for-woocommerce' ),
				'color' => '#e65054',
			);
		}
		return $this->get_band( $score );
	}

	/**
	 * Category variant of the severity-overridden badge.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   float $earned
	 * @param   float $possible
	 * @param   int   $critical_open Open critical issues in the category.
	 * @param   int   $high_open     Open high issues in the category.
	 * @return  array {id, label, color}
	 */
	function get_category_badge( $earned, $possible, $critical_open, $high_open ) {
		return $this->get_status_badge(
			( $possible > 0 ? ( $earned / $possible ) * 100 : 100 ),
			$critical_open,
			$high_open
		);
	}

}

endif;

return new WPFCHS_Scores();
