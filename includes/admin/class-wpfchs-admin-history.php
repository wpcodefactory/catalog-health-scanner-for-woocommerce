<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - Admin History Tab Class
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
		// Scan comparison ships only in the Pro plugin; absent, not disabled.
		if ( wpfchs()->core->compare ) {
			wpfchs()->core->compare->render();
		}
		$this->render_scans();
		$this->render_fix_log();
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
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Scan history', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</h2></div>';

		if ( empty( $scans ) ) {
			echo '<p class="wpfchs-panel-empty">' . esc_html__( 'No completed scans yet.', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped wpfchs-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Profile', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Duration', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Products', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Issues found', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Score', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Report', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</th>';
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
				echo ' <span class="wpfchs-muted" title="' . esc_attr__( 'Recalculated after an applicability change; the trend stays comparable.', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '">*</span>';
			}
			echo '</td>';
			echo '<td>';
			if ( $core->report ) {
				echo '<a class="button-link" href="' . esc_url( $core->report->get_url( (int) $scan->id ) ) . '">' . esc_html__( 'PDF', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</a>';
			} else {
				echo '<span class="wpfchs-muted">&mdash;</span>';
			}
			echo '</td>';
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
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Fix activity log', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</h2></div>';

		if ( empty( $log ) ) {
			echo '<p class="wpfchs-panel-empty">' . esc_html__( 'No fixes applied yet.', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</p>';
			echo '</div>';
			return;
		}

		$undo_days = (int) $core->get_threshold( 'undo_window_days' );

		echo '<table class="widefat striped wpfchs-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Date', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Fix', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Products', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</th>';
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
				echo esc_html__( 'Undone', 'wpfactory-catalog-health-scanner-for-woocommerce' );
			} elseif ( $in_window ) {
				echo '<button type="button" class="button-link wpfchs-undo-fix" data-log="' . esc_attr( $entry->id ) . '">' . esc_html__( 'Undo', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</button>';
			} else {
				echo esc_html__( 'Applied', 'wpfactory-catalog-health-scanner-for-woocommerce' );
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
