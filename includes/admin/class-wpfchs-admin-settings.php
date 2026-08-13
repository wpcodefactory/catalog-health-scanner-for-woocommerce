<?php
/**
 * Catalog Health Scanner for WooCommerce - Admin Settings Page Class
 *
 * Applicability (with auto-detect reasoning shown alongside), individual
 * check toggles, thresholds, scheduling and digest, scan scope, and the
 * ignore list.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Admin_Settings' ) ) :

class WPFCHS_Admin_Settings {

	/**
	 * Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'admin_init', array( $this, 'maybe_reset' ) );
	}

	/**
	 * Resets all plugin settings to defaults and restarts the setup wizard.
	 * Scan history, issues, and the fix log are kept — this is a settings
	 * reset, not a data wipe.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function maybe_reset() {

		if ( ! isset( $_POST['wpfchs_reset'] ) ) {
			return;
		}

		check_admin_referer( 'wpfchs-reset' );

		if ( ! current_user_can( wpfchs()->core->get_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'catalog-health-scanner-for-woocommerce' ) );
		}

		$option_keys = apply_filters(
			'wpfchs_reset_option_keys',
			array(
				'wpfchs_applicability',
				'wpfchs_disabled_checks',
				'wpfchs_thresholds',
				'wpfchs_schedule',
				'wpfchs_scan_scope',
				'wpfchs_custom_profiles',
				'wpfchs_default_profile',
				'wpfchs_last_profile',
				'wpfchs_dismissed_recs',
				'wpfchs_branding',
				'wpfchs_setup_complete',
			)
		);
		foreach ( $option_keys as $key ) {
			delete_option( $key );
		}

		wpfchs()->core->applicability->flush_cache();

		wp_safe_redirect( admin_url( 'admin.php?page=wpfchs-setup' ) );
		exit;

	}

	/**
	 * maybe_save.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function maybe_save() {

		if ( ! isset( $_POST['wpfchs_settings_action'] ) ) {
			return;
		}

		check_admin_referer( 'wpfchs-settings' );

		if ( ! current_user_can( wpfchs()->core->get_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'catalog-health-scanner-for-woocommerce' ) );
		}

		// Applicability.
		$old_states = (array) get_option( 'wpfchs_applicability', array() );
		$groups = wpfchs()->core->applicability->get_groups();
		$states = isset( $_POST['wpfchs_applicability'] ) ? map_deep( wp_unslash( (array) $_POST['wpfchs_applicability'] ), 'sanitize_key' ) : array();
		$clean  = array();
		foreach ( $groups as $group ) {
			$state = ( $states[ $group ] ?? 'auto' );
			$clean[ $group ] = ( in_array( $state, array( 'auto', 'yes', 'no', 'report_only' ), true ) ? $state : 'auto' );
		}
		update_option( 'wpfchs_applicability', $clean, false );

		// Disabled checks: the form posts *enabled* boxes; everything known
		// but unposted is disabled.
		$known   = array_keys( wpfchs()->core->checks->get_all() );
		$enabled = isset( $_POST['wpfchs_enabled_checks'] ) ? map_deep( wp_unslash( (array) $_POST['wpfchs_enabled_checks'] ), 'sanitize_key' ) : array();
		update_option( 'wpfchs_disabled_checks', array_values( array_diff( $known, $enabled ) ), false );

		// Thresholds (grouped option row).
		$thresholds = isset( $_POST['wpfchs_thresholds'] ) ? map_deep( wp_unslash( (array) $_POST['wpfchs_thresholds'] ), 'sanitize_text_field' ) : array();
		$numeric    = array();
		foreach ( array( 'min_margin_percent', 'min_image_width', 'min_description_chars', 'grace_period_days', 'undo_window_days', 'oos_age_days', 'max_weight', 'price_deviation_factor', 'image_reuse_count', 'max_feed_desc_chars' ) as $key ) {
			if ( isset( $thresholds[ $key ] ) && '' !== $thresholds[ $key ] ) {
				$numeric[ $key ] = ( in_array( $key, array( 'min_margin_percent', 'price_deviation_factor', 'max_weight' ), true ) ? (float) $thresholds[ $key ] : absint( $thresholds[ $key ] ) );
			}
		}
		update_option( 'wpfchs_thresholds', $numeric, false );

		// Schedule + digest. Pro-owned: the free build renders these controls
		// disabled, so nothing arrives from an honest form — and if something
		// does arrive anyway, it is not persisted. (WPFCHS_Schedule also
		// forces these off on read, so this guard is belt on top of braces.)
		if ( wpfchs()->is_pro() ) {
			$schedule = isset( $_POST['wpfchs_schedule'] ) ? map_deep( wp_unslash( (array) $_POST['wpfchs_schedule'] ), 'sanitize_text_field' ) : array();
			update_option(
				'wpfchs_schedule',
				array(
					'enabled'           => ( 'yes' === ( $schedule['enabled'] ?? 'no' ) ? 'yes' : 'no' ),
					'frequency'         => ( in_array( ( $schedule['frequency'] ?? '' ), array( 'daily', 'weekly', 'monthly' ), true ) ? $schedule['frequency'] : 'weekly' ),
					'profile'           => sanitize_key( $schedule['profile'] ?? 'revenue_blockers' ),
					'digest_enabled'    => ( 'yes' === ( $schedule['digest_enabled'] ?? 'no' ) ? 'yes' : 'no' ),
					'digest_recipients' => implode( ', ', array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) ( $schedule['digest_recipients'] ?? '' ) ) ) ) ),
					'alerts_enabled'    => ( 'yes' === ( $schedule['alerts_enabled'] ?? 'no' ) ? 'yes' : 'no' ),
					'alert_recipients'  => implode( ', ', array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) ( $schedule['alert_recipients'] ?? '' ) ) ) ) ),
					'auto_fix_enabled'  => ( 'yes' === ( $schedule['auto_fix_enabled'] ?? 'no' ) ? 'yes' : 'no' ),
				),
				false
			);

			// White-label report branding. Pro-owned, same as the schedule above.
			$branding = isset( $_POST['wpfchs_branding'] ) ? map_deep( wp_unslash( (array) $_POST['wpfchs_branding'] ), 'sanitize_text_field' ) : array();
			update_option(
				'wpfchs_branding',
				array(
					'agency_name' => (string) ( $branding['agency_name'] ?? '' ),
					'logo_id'     => absint( $branding['logo_id'] ?? 0 ),
					'footer'      => (string) ( $branding['footer'] ?? '' ),
				),
				false
			);
		}

		// Scan scope.
		$exclude_cats = isset( $_POST['wpfchs_exclude_cats'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['wpfchs_exclude_cats'] ) ) : array();
		update_option( 'wpfchs_scan_scope', array( 'exclude_cats' => array_filter( $exclude_cats ) ), false );

		wpfchs()->core->applicability->flush_cache();

		// Applicability changed: recalculate historic scores so the trend
		// line stays comparable (each affected scan gets a marker).
		if ( $clean !== array_merge( array_fill_keys( $groups, 'auto' ), array_intersect_key( $old_states, array_flip( $groups ) ) ) ) {
			wpfchs()->core->scores->recalculate_history();
		}

		wp_safe_redirect( add_query_arg( 'wpfchs_saved', '1', admin_url( 'admin.php?page=wpfchs-settings' ) ) );
		exit;

	}

	/**
	 * render_page.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_page() {

		wpfchs()->core->admin->render_shell_open( 'settings' );

		if ( filter_input( INPUT_GET, 'wpfchs_saved', FILTER_SANITIZE_SPECIAL_CHARS ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'catalog-health-scanner-for-woocommerce' ) . '</p></div>';
		}

		echo '<form method="post">';
		wp_nonce_field( 'wpfchs-settings' );
		echo '<input type="hidden" name="wpfchs_settings_action" value="save" />';

		$this->render_applicability_section();
		$this->render_checks_section();
		$this->render_thresholds_section();
		$this->render_schedule_section();
		$this->render_scope_section();

		echo '<p><button type="submit" class="button button-primary">' . esc_html__( 'Save settings', 'catalog-health-scanner-for-woocommerce' ) . '</button></p>';
		echo '</form>';

		$this->render_ignored_section();
		$this->render_reset_section();

		wpfchs()->core->admin->render_shell_close();

	}

	/**
	 * Reset & re-run setup wizard.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_reset_section() {

		echo '<div class="wpfchs-card wpfchs-panel">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Reset & setup', 'catalog-health-scanner-for-woocommerce' ) . '</h2></div>';
		echo '<div class="wpfchs-panel-row"><span class="wpfchs-panel-row-main">';
		echo '<span>' . esc_html__( 'Restore all settings to their defaults and run the setup wizard again.', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
		echo '<span class="wpfchs-muted">' . esc_html__( 'Your scan history, issues, and fix log are kept. Only settings and profiles are reset.', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
		echo '</span>';
		echo '<form method="post" style="margin-left:auto">';
		wp_nonce_field( 'wpfchs-reset' );
		echo '<input type="hidden" name="wpfchs_reset" value="1" />';
		echo '<button type="submit" class="button wpfchs-reset-btn">' . esc_html__( 'Reset & re-run setup', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';

	}

	/**
	 * render_applicability_section.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_applicability_section() {

		$core          = wpfchs()->core;
		$applicability = $core->applicability;

		$group_labels = array();
		foreach ( $applicability->get_groups() as $group ) {
			$group_labels[ $group ] = $applicability->get_group_label( $group );
		}

		echo '<div class="wpfchs-card wpfchs-panel" id="wpfchs-applicability">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Applicability', 'catalog-health-scanner-for-woocommerce' ) . '</h2>';
		echo '<span class="wpfchs-muted">' . esc_html__( 'Non-applicable groups are skipped entirely and excluded from the health score.', 'catalog-health-scanner-for-woocommerce' ) . '</span></div>';

		echo '<table class="widefat striped wpfchs-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Check group', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Setting', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Auto-detect currently says', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $applicability->get_groups() as $group ) {
			$state = $applicability->get_state( $group );
			$auto  = $applicability->auto_detect( $group );
			echo '<tr>';
			echo '<td><strong>' . esc_html( $group_labels[ $group ] ?? $group ) . '</strong></td>';
			echo '<td><select name="wpfchs_applicability[' . esc_attr( $group ) . ']">';
			foreach ( array(
				'auto'        => __( 'Auto-detect (recommended)', 'catalog-health-scanner-for-woocommerce' ),
				'yes'         => __( 'Applicable', 'catalog-health-scanner-for-woocommerce' ),
				'report_only' => __( 'Report, but do not score', 'catalog-health-scanner-for-woocommerce' ),
				'no'          => __( 'Not applicable', 'catalog-health-scanner-for-woocommerce' ),
			) as $value => $option_label ) {
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $state, false ) . '>' . esc_html( $option_label ) . '</option>';
			}
			echo '</select></td>';
			echo '<td class="wpfchs-muted">';
			echo esc_html(
				$auto['applicable'] ?
				/* translators: %s: auto-detect reasoning. */
				sprintf( __( 'Applicable — %s', 'catalog-health-scanner-for-woocommerce' ), $auto['reason'] ) :
				/* translators: %s: auto-detect reasoning. */
				sprintf( __( 'Not applicable — %s', 'catalog-health-scanner-for-woocommerce' ), $auto['reason'] )
			);
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';

	}

	/**
	 * render_checks_section.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_checks_section() {

		$core       = wpfchs()->core;
		$categories = $core->checks->get_categories();
		$disabled   = $core->checks->get_disabled();

		echo '<div class="wpfchs-card wpfchs-panel">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Individual checks', 'catalog-health-scanner-for-woocommerce' ) . '</h2>';
		echo '<span class="wpfchs-muted">' . esc_html__( 'Untick a check to disable it everywhere. To silence a single product instead, use Ignore on the issue.', 'catalog-health-scanner-for-woocommerce' ) . '</span></div>';

		foreach ( $categories as $category_id => $label ) {
			$checks = $core->checks->get_by_category( $category_id );
			if ( empty( $checks ) ) {
				continue;
			}
			echo '<fieldset class="wpfchs-profile-category">';
			echo '<legend>' . esc_html( $label ) . '</legend>';
			foreach ( $checks as $check_id => $check ) {
				echo '<label class="wpfchs-profile-check">';
				echo '<input type="checkbox" name="wpfchs_enabled_checks[]" value="' . esc_attr( $check_id ) . '"' . checked( ! in_array( $check_id, $disabled, true ), true, false ) . ' /> ';
				echo esc_html( $check->get_label() );
				echo ' ' . wp_kses_post( $core->admin->severity_badge( $check->get_severity() ) );
				echo '</label>';
			}
			echo '</fieldset>';
		}

		echo '</div>';

	}

	/**
	 * render_thresholds_section.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_thresholds_section() {

		$core = wpfchs()->core;

		$fields = array(
			'min_margin_percent'    => array( __( 'Minimum margin (%)', 'catalog-health-scanner-for-woocommerce' ), __( '0 disables the margin check.', 'catalog-health-scanner-for-woocommerce' ) ),
			'min_image_width'       => array( __( 'Minimum image resolution (px)', 'catalog-health-scanner-for-woocommerce' ), __( 'Featured images below this width or height are flagged.', 'catalog-health-scanner-for-woocommerce' ) ),
			'min_description_chars' => array( __( 'Minimum description length (characters)', 'catalog-health-scanner-for-woocommerce' ), '' ),
			'oos_age_days'          => array( __( 'Long-term out of stock after (days)', 'catalog-health-scanner-for-woocommerce' ), '' ),
			'grace_period_days'     => array( __( 'Grace period for new products (days)', 'catalog-health-scanner-for-woocommerce' ), __( 'Newly published products are not counted until this passes.', 'catalog-health-scanner-for-woocommerce' ) ),
			'undo_window_days'      => array( __( 'Fix undo window (days)', 'catalog-health-scanner-for-woocommerce' ), '' ),
			'max_weight'            => array( __( 'Maximum plausible weight', 'catalog-health-scanner-for-woocommerce' ), __( 'In your store weight unit. 0 disables the upper bound.', 'catalog-health-scanner-for-woocommerce' ) ),
			'price_deviation_factor' => array( __( 'Price deviation factor', 'catalog-health-scanner-for-woocommerce' ), __( 'Flag prices this many times above or below the category median.', 'catalog-health-scanner-for-woocommerce' ) ),
			'image_reuse_count'     => array( __( 'Image reuse threshold (products)', 'catalog-health-scanner-for-woocommerce' ), '' ),
			'max_feed_desc_chars'   => array( __( 'Feed description limit (characters)', 'catalog-health-scanner-for-woocommerce' ), '' ),
		);

		echo '<div class="wpfchs-card wpfchs-panel">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Thresholds', 'catalog-health-scanner-for-woocommerce' ) . '</h2></div>';
		echo '<table class="form-table wpfchs-form-table">';

		foreach ( $fields as $key => $field ) {
			echo '<tr>';
			echo '<th scope="row"><label for="wpfchs-threshold-' . esc_attr( $key ) . '">' . esc_html( $field[0] ) . '</label></th>';
			echo '<td><input type="number" step="any" min="0" id="wpfchs-threshold-' . esc_attr( $key ) . '" name="wpfchs_thresholds[' . esc_attr( $key ) . ']" value="' . esc_attr( $core->get_threshold( $key ) ) . '" class="small-text" />';
			if ( '' !== $field[1] ) {
				echo '<p class="description">' . esc_html( $field[1] ) . '</p>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</table>';
		echo '</div>';

	}

	/**
	 * render_schedule_section.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_schedule_section() {

		$core     = wpfchs()->core;
		$settings = $core->schedule->get_settings();
		$profiles = $core->profiles->get_all();

		// Locked, not hidden: the free build renders the whole section with
		// every control disabled, so the feature can make its own case. The
		// promoting notice at the top of this screen lights up when a
		// disabled control is clicked.
		$locked = $core->upgrade->is_locked();

		echo '<div class="wpfchs-card wpfchs-panel' . ( $locked ? ' wpfchs-panel-locked' : '' ) . '" id="wpfchs-schedule">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Scheduled scans & email digest', 'catalog-health-scanner-for-woocommerce' );
		if ( $locked ) {
			echo ' ' . $core->upgrade->badge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fully escaped in WPFCHS_Upgrade::badge().
		}
		echo '</h2>';
		echo '<span class="wpfchs-muted">' . esc_html__( 'The digest is only sent when there is something new. Silence means a clean catalog.', 'catalog-health-scanner-for-woocommerce' ) . '</span></div>';
		if ( $locked ) {
			echo $core->upgrade->lock_note( __( 'Scans that run themselves, an email digest of new issues, and instant critical alerts.', 'catalog-health-scanner-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fully escaped in WPFCHS_Upgrade::lock_note().
		}
		echo '<table class="form-table wpfchs-form-table">';

		echo '<tr><th scope="row">' . esc_html__( 'Scheduled scans', 'catalog-health-scanner-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="wpfchs_schedule[enabled]" value="yes"' . checked( 'yes', $settings['enabled'], false ) . disabled( $locked, true, false ) . ' /> ' . esc_html__( 'Run scans automatically', 'catalog-health-scanner-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="wpfchs-schedule-frequency">' . esc_html__( 'Frequency', 'catalog-health-scanner-for-woocommerce' ) . '</label></th><td>';
		echo '<select id="wpfchs-schedule-frequency" name="wpfchs_schedule[frequency]"' . disabled( $locked, true, false ) . '>';
		foreach ( array(
			'daily'   => __( 'Daily', 'catalog-health-scanner-for-woocommerce' ),
			'weekly'  => __( 'Weekly', 'catalog-health-scanner-for-woocommerce' ),
			'monthly' => __( 'Monthly', 'catalog-health-scanner-for-woocommerce' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $settings['frequency'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="wpfchs-schedule-profile">' . esc_html__( 'Profile', 'catalog-health-scanner-for-woocommerce' ) . '</label></th><td>';
		echo '<select id="wpfchs-schedule-profile" name="wpfchs_schedule[profile]"' . disabled( $locked, true, false ) . '>';
		foreach ( $profiles as $profile_id => $profile ) {
			echo '<option value="' . esc_attr( $profile_id ) . '"' . selected( $profile_id, $settings['profile'], false ) . '>' . esc_html( $profile['label'] ) . '</option>';
		}
		echo '</select>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Email digest', 'catalog-health-scanner-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="wpfchs_schedule[digest_enabled]" value="yes"' . checked( 'yes', $settings['digest_enabled'], false ) . disabled( $locked, true, false ) . ' /> ' . esc_html__( 'Email a digest after scheduled scans when new issues are found', 'catalog-health-scanner-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="wpfchs-digest-recipients">' . esc_html__( 'Digest recipients', 'catalog-health-scanner-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" id="wpfchs-digest-recipients" name="wpfchs_schedule[digest_recipients]" value="' . esc_attr( $settings['digest_recipients'] ) . '" class="regular-text"' . disabled( $locked, true, false ) . ' />';
		echo '<p class="description">' . esc_html__( 'Comma-separated email addresses.', 'catalog-health-scanner-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Immediate critical alerts', 'catalog-health-scanner-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="wpfchs_schedule[alerts_enabled]" value="yes"' . checked( 'yes', $settings['alerts_enabled'], false ) . disabled( $locked, true, false ) . ' /> ' . esc_html__( 'Email as soon as a scan finds new critical issues', 'catalog-health-scanner-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="wpfchs-alert-recipients">' . esc_html__( 'Alert recipients', 'catalog-health-scanner-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" id="wpfchs-alert-recipients" name="wpfchs_schedule[alert_recipients]" value="' . esc_attr( $settings['alert_recipients'] ) . '" class="regular-text"' . disabled( $locked, true, false ) . ' />';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Auto-fix after scheduled scans', 'catalog-health-scanner-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="wpfchs_schedule[auto_fix_enabled]" value="yes"' . checked( 'yes', $settings['auto_fix_enabled'], false ) . disabled( $locked, true, false ) . ' /> ' . esc_html__( 'Automatically apply the unambiguous "quick win" fixes after each scheduled scan', 'catalog-health-scanner-for-woocommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Only fully reversible auto-fixes run (expired sales, dead references, stock-status sync, and similar). Every change is logged and can be undone from the History tab.', 'catalog-health-scanner-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '</table>';
		echo '</div>';

		$this->render_branding_section();

	}

	/**
	 * White-label report branding (spec section 12).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_branding_section() {

		$branding = wp_parse_args(
			(array) get_option( 'wpfchs_branding', array() ),
			array(
				'agency_name' => '',
				'logo_id'     => 0,
				'footer'      => '',
			)
		);

		// White label travels with the PDF report: both are Pro. Locked, not
		// hidden, same as the schedule section above.
		$locked = wpfchs()->core->upgrade->is_locked();

		echo '<div class="wpfchs-card wpfchs-panel' . ( $locked ? ' wpfchs-panel-locked' : '' ) . '" id="wpfchs-branding">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'PDF report branding (white label)', 'catalog-health-scanner-for-woocommerce' );
		if ( $locked ) {
			echo ' ' . wpfchs()->core->upgrade->badge(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fully escaped in WPFCHS_Upgrade::badge().
		}
		echo '</h2>';
		echo '<span class="wpfchs-muted">' . esc_html__( 'Shown on the PDF audit report cover. Leave empty to use the store name.', 'catalog-health-scanner-for-woocommerce' ) . '</span></div>';
		if ( $locked ) {
			echo wpfchs()->core->upgrade->lock_note( __( 'A client-ready PDF audit report carrying your agency name and logo.', 'catalog-health-scanner-for-woocommerce' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fully escaped in WPFCHS_Upgrade::lock_note().
		}
		echo '<table class="form-table wpfchs-form-table">';

		echo '<tr><th scope="row"><label for="wpfchs-agency-name">' . esc_html__( 'Agency / brand name', 'catalog-health-scanner-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" id="wpfchs-agency-name" name="wpfchs_branding[agency_name]" value="' . esc_attr( $branding['agency_name'] ) . '" class="regular-text"' . disabled( $locked, true, false ) . ' />';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Logo', 'catalog-health-scanner-for-woocommerce' ) . '</th><td>';
		echo '<div class="wpfchs-logo-field">';

		$logo_url = ( $branding['logo_id'] ? wp_get_attachment_image_url( $branding['logo_id'], 'medium' ) : '' );
		echo '<div class="wpfchs-logo-preview"' . ( $logo_url ? '' : ' style="display:none"' ) . '>';
		echo '<img src="' . esc_url( (string) $logo_url ) . '" alt="" />';
		echo '</div>';

		echo '<input type="hidden" id="wpfchs-logo-id" name="wpfchs_branding[logo_id]" value="' . esc_attr( $branding['logo_id'] ) . '" />';
		echo '<button type="button" class="button wpfchs-logo-select"' . disabled( $locked, true, false ) . '>' . esc_html__( 'Choose logo', 'catalog-health-scanner-for-woocommerce' ) . '</button> ';
		echo '<button type="button" class="button-link wpfchs-logo-remove"' . disabled( $locked, true, false ) . ( $branding['logo_id'] ? '' : ' style="display:none"' ) . '>' . esc_html__( 'Remove', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
		if ( function_exists( 'imagecreatefromstring' ) ) {
			echo '<p class="description">' . esc_html__( 'Shown on the PDF report cover. JPEG, PNG, WebP, and GIF all work — transparent areas are flattened onto white.', 'catalog-health-scanner-for-woocommerce' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Shown on the PDF report cover. The GD image library is not available on this server, so only JPEG logos can be rendered.', 'catalog-health-scanner-for-woocommerce' ) . '</p>';
		}

		echo '</div>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="wpfchs-report-footer">' . esc_html__( 'Report footer line', 'catalog-health-scanner-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" id="wpfchs-report-footer" name="wpfchs_branding[footer]" value="' . esc_attr( $branding['footer'] ) . '" class="large-text"' . disabled( $locked, true, false ) . ' />';
		echo '</td></tr>';

		echo '</table>';
		echo '</div>';

	}

	/**
	 * render_scope_section.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_scope_section() {

		$excluded = wpfchs()->core->scanner->get_excluded_category_ids();

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
			)
		);

		echo '<div class="wpfchs-card wpfchs-panel" id="wpfchs-scope">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Scan scope', 'catalog-health-scanner-for-woocommerce' ) . '</h2>';
		echo '<span class="wpfchs-muted">' . esc_html__( 'Products in excluded categories are skipped. Scans that skip anything never auto-resolve issues, so a skipped product is never reported as fixed.', 'catalog-health-scanner-for-woocommerce' ) . '</span></div>';

		echo '<p class="wpfchs-panel-row"><label for="wpfchs-exclude-cats">' . esc_html__( 'Exclude product categories', 'catalog-health-scanner-for-woocommerce' ) . '</label> ';
		echo '<select id="wpfchs-exclude-cats" name="wpfchs_exclude_cats[]" multiple size="6" style="min-width:280px">';
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				echo '<option value="' . esc_attr( $term->term_id ) . '"' . selected( in_array( (int) $term->term_id, $excluded, true ), true, false ) . '>' . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select></p>';

		echo '</div>';

	}

	/**
	 * Ignore list management: review and restore anything dismissed.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_ignored_section() {

		$core     = wpfchs()->core;
		$per_page = 200;
		$total    = $core->issues->count( array( 'status' => 'ignored' ) );
		$ignored  = $core->issues->query(
			array(
				'status' => 'ignored',
				'limit'  => $per_page,
			)
		);

		echo '<div class="wpfchs-card wpfchs-panel wpfchs-ignored-panel" id="wpfchs-ignored">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Ignored issues', 'catalog-health-scanner-for-woocommerce' ) . '</h2>';
		echo '<span class="wpfchs-muted">' . esc_html__( 'Ignored issues count as passing and never resurface until restored here.', 'catalog-health-scanner-for-woocommerce' ) . '</span></div>';

		if ( empty( $ignored ) ) {
			echo '<p class="wpfchs-panel-empty">' . esc_html__( 'Nothing is ignored.', 'catalog-health-scanner-for-woocommerce' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<div class="wpfchs-selection-bar">';
		echo '<label class="wpfchs-selection-toggle"><input type="checkbox" class="wpfchs-ignored-select-all" /> ' . esc_html__( 'Select all on this page', 'catalog-health-scanner-for-woocommerce' ) . '</label>';
		echo '<span class="wpfchs-selection-count" hidden><strong class="wpfchs-selected-number">0</strong> ' . esc_html__( 'selected', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
		echo '<span class="wpfchs-selection-actions">';
		echo '<button type="button" class="button button-primary wpfchs-restore-selected wpfchs-action-selected" hidden>' . esc_html__( 'Restore selected', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
		echo '<button type="button" class="button-link wpfchs-restore-all" data-total="' . esc_attr( $total ) . '">';
		printf(
			/* translators: %s: number of ignored issues. */
			esc_html( _n( 'Restore all %s issue', 'Restore all %s issues', $total, 'catalog-health-scanner-for-woocommerce' ) ),
			esc_html( number_format_i18n( $total ) )
		);
		echo '</button>';
		echo '</span>';
		echo '</div>';

		echo '<table class="widefat striped wpfchs-table">';
		echo '<thead><tr>';
		echo '<th class="wpfchs-col-cb"></th>';
		echo '<th>' . esc_html__( 'Product', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Check', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Ignored by', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Ignored on', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $ignored as $issue ) {
			$check = $core->checks->get( $issue->check_id );
			$user  = ( $issue->ignored_by ? get_userdata( $issue->ignored_by ) : null );
			echo '<tr>';
			echo '<td><input type="checkbox" class="wpfchs-ignored-row" data-issue="' . esc_attr( $issue->id ) . '" /></td>';
			echo '<td><a href="' . esc_url( (string) get_edit_post_link( $issue->product_id ) ) . '">' . esc_html( get_the_title( $issue->product_id ) ) . '</a></td>';
			echo '<td>' . esc_html( $check ? $check->get_label() : $issue->check_id ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : '—' ) . '</td>';
			echo '<td>' . esc_html( $issue->ignored_at ? get_date_from_gmt( $issue->ignored_at, get_option( 'date_format' ) ) : '—' ) . '</td>';
			echo '<td><button type="button" class="button-link wpfchs-restore-issue" data-issue="' . esc_attr( $issue->id ) . '">' . esc_html__( 'Restore', 'catalog-health-scanner-for-woocommerce' ) . '</button></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $total > count( $ignored ) ) {
			echo '<p class="wpfchs-muted">';
			printf(
				/* translators: %1$s: rows shown, %2$s: total ignored issues. */
				esc_html__( 'Showing the %1$s most recent of %2$s. "Restore all" covers every ignored issue, not just this page.', 'catalog-health-scanner-for-woocommerce' ),
				esc_html( number_format_i18n( count( $ignored ) ) ),
				esc_html( number_format_i18n( $total ) )
			);
			echo '</p>';
		}

		echo '</div>';

	}

}

endif;

return new WPFCHS_Admin_Settings();
