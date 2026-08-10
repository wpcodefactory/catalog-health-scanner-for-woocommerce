<?php
/**
 * Catalog Health Scanner for WooCommerce - Admin History Tab Class
 *
 * Past scans and the fix activity log (with undo).
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Admin_History' ) ) :

class WPFCHS_Admin_History {

	/**
	 * render.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render() {
		$this->render_comparison();
		$this->render_scans();
		$this->render_fix_log();
	}

	/**
	 * Comparison view between any two scans: what was fixed, what
	 * regressed, and what is new (spec 8.3).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_comparison() {

		$core  = wpfchs()->core;
		$scans = $core->scanner->get_history( 50 );
		if ( count( $scans ) < 2 ) {
			return;
		}

		// Comparing scans is Pro. The free build shows the same toolbar with
		// the controls disabled — the moment this feature earns its keep is
		// exactly when a returning user has two scans to compare, so it must
		// be visible then, not hidden.
		$locked = $core->upgrade->is_locked();

		$compare_a = absint( filter_input( INPUT_GET, 'compare_a', FILTER_SANITIZE_NUMBER_INT ) );
		$compare_b = absint( filter_input( INPUT_GET, 'compare_b', FILTER_SANITIZE_NUMBER_INT ) );

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="wpfchs-card wpfchs-toolbar">';
		echo '<input type="hidden" name="page" value="wpfchs" />';
		echo '<input type="hidden" name="tab" value="history" />';
		echo '<strong>' . esc_html__( 'Compare scans', 'catalog-health-scanner-for-woocommerce' );
		if ( $locked ) {
			echo ' ' . $core->upgrade->badge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fully escaped in WPFCHS_Upgrade::badge().
		}
		echo '</strong>';

		foreach ( array( 'compare_a' => __( 'From', 'catalog-health-scanner-for-woocommerce' ), 'compare_b' => __( 'To', 'catalog-health-scanner-for-woocommerce' ) ) as $field => $label ) {
			$selected_id = ( 'compare_a' === $field ? $compare_a : $compare_b );
			echo '<label>' . esc_html( $label ) . ' <select name="' . esc_attr( $field ) . '"' . disabled( $locked, true, false ) . '>';
			foreach ( $scans as $scan ) {
				echo '<option value="' . esc_attr( $scan->id ) . '"' . selected( (int) $scan->id, $selected_id, false ) . '>';
				echo esc_html( get_date_from_gmt( $scan->completed_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) . ' — ' . wc_format_decimal( (float) $scan->score, 0 ) . '%' );
				echo '</option>';
			}
			echo '</select></label>';
		}

		if ( $locked ) {
			echo $core->upgrade->lock_button( __( 'Compare', 'catalog-health-scanner-for-woocommerce' ), __( 'Compare scans', 'catalog-health-scanner-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fully escaped in WPFCHS_Upgrade::lock_button().
			echo '<span class="wpfchs-muted">' . esc_html__( 'See exactly what improved, what regressed, and what is new between any two scans.', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
		} else {
			echo '<button type="submit" class="button">' . esc_html__( 'Compare', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
		}
		echo '</form>';

		// The diff itself is Pro-only, even if compare_a/b arrive in the URL.
		if ( $locked || ! $compare_a || ! $compare_b || $compare_a === $compare_b ) {
			return;
		}

		$scan_a = $core->scanner->get_scan( min( $compare_a, $compare_b ) );
		$scan_b = $core->scanner->get_scan( max( $compare_a, $compare_b ) );
		if ( ! $scan_a || ! $scan_b || 'complete' !== $scan_a->status || 'complete' !== $scan_b->status ) {
			return;
		}

		$diff   = $core->issues->compare_scans( $scan_a, $scan_b );
		$checks = $core->checks;
		$delta  = (float) $scan_b->score - (float) $scan_a->score;

		echo '<div class="wpfchs-card wpfchs-panel">';
		echo '<div class="wpfchs-panel-head"><h2>';
		printf(
			/* translators: %1$s: older scan date, %2$s: newer scan date. */
			esc_html__( 'Comparison: %1$s → %2$s', 'catalog-health-scanner-for-woocommerce' ),
			esc_html( get_date_from_gmt( $scan_a->completed_at, get_option( 'date_format' ) ) ),
			esc_html( get_date_from_gmt( $scan_b->completed_at, get_option( 'date_format' ) ) )
		);
		echo '</h2>';
		echo '<span class="wpfchs-band" style="color:' . esc_attr( $delta >= 0 ? '#00812c' : '#d63638' ) . '">';
		echo esc_html( ( $delta >= 0 ? '+' : '' ) . wc_format_decimal( $delta, 1 ) . '%' );
		echo '</span></div>';

		$sections = array(
			'fixed'     => array( __( 'Fixed', 'catalog-health-scanner-for-woocommerce' ), '#00812c' ),
			'regressed' => array( __( 'Regressed', 'catalog-health-scanner-for-woocommerce' ), '#d63638' ),
			'new'       => array( __( 'New', 'catalog-health-scanner-for-woocommerce' ), '#996800' ),
		);

		foreach ( $sections as $key => $section ) {
			$total = array_sum( $diff[ $key ] );
			echo '<div class="wpfchs-panel-row wpfchs-compare-row">';
			echo '<strong style="color:' . esc_attr( $section[1] ) . ';min-width:90px">' . esc_html( $section[0] ) . '</strong>';
			if ( 0 === $total ) {
				echo '<span class="wpfchs-muted">' . esc_html__( 'None', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
			} else {
				$parts = array();
				arsort( $diff[ $key ] );
				foreach ( array_slice( $diff[ $key ], 0, 8, true ) as $check_id => $count ) {
					$check   = $checks->get( $check_id );
					$parts[] = esc_html( ( $check ? $check->get_label() : $check_id ) . ' (' . number_format_i18n( $count ) . ')' );
				}
				echo '<span>' . wp_kses_post( implode( ' &middot; ', $parts ) ) . '</span>';
				echo '<span class="wpfchs-muted wpfchs-count">';
				printf(
					/* translators: %s: total issue count. */
					esc_html( _n( '%s issue', '%s issues', $total, 'catalog-health-scanner-for-woocommerce' ) ),
					esc_html( number_format_i18n( $total ) )
				);
				echo '</span>';
			}
			echo '</div>';
		}

		echo '</div>';

	}

	/**
	 * render_scans.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_scans() {

		$core     = wpfchs()->core;
		$scans    = $core->scanner->get_history( 50 );
		$profiles = $core->profiles->get_all();

		echo '<div class="wpfchs-card wpfchs-panel">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Scan history', 'catalog-health-scanner-for-woocommerce' ) . '</h2></div>';

		if ( empty( $scans ) ) {
			echo '<p class="wpfchs-panel-empty">' . esc_html__( 'No completed scans yet.', 'catalog-health-scanner-for-woocommerce' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped wpfchs-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Profile', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Duration', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Products', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Issues found', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Score', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Report', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $scans as $scan ) {
			$duration = (
				$scan->completed_at ?
				human_time_diff( strtotime( $scan->started_at ), strtotime( $scan->completed_at ) ) :
				''
			);
			$band = $core->scores->get_band( (float) $scan->score );
			$data = json_decode( (string) $scan->score_data, true );
			echo '<tr>';
			echo '<td>' . esc_html( get_date_from_gmt( $scan->completed_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</td>';
			echo '<td>' . esc_html( $profiles[ $scan->profile ]['label'] ?? $scan->profile ) . '</td>';
			echo '<td>' . esc_html( $duration ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $scan->products_scanned ) ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $scan->issues_found ) ) . '</td>';
			echo '<td><strong style="color:' . esc_attr( $band['color'] ) . '">' . esc_html( wc_format_decimal( (float) $scan->score, 0 ) ) . '%</strong>';
			if ( ! empty( $data['recalculated'] ) ) {
				echo ' <span class="wpfchs-muted" title="' . esc_attr__( 'Recalculated after an applicability change; the trend stays comparable.', 'catalog-health-scanner-for-woocommerce' ) . '">*</span>';
			}
			echo '</td>';
			echo '<td>' . $core->upgrade->lock_link( __( 'PDF', 'catalog-health-scanner-for-woocommerce' ), __( 'PDF report', 'catalog-health-scanner-for-woocommerce' ), $core->report->get_url( (int) $scan->id ), 'button-link' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fully escaped in WPFCHS_Upgrade::lock_link().
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

	}

	/**
	 * render_fix_log.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_fix_log() {

		$core = wpfchs()->core;
		$log  = $core->fixes->get_log( 25 );

		echo '<div class="wpfchs-card wpfchs-panel">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Fix activity log', 'catalog-health-scanner-for-woocommerce' ) . '</h2></div>';

		if ( empty( $log ) ) {
			echo '<p class="wpfchs-panel-empty">' . esc_html__( 'No fixes applied yet.', 'catalog-health-scanner-for-woocommerce' ) . '</p>';
			echo '</div>';
			return;
		}

		$undo_days = (int) $core->get_threshold( 'undo_window_days' );

		echo '<table class="widefat striped wpfchs-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Fix', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Products', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $log as $entry ) {
			$check = $core->checks->get( $entry->check_id );
			$user  = get_userdata( $entry->user_id );
			$in_window = ( strtotime( $entry->created_at ) >= ( time() - $undo_days * DAY_IN_SECONDS ) );
			echo '<tr>';
			echo '<td>' . esc_html( get_date_from_gmt( $entry->created_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : '#' . $entry->user_id ) . '</td>';
			echo '<td>' . esc_html( $check ? $check->get_label() : $entry->check_id ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( (int) $entry->items_count ) ) . '</td>';
			echo '<td>';
			if ( $entry->undone ) {
				echo esc_html__( 'Undone', 'catalog-health-scanner-for-woocommerce' );
			} elseif ( $in_window ) {
				echo '<button type="button" class="button-link wpfchs-undo-fix" data-log="' . esc_attr( $entry->id ) . '">' . esc_html__( 'Undo', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
			} else {
				echo esc_html__( 'Applied', 'catalog-health-scanner-for-woocommerce' );
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

	}

}

endif;

return new WPFCHS_Admin_History();
