<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - Admin Dashboard Class
 *
 * The landing screen: overall score, trend, scan controls, category
 * summary grid, quick wins, top issues.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Admin_Dashboard' ) ) :

class WPFCHS_Admin_Dashboard {

	/**
	 * render.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render() {

		$core    = wpfchs()->core;
		$scanner = $core->scanner;
		$running = $scanner->get_running();

		$this->maybe_render_welcome();
		$this->render_scan_controls( $running );

		if ( $running ) {
			$this->render_progress( $running );
		}

		$last = wpfchs()->core->admin->get_last_scan_data();

		if ( ! $last['scan'] ) {
			if ( ! $running ) {
				$this->render_empty_state();
			}
			return;
		}

		$this->render_stale_notice( $last['scan'] );
		$this->render_score_panel( $last['scan'] );
		$this->render_skipped_notice( $last['scan'] );
		$this->render_critical_banner();
		$this->render_category_grid( $last['categories'] );

		echo '<div class="wpfchs-columns">';
		$this->render_quick_wins();
		$this->render_top_issues();
		echo '</div>';

	}

	/**
	 * render_scan_controls.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   object|null $running
	 */
	function render_scan_controls( $running ) {

		$core     = wpfchs()->core;
		$profiles = $core->profiles->get_all();
		$selected = $core->profiles->get_selected();
		$last     = $core->scanner->get_last_completed();

		echo '<div class="wpfchs-card wpfchs-scan-controls">';

		echo '<label for="wpfchs-profile">' . esc_html__( 'Scan profile', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</label> ';
		echo '<select id="wpfchs-profile"' . ( $running ? ' disabled' : '' ) . '>';
		foreach ( $profiles as $profile_id => $profile ) {
			echo '<option value="' . esc_attr( $profile_id ) . '"' . selected( $profile_id, $selected, false ) . '>' . esc_html( $profile['label'] ) . '</option>';
		}
		echo '</select> ';

		if ( $running ) {
			if ( 'paused' === $running->status ) {
				echo '<button type="button" class="button button-primary wpfchs-start-scan" id="wpfchs-resume-scan" data-scan="' . esc_attr( $running->id ) . '">' . esc_html__( 'Resume Scan', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</button> ';
			} else {
				echo '<button type="button" class="button" id="wpfchs-pause-scan" data-scan="' . esc_attr( $running->id ) . '">' . esc_html__( 'Pause', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</button> ';
			}
			echo '<button type="button" class="button" id="wpfchs-cancel-scan" data-scan="' . esc_attr( $running->id ) . '">' . esc_html__( 'Cancel', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</button>';
		} else {
			echo '<button type="button" class="button button-primary wpfchs-start-scan" id="wpfchs-run-scan">' . esc_html__( 'Run Scan', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</button> ';

			// Changes-only rescan. Offered only once a previous scan exists to
			// measure "since" from, and labelled with the number of products it
			// will actually visit — "Scan 12 changed products" is a promise the
			// user can check, where "Quick scan" is a guess they cannot.
			if ( $last ) {
				$changed = $core->scanner->count_products( (string) $last->completed_at );
				echo '<button type="button" class="button wpfchs-start-scan" data-mode="incremental"' . ( $changed < 1 ? ' disabled' : '' ) . '>';
				if ( $changed > 0 ) {
					printf(
						/* translators: %s: number of products edited since the last scan. */
						esc_html( _n( 'Scan %s changed product', 'Scan %s changed products', $changed, 'wpfactory-catalog-health-scanner-for-woocommerce' ) ),
						esc_html( number_format_i18n( $changed ) )
					);
				} else {
					esc_html_e( 'No changes since last scan', 'wpfactory-catalog-health-scanner-for-woocommerce' );
				}
				echo '</button> ';
			}
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=wpfchs-settings#wpfchs-schedule' ) ) . '">' . esc_html__( 'Schedule', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</a>';
			if ( $last && $core->report ) {
				echo ' <a class="button" href="' . esc_url( $core->report->get_url() ) . '">' . esc_html__( 'PDF report', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</a>';
			}
		}

		if ( $last ) {
			echo '<span class="wpfchs-scan-meta">';
			printf(
				/* translators: %1$s: human time diff since last scan, %2$s: number of products scanned. */
				esc_html__( 'Last scan: %1$s ago · %2$s products', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				esc_html( human_time_diff( strtotime( $last->completed_at . ' UTC' ) ) ),
				esc_html( number_format_i18n( (int) $last->products_scanned ) )
			);
			echo '</span>';
		}

		echo '</div>';

	}

	/**
	 * render_progress.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   object $running
	 */
	function render_progress( $running ) {
		$progress = wpfchs()->core->scanner->progress( $running );
		echo '<div class="wpfchs-card wpfchs-progress" id="wpfchs-progress" data-scan="' . esc_attr( $progress['id'] ) . '" data-status="' . esc_attr( $progress['status'] ) . '">';
		echo '<div class="wpfchs-progress-bar"><div class="wpfchs-progress-fill" style="width:' . esc_attr( $progress['percent'] ) . '%"></div></div>';
		echo '<span class="wpfchs-progress-text">';
		printf(
			/* translators: %1$s: scanned count, %2$s: total count. */
			esc_html__( 'Scanning: %1$s of %2$s products', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
			esc_html( number_format_i18n( $progress['scanned'] ) ),
			esc_html( number_format_i18n( $progress['total'] ) )
		);
		echo '</span>';
		echo '</div>';
	}

	/**
	 * render_empty_state.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_empty_state() {
		echo '<div class="wpfchs-card wpfchs-empty-state">';
		echo '<h2>' . esc_html__( 'Run your first scan', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Find the products that are silently costing you sales. Revenue Blockers is a fast first look — critical issues only. It takes seconds on most catalogs.', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</p>';
		echo '<p><button type="button" class="button button-primary button-hero wpfchs-start-scan">' . esc_html__( 'Scan my catalog now', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</button></p>';
		echo '</div>';
	}

	/**
	 * One-time welcome banner shown right after the setup wizard finishes.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function maybe_render_welcome() {
		if ( ! filter_input( INPUT_GET, 'wpfchs_setup_done', FILTER_SANITIZE_NUMBER_INT ) ) {
			return;
		}
		echo '<div class="wpfchs-banner wpfchs-banner-welcome">';
		echo '<span><strong>' . esc_html__( 'Setup complete.', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</strong> ';
		esc_html_e( 'Your store is configured. Run your first scan to see where you stand.', 'wpfactory-catalog-health-scanner-for-woocommerce' );
		echo '</span>';
		echo '<button type="button" class="button button-primary wpfchs-start-scan">' . esc_html__( 'Run first scan', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</button>';
		echo '</div>';
	}

	/**
	 * render_score_panel.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   object $scan
	 */
	function render_score_panel( $scan ) {

		$core    = wpfchs()->core;
		$score   = (float) $scan->score;
		// The badge may never read kinder than the worst open issue: severity
		// presence overrides the score band (only scored checks count — a
		// group the user switched off cannot redden the badge).
		$band    = $core->scores->get_status_badge(
			$score,
			array_sum( $core->issues->count_open_scored_by_category( 'critical' ) ),
			array_sum( $core->issues->count_open_scored_by_category( 'high' ) )
		);
		$open    = $core->issues->count_open_effective();
		$ignored = $core->issues->count( array( 'status' => 'ignored' ) );

		// Only scans that measured something belong on the chart.
		$history = array_reverse( $core->scanner->get_trend_history( 8 ) );

		echo '<div class="wpfchs-card wpfchs-score-panel">';

		echo '<div class="wpfchs-score-main">';
		echo $this->render_gauge( $score, $band ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped numeric/colour parts.
		echo '<span class="wpfchs-score-labels">';
		echo '<strong>' . esc_html__( 'Catalog Health', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</strong>';
		echo '<span class="wpfchs-chip wpfchs-score-band" style="color:' . esc_attr( $band['color'] ) . ';background:' . esc_attr( $band['color'] ) . '1a">' . esc_html( $band['label'] ) . '</span>';
		echo '<span class="wpfchs-muted">';
		printf(
			/* translators: %s: number of open issues. */
			esc_html( _n( '%s open issue', '%s open issues', $open, 'wpfactory-catalog-health-scanner-for-woocommerce' ) ),
			esc_html( number_format_i18n( $open ) )
		);

		// An ignored issue is still a broken product; it is only excluded from
		// the score. Without this line a catalog where everything was ignored
		// reads as a flawless 100%, which is exactly the impression a
		// white-label client report must never give.
		if ( $ignored > 0 ) {
			echo ' &middot; <a class="wpfchs-ignored-link" href="' . esc_url( admin_url( 'admin.php?page=wpfchs-settings#wpfchs-ignored' ) ) . '">';
			printf(
				/* translators: %s: number of ignored issues. */
				esc_html( _n( '%s ignored, not counted', '%s ignored, not counted', $ignored, 'wpfactory-catalog-health-scanner-for-woocommerce' ) ),
				esc_html( number_format_i18n( $ignored ) )
			);
			echo '</a>';
		}

		echo '</span>';
		echo '</span>';
		echo '</div>';

		if ( count( $history ) > 1 ) {
			$this->render_trend( $history );
		}

		echo '</div>';

	}

	/**
	 * Circular score gauge (SVG donut) with the percentage in the centre,
	 * the arc coloured by the score band.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   float $score
	 * @param   array $band
	 * @return  string
	 */
	function render_gauge( $score, $band ) {

		$size   = 120;
		$stroke = 12;
		$r      = ( $size - $stroke ) / 2;
		$cx     = $size / 2;
		$circ   = 2 * M_PI * $r;
		$offset = $circ * ( 1 - max( 0, min( 100, $score ) ) / 100 );

		$svg  = '<svg class="wpfchs-gauge" width="' . esc_attr( $size ) . '" height="' . esc_attr( $size ) . '" viewBox="0 0 ' . esc_attr( $size ) . ' ' . esc_attr( $size ) . '" role="img" aria-label="' . esc_attr( sprintf( /* translators: %s: score. */ __( 'Catalog health score: %s percent', 'wpfactory-catalog-health-scanner-for-woocommerce' ), wc_format_decimal( $score, 0 ) ) ) . '">';
		$svg .= '<circle cx="' . esc_attr( $cx ) . '" cy="' . esc_attr( $cx ) . '" r="' . esc_attr( $r ) . '" fill="none" stroke="#eef0f1" stroke-width="' . esc_attr( $stroke ) . '"></circle>';
		$svg .= '<circle cx="' . esc_attr( $cx ) . '" cy="' . esc_attr( $cx ) . '" r="' . esc_attr( $r ) . '" fill="none" stroke="' . esc_attr( $band['color'] ) . '" stroke-width="' . esc_attr( $stroke ) . '" stroke-linecap="round" stroke-dasharray="' . esc_attr( round( $circ, 2 ) ) . '" stroke-dashoffset="' . esc_attr( round( $offset, 2 ) ) . '" transform="rotate(-90 ' . esc_attr( $cx ) . ' ' . esc_attr( $cx ) . ')"></circle>';
		$svg .= '<text x="' . esc_attr( $cx ) . '" y="' . esc_attr( $cx + 2 ) . '" text-anchor="middle" dominant-baseline="middle" font-size="30" font-weight="700" fill="#1d2327">' . esc_html( wc_format_decimal( $score, 0 ) ) . '%</text>';
		$svg .= '</svg>';

		return $svg;

	}

	/**
	 * Inline SVG trend line over the last scans.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $history Oldest first.
	 */
	function render_trend( $history ) {

		$width  = 240;
		$height = 56;
		$count  = count( $history );
		$points = array();

		foreach ( $history as $i => $scan ) {
			$x        = 4 + ( $i / max( 1, $count - 1 ) ) * ( $width - 8 );
			$y        = 4 + ( 1 - ( (float) $scan->score / 100 ) ) * ( $height - 8 );
			$points[] = round( $x, 1 ) . ',' . round( $y, 1 );
		}

		$first = (float) $history[0]->score;
		$last  = (float) end( $history )->score;

		echo '<div class="wpfchs-trend">';
		echo '<span class="wpfchs-trend-label">';
		printf(
			/* translators: %d: number of scans in the trend. */
			esc_html__( 'Trend · last %d scans', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
			(int) $count
		);
		echo '</span>';
		echo '<svg width="' . esc_attr( $width ) . '" height="' . esc_attr( $height ) . '" viewBox="0 0 ' . esc_attr( $width ) . ' ' . esc_attr( $height ) . '" role="img" aria-hidden="true">';
		echo '<polyline points="' . esc_attr( implode( ' ', $points ) ) . '" fill="none" stroke="#2271b1" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"></polyline>';
		// A dot per scan, so the number of plotted points is countable and
		// matches the label. Without them a run of identical scores is a
		// featureless line that reads as two points however many there are.
		foreach ( $points as $i => $point ) {
			list( $px, $py ) = explode( ',', $point );
			$is_last         = ( $i === count( $points ) - 1 );
			echo '<circle cx="' . esc_attr( $px ) . '" cy="' . esc_attr( $py ) . '" r="' . ( $is_last ? '3.5' : '2' ) . '" fill="' . ( $is_last ? '#2271b1' : '#8ab6dc' ) . '"></circle>';
		}
		echo '</svg>';
		echo '<span class="wpfchs-trend-range"><span>' . esc_html( wc_format_decimal( $first, 0 ) ) . '%</span><span>' . esc_html( wc_format_decimal( $last, 0 ) ) . '%</span></span>';
		echo '</div>';

	}

	/**
	 * Warns when the displayed results predate a settings reset.
	 *
	 * Scan history deliberately survives a reset, but the results on screen
	 * were produced under the configuration that was just discarded. Without
	 * this, finishing the setup wizard on a freshly reset store lands on a
	 * full results page for a scan the user never ran under these settings.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   object $scan
	 */
	function render_stale_notice( $scan ) {

		$changed_at = (int) get_option( 'wpfchs_settings_changed_at', 0 );
		if ( $changed_at < 1 || empty( $scan->completed_at ) ) {
			return;
		}

		$scanned_at = (int) strtotime( $scan->completed_at . ' UTC' );
		if ( $scanned_at >= $changed_at ) {
			return;
		}

		echo '<div class="wpfchs-alert wpfchs-alert-warning">';
		echo '<span class="wpfchs-alert-icon wpfchs-alert-icon-warning" aria-hidden="true">i</span>';
		echo '<span class="wpfchs-alert-text">';
		echo '<strong>' . esc_html__( 'These results predate your settings change.', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</strong> ';
		printf(
			/* translators: %s: human-readable time difference. */
			esc_html__( 'This scan ran %s ago, under the settings you have since reset. Run a new scan for results that match your current configuration.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
			esc_html( human_time_diff( $scanned_at ) )
		);
		echo '</span>';
		echo '<button type="button" class="button button-primary wpfchs-start-scan">' . esc_html__( 'Run scan', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</button>';
		echo '</div>';

	}

	/**
	 * Warns when the last scan did not cover the whole catalog.
	 *
	 * A score computed from part of the catalog is not a score for the
	 * catalog, and the products a scan skips are usually the ones just
	 * imported — the ones most likely to be broken. Saying nothing here would
	 * let "we ignored your import" read as "your import is healthy".
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   object $scan
	 */
	function render_skipped_notice( $scan ) {

		$core    = wpfchs()->core;
		$skipped = $core->scanner->get_skipped_count( $scan );

		if ( $skipped < 1 ) {
			return;
		}

		$grace_days   = (int) $core->get_threshold( 'grace_period_days' );
		$excluded     = $core->scanner->get_excluded_category_ids();
		$settings_url = admin_url( 'admin.php?page=wpfchs-settings' );

		echo '<div class="wpfchs-alert wpfchs-alert-warning">';
		echo '<span class="wpfchs-alert-icon wpfchs-alert-icon-warning" aria-hidden="true">i</span>';
		echo '<span class="wpfchs-alert-text">';
		echo '<strong>';
		printf(
			/* translators: %s: number of products skipped. */
			esc_html( _n( '%s product was not checked in this scan.', '%s products were not checked in this scan.', $skipped, 'wpfactory-catalog-health-scanner-for-woocommerce' ) ),
			esc_html( number_format_i18n( $skipped ) )
		);
		echo '</strong> ';

		// An incremental scan skipped them on purpose, so say that rather than
		// blaming the grace period or an excluded category.
		$data = json_decode( (string) $scan->score_data, true );
		if ( 'incremental' === ( is_array( $data ) ? ( $data['mode'] ?? 'full' ) : 'full' ) ) {
			esc_html_e( 'This was a changes-only scan, so it looked at products edited since the previous scan and left the rest as they were. Run a full scan for a score that covers the whole catalog.', 'wpfactory-catalog-health-scanner-for-woocommerce' );
			echo '</span>';
			echo '<button type="button" class="wpfchs-cta-warning wpfchs-start-scan" data-mode="full">' . esc_html__( 'Run a full scan', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . ' &rarr;</button>';
			echo '</div>';
			return;
		}

		if ( $grace_days > 0 && ! empty( $excluded ) ) {
			printf(
				/* translators: %d: grace period in days. */
				esc_html__( 'They are either newer than your %d-day grace period or sit in an excluded category, so your score does not cover them.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				(int) $grace_days
			);
		} elseif ( $grace_days > 0 ) {
			printf(
				/* translators: %d: grace period in days. */
				esc_html__( 'They are newer than your %d-day grace period, so your score does not cover them. Turn the grace period off to include everything.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				(int) $grace_days
			);
		} else {
			esc_html_e( 'They sit in a category excluded from scanning, so your score does not cover them.', 'wpfactory-catalog-health-scanner-for-woocommerce' );
		}

		echo '</span>';
		echo '<a class="wpfchs-cta-warning" href="' . esc_url( $settings_url . '#wpfchs-scope' ) . '">' . esc_html__( 'Change what gets scanned', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . ' &rarr;</a>';
		echo '</div>';

	}

	/**
	 * Critical banner: products that cannot be purchased right now.
	 *
	 * Criticals are rarely confined to one category, so the banner shows the
	 * breakdown and every chip deep-links to that category already filtered
	 * to critical severity. The primary CTA targets whichever category holds
	 * the most — never a hard-coded tab, which used to strand people on
	 * Purchasability while criticals sat in Downloads or Structure.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_critical_banner() {

		$core       = wpfchs()->core;
		$categories = $core->checks->get_categories();
		// Scored checks only: an alarm about issues the user has marked "not
		// applicable" (a catalog store and its unpriced products, say) is
		// noise shouted in red.
		$by_cat     = $core->issues->count_open_scored_by_category( 'critical' );
		$critical   = array_sum( $by_cat );

		if ( $critical < 1 ) {
			return;
		}

		$first_cat = key( $by_cat );
		$cta_url   = add_query_arg(
			array(
				'page'     => 'wpfchs',
				'tab'      => $first_cat,
				'severity' => 'critical',
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="wpfchs-alert wpfchs-alert-critical">';
		echo '<span class="wpfchs-alert-icon" aria-hidden="true">!</span>';
		echo '<span class="wpfchs-alert-text">';
		echo '<strong>';
		printf(
			/* translators: %s: number of critical issues. */
			esc_html( _n( '%s critical issue is costing you sales right now.', '%s critical issues are costing you sales right now.', $critical, 'wpfactory-catalog-health-scanner-for-woocommerce' ) ),
			esc_html( number_format_i18n( $critical ) )
		);
		echo '</strong> ';
		esc_html_e( 'Unpurchasable products, negative margins, or broken downloads.', 'wpfactory-catalog-health-scanner-for-woocommerce' );

		echo '<span class="wpfchs-alert-breakdown">';
		foreach ( $by_cat as $category_id => $count ) {
			$url = add_query_arg(
				array(
					'page'     => 'wpfchs',
					'tab'      => $category_id,
					'severity' => 'critical',
				),
				admin_url( 'admin.php' )
			);
			echo '<a class="wpfchs-alert-chip" href="' . esc_url( $url ) . '">';
			echo esc_html( $categories[ $category_id ] ?? $category_id );
			echo '<b>' . esc_html( number_format_i18n( $count ) ) . '</b>';
			echo '</a>';
		}
		echo '</span>';

		echo '</span>';

		// "Review & fix" only when at least one open critical check actually
		// has a fixer. Every current critical check is manual-only, so the
		// button must not promise a fix the screen cannot deliver.
		$fixable = false;
		foreach ( array_keys( $core->issues->count_open_by_check() ) as $check_id ) {
			$check = $core->checks->get( $check_id );
			if ( $check && 'critical' === $check->get_severity() && $check->get_fixer() ) {
				$fixable = true;
				break;
			}
		}
		$cta_text = (
			$fixable ?
			__( 'Review & fix', 'wpfactory-catalog-health-scanner-for-woocommerce' ) :
			sprintf(
				/* translators: %s: number of critical issues. */
				__( 'Review the %s', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				number_format_i18n( $critical )
			)
		);
		echo '<a class="wpfchs-cta-danger" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta_text ) . ' &rarr;</a>';
		echo '</div>';

	}

	/**
	 * render_category_grid.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $score_categories From the last scan's score data.
	 */
	function render_category_grid( $score_categories ) {

		$core          = wpfchs()->core;
		$categories    = $core->checks->get_categories();
		$applicability = $core->applicability;
		// The SAME counter the headline total uses. Summing raw per-check
		// counts here made a store-wide finding count once per product it
		// reaches, so Content read 131 where the report listed 39 and the
		// card totals did not reconcile with the headline.
		$open_by_cat   = $core->issues->count_open_scored_by_category();

		$applicable_count = 0;
		$cards            = array();

		foreach ( $categories as $category_id => $label ) {

			// A category is "not applicable" when every one of its checks
			// belongs to a non-applicable group.
			$checks     = $core->checks->get_by_category( $category_id );
			$applicable = false;
			$reason     = '';
			foreach ( $checks as $check ) {
				$group   = $check->get_group();
				$resolve = ( '' === $group ? array( 'applicable' => true, 'reason' => '' ) : $applicability->resolve( $group ) );
				if ( $resolve['applicable'] ) {
					$applicable = true;
					break;
				}
				$reason = $resolve['reason'];
			}

			$open = (int) ( $open_by_cat[ $category_id ] ?? 0 );

			$cards[ $category_id ] = array(
				'label'      => $label,
				'applicable' => $applicable,
				'reason'     => $reason,
				'open'       => $open,
				'score'      => ( $score_categories[ $category_id ] ?? null ),
			);

			if ( $applicable ) {
				$applicable_count++;
			}

		}

		echo '<div class="wpfchs-grid-header">';
		echo '<h2>' . esc_html__( 'Category scores', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</h2>';
		echo '<span class="wpfchs-muted">';
		printf(
			/* translators: %1$d: applicable category count, %2$d: total category count. */
			esc_html__( '%1$d of %2$d categories apply to this store', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
			(int) $applicable_count,
			(int) count( $categories )
		);
		echo '</span>';
		echo '</div>';

		// Per-category open counts by severity (scored checks only): the card
		// badge must never read kinder than the worst open issue, and the
		// foot names that severity so "Healthy · 16 issues" cannot happen.
		$crit_by_cat = $core->issues->count_open_scored_by_category( 'critical' );
		$high_by_cat = $core->issues->count_open_scored_by_category( 'high' );
		$med_by_cat  = $core->issues->count_open_scored_by_category( 'medium' );
		$low_by_cat  = $core->issues->count_open_scored_by_category( 'low' );

		echo '<div class="wpfchs-category-grid">';

		foreach ( $cards as $category_id => $card ) {

			if ( ! $card['applicable'] ) {
				echo '<div class="wpfchs-category-card wpfchs-card-na">';
				echo '<div class="wpfchs-card-head"><span class="wpfchs-card-title">' . esc_html( $card['label'] ) . '</span><span class="wpfchs-card-state">' . esc_html__( 'Not applicable', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</span></div>';
				echo '<div class="wpfchs-card-score">&mdash;</div>';
				echo '<div class="wpfchs-card-bar"><div style="width:0"></div></div>';
				echo '<div class="wpfchs-card-foot">' . esc_html( $card['reason'] ) . '</div>';
				echo '</div>';
				continue;
			}

			$url = add_query_arg(
				array(
					'page' => 'wpfchs',
					'tab'  => $category_id,
				),
				admin_url( 'admin.php' )
			);

			$crit_open  = (int) ( $crit_by_cat[ $category_id ] ?? 0 );
			$high_open  = (int) ( $high_by_cat[ $category_id ] ?? 0 );
			// Card colour = score. Severity gets its own small chip below.
			$band       = ( null !== $card['score'] ? wpfchs()->core->scores->get_category_band( $card['score']['earned'], $card['score']['possible'] ) : null );
			$chip       = wpfchs()->core->scores->get_severity_chip( $crit_open, $high_open );
			$accent     = ( $band ? $band['color'] : '#c3c4c7' );
			$band_class = ( $band ? ' wpfchs-band-' . $band['id'] : '' );

			echo '<a class="wpfchs-category-card' . esc_attr( $band_class ) . '" style="border-left-color:' . esc_attr( $accent ) . '" href="' . esc_url( $url ) . '">';
			echo '<div class="wpfchs-card-head"><span class="wpfchs-card-title">' . esc_html( $card['label'] ) . '</span>';

			if ( null !== $card['score'] ) {
				// Severity chip when something serious is open, otherwise the
				// score band. One chip, never two.
				$chip_label = ( $chip ? $chip['label'] : $band['label'] );
				$chip_color = ( $chip ? $chip['color'] : $band['color'] );
				echo '<span class="wpfchs-chip" style="color:' . esc_attr( $chip_color ) . ';background:' . esc_attr( $chip_color ) . '1a">' . esc_html( $chip_label ) . '</span></div>';
				echo '<div class="wpfchs-card-score">' . esc_html( wc_format_decimal( $card['score']['earned'], 1 ) ) . ' <span>/ ' . esc_html( wc_format_decimal( $card['score']['possible'], 1, true ) ) . '</span></div>';
				$percent = ( $card['score']['possible'] > 0 ? round( ( $card['score']['earned'] / $card['score']['possible'] ) * 100 ) : 100 );
				echo '<div class="wpfchs-card-bar"><div style="width:' . esc_attr( $percent ) . '%;background:' . esc_attr( $band['color'] ) . '"></div></div>';
			} else {
				echo '<span class="wpfchs-card-state">' . esc_html__( 'Not scanned yet', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</span></div>';
				echo '<div class="wpfchs-card-score">&mdash;</div>';
				echo '<div class="wpfchs-card-bar"><div style="width:0"></div></div>';
			}

			echo '<div class="wpfchs-card-foot">';
			printf(
				/* translators: %s: number of open issues. */
				esc_html( _n( '%s issue', '%s issues', $card['open'], 'wpfactory-catalog-health-scanner-for-woocommerce' ) ),
				esc_html( number_format_i18n( $card['open'] ) )
			);
			// Name the worst open severity, so a good score next to a pile of
			// low-weight issues does not read as a contradiction.
			$highest = '';
			if ( $crit_open > 0 ) {
				$highest = __( 'highest: Critical', 'wpfactory-catalog-health-scanner-for-woocommerce' );
			} elseif ( $high_open > 0 ) {
				$highest = __( 'highest: High', 'wpfactory-catalog-health-scanner-for-woocommerce' );
			} elseif ( ! empty( $med_by_cat[ $category_id ] ) ) {
				$highest = __( 'highest: Medium', 'wpfactory-catalog-health-scanner-for-woocommerce' );
			} elseif ( ! empty( $low_by_cat[ $category_id ] ) ) {
				$highest = __( 'highest: Low', 'wpfactory-catalog-health-scanner-for-woocommerce' );
			}
			if ( '' !== $highest && $card['open'] > 0 ) {
				echo ' <span class="wpfchs-muted">&middot; ' . esc_html( $highest ) . '</span>';
			}
			echo '</div>';
			echo '</a>';

		}

		echo '</div>';

	}

	/**
	 * Quick wins: auto-fixable checks with open issues.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_quick_wins() {

		$core        = wpfchs()->core;
		$open_counts = $core->issues->count_open_by_check();

		$wins = array();
		foreach ( $core->checks->get_all() as $check_id => $check ) {
			// Skip checks in a non-applicable group: pushing fixes for issues
			// the user has said do not apply to their store is noise.
			$group = $check->get_group();
			if ( '' !== $group && ! $core->applicability->resolve( $group )['applicable'] ) {
				continue;
			}
			if ( 'auto' === $check->get_fix_type() && ! empty( $open_counts[ $check_id ] ) ) {
				$wins[ $check_id ] = array(
					'check' => $check,
					'count' => $open_counts[ $check_id ],
				);
			}
		}

		echo '<div class="wpfchs-card wpfchs-panel">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Quick wins', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</h2>';
		if ( ! empty( $wins ) && $core->bulk ) {
			echo '<button type="button" class="button button-primary wpfchs-fix-all-quick-wins">' . esc_html__( 'Fix all', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</button>';
		} else {
			echo '<span class="wpfchs-muted">' . esc_html__( 'Auto-fixable', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</span>';
		}
		echo '</div>';

		if ( empty( $wins ) ) {
			echo '<p class="wpfchs-panel-empty">' . esc_html__( 'Nothing auto-fixable right now. Good.', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</p>';
		} else {
			foreach ( array_slice( $wins, 0, 5, true ) as $check_id => $win ) {
				echo '<div class="wpfchs-panel-row">';
				echo '<span class="wpfchs-panel-row-main">';
				echo '<span>' . esc_html( $win['check']->get_label() ) . '</span>';
				echo '<span class="wpfchs-muted">';
				printf(
					/* translators: %s: number of affected products. */
					esc_html( _n( '%s product', '%s products', $win['count'], 'wpfactory-catalog-health-scanner-for-woocommerce' ) ),
					esc_html( number_format_i18n( $win['count'] ) )
				);
				echo '</span>';
				echo '</span>';
				echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'wpfchs', 'tab' => $win['check']->get_category() ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Review', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</a>';
				echo '</div>';
			}
		}

		echo '</div>';

	}

	/**
	 * Top issues: the largest open groups, weighted by severity.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_top_issues() {

		$core        = wpfchs()->core;
		$checks      = $core->checks;
		$categories  = $checks->get_categories();
		$open_counts = $core->issues->count_open_by_check();

		$rows = array();
		foreach ( $open_counts as $check_id => $count ) {
			$check = $checks->get( $check_id );
			if ( ! $check ) {
				continue;
			}
			// Same rule as the quick wins panel: never headline issues from a
			// group the user has marked not applicable.
			$group = $check->get_group();
			if ( '' !== $group && ! wpfchs()->core->applicability->resolve( $group )['applicable'] ) {
				continue;
			}
			$rows[] = array(
				'check'  => $check,
				'count'  => $count,
				// A store-level check is one finding however many products it
				// reaches — its reach must not let it outrank real per-product
				// problems.
				'weight' => ( $check->is_store_level() ? 1 : $count ) * $check->get_weight(),
			);
		}

		usort(
			$rows,
			function ( $a, $b ) {
				return $b['weight'] <=> $a['weight'];
			}
		);

		echo '<div class="wpfchs-card wpfchs-panel">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Top issues', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</h2></div>';

		if ( empty( $rows ) ) {
			echo '<p class="wpfchs-panel-empty">' . esc_html__( 'No open issues. Your catalog is clean.', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</p>';
		} else {
			foreach ( array_slice( $rows, 0, 5 ) as $row ) {
				$check = $row['check'];
				$url   = add_query_arg(
					array(
						'page'  => 'wpfchs',
						'tab'   => $check->get_category(),
						'check' => $check->get_id(),
					),
					admin_url( 'admin.php' )
				);
				echo '<div class="wpfchs-panel-row wpfchs-top-issue-row">';
				echo '<span class="wpfchs-top-issue-label">' . esc_html( $check->get_label() ) . '</span>';
				echo '<span class="wpfchs-muted">' . esc_html( $categories[ $check->get_category() ] ?? $check->get_category() ) . '</span>';
				echo wp_kses_post( wpfchs()->core->admin->severity_badge( $check->get_severity() ) );
				echo '<span class="wpfchs-muted wpfchs-count">';
				printf(
					/* translators: %s: number of affected products. */
					esc_html( _n( '%s product', '%s products', $row['count'], 'wpfactory-catalog-health-scanner-for-woocommerce' ) ),
					esc_html( number_format_i18n( $row['count'] ) )
				);
				echo '</span>';
				echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</a>';
				echo '</div>';
			}
		}

		echo '</div>';

	}

}

endif;

return new WPFCHS_Admin_Dashboard();
