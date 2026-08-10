<?php
/**
 * Catalog Health Scanner for WooCommerce - Admin Profiles Page Class
 *
 * Built-in and custom scan profiles; custom profile editor with checks
 * grouped by category; default profile selection.
 *
 * Form saves use the custom settings form pattern: `check_admin_referer()`
 * plus capability before touching any option.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Admin_Profiles' ) ) :

class WPFCHS_Admin_Profiles {

	/**
	 * Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
	}

	/**
	 * maybe_save.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function maybe_save() {

		if ( ! isset( $_POST['wpfchs_profiles_action'] ) ) {
			return;
		}

		check_admin_referer( 'wpfchs-profiles' );

		if ( ! current_user_can( wpfchs()->core->get_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'catalog-health-scanner-for-woocommerce' ) );
		}

		$action   = sanitize_key( wp_unslash( $_POST['wpfchs_profiles_action'] ) );
		$profiles = wpfchs()->core->profiles;

		if ( 'save_default' === $action ) {
			$default = isset( $_POST['wpfchs_default_profile'] ) ? sanitize_key( wp_unslash( $_POST['wpfchs_default_profile'] ) ) : 'revenue_blockers';
			update_option( 'wpfchs_default_profile', $default, false );
		} elseif ( 'save_custom' === $action ) {
			$id     = isset( $_POST['wpfchs_profile_id'] ) ? sanitize_key( wp_unslash( $_POST['wpfchs_profile_id'] ) ) : '';
			$label  = isset( $_POST['wpfchs_profile_label'] ) ? sanitize_text_field( wp_unslash( $_POST['wpfchs_profile_label'] ) ) : '';
			$checks = isset( $_POST['wpfchs_profile_checks'] ) ? map_deep( wp_unslash( (array) $_POST['wpfchs_profile_checks'] ), 'sanitize_key' ) : array();
			if ( '' !== $label && ! empty( $checks ) ) {
				$profiles->save_custom( $id, $label, $checks );
			}
		} elseif ( 'delete_custom' === $action ) {
			$id = isset( $_POST['wpfchs_profile_id'] ) ? sanitize_key( wp_unslash( $_POST['wpfchs_profile_id'] ) ) : '';
			if ( 0 === strpos( $id, 'custom_' ) ) {
				$profiles->delete_custom( $id );
			}
		} elseif ( 'duplicate' === $action ) {
			$source_id = isset( $_POST['wpfchs_profile_id'] ) ? sanitize_key( wp_unslash( $_POST['wpfchs_profile_id'] ) ) : '';
			$source    = $profiles->get( $source_id );
			if ( null !== $source ) {
				$checks = (
					null === $source['checks'] ?
					array_keys( wpfchs()->core->checks->get_all() ) :
					$source['checks']
				);
				$profiles->save_custom(
					'custom_' . sanitize_key( $source_id . '_copy_' . time() ),
					sprintf(
						/* translators: %s: source profile name. */
						__( 'Copy of %s', 'catalog-health-scanner-for-woocommerce' ),
						$source['label']
					),
					$checks
				);
			}
		}

		wp_safe_redirect( add_query_arg( 'wpfchs_saved', '1', admin_url( 'admin.php?page=wpfchs-profiles' ) ) );
		exit;

	}

	/**
	 * render_page.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_page() {

		$core     = wpfchs()->core;
		$profiles = $core->profiles->get_all();
		$default  = $core->profiles->get_default();

		$core->admin->render_shell_open( 'profiles' );

		if ( filter_input( INPUT_GET, 'wpfchs_saved', FILTER_SANITIZE_SPECIAL_CHARS ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Profiles saved.', 'catalog-health-scanner-for-woocommerce' ) . '</p></div>';
		}

		// Profile list + default selection.
		echo '<form method="post">';
		wp_nonce_field( 'wpfchs-profiles' );
		echo '<input type="hidden" name="wpfchs_profiles_action" value="save_default" />';

		echo '<div class="wpfchs-card wpfchs-panel">';
		echo '<div class="wpfchs-panel-head"><h2>' . esc_html__( 'Profiles', 'catalog-health-scanner-for-woocommerce' ) . '</h2></div>';

		echo '<table class="widefat striped wpfchs-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Default', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Profile', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Description', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Checks', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		$total_checks = count( $core->checks->get_all() );

		foreach ( $profiles as $profile_id => $profile ) {
			$check_count = ( null === $profile['checks'] ? $total_checks : count( $profile['checks'] ) );
			echo '<tr>';
			echo '<td><input type="radio" name="wpfchs_default_profile" value="' . esc_attr( $profile_id ) . '"' . checked( $profile_id, $default, false ) . ' /></td>';
			echo '<td><strong>' . esc_html( $profile['label'] ) . '</strong>' . ( $profile['custom'] ? ' <span class="wpfchs-muted">' . esc_html__( '(custom)', 'catalog-health-scanner-for-woocommerce' ) . '</span>' : '' ) . '</td>';
			echo '<td>' . esc_html( $profile['description'] ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $check_count ) ) . '</td>';
			echo '<td class="wpfchs-profile-actions" data-profile="' . esc_attr( $profile_id ) . '">';
			if ( $profile['custom'] ) {
				echo '<a href="' . esc_url( admin_url( 'admin.php?page=wpfchs-profiles&edit=' . rawurlencode( $profile_id ) . '#wpfchs-profile-editor' ) ) . '">' . esc_html__( 'Edit', 'catalog-health-scanner-for-woocommerce' ) . '</a> | ';
			}
			echo '<button type="button" class="button-link wpfchs-profile-duplicate">' . esc_html__( 'Duplicate', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
			if ( $profile['custom'] ) {
				echo ' | <button type="button" class="button-link wpfchs-profile-delete">' . esc_html__( 'Delete', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="wpfchs-panel-foot"><button type="submit" class="button button-primary">' . esc_html__( 'Save default profile', 'catalog-health-scanner-for-woocommerce' ) . '</button></p>';
		echo '</div>';
		echo '</form>';

		// Hidden form the Duplicate/Delete row buttons submit through.
		echo '<form method="post" id="wpfchs-profile-row-action" style="display:none">';
		wp_nonce_field( 'wpfchs-profiles' );
		echo '<input type="hidden" name="wpfchs_profiles_action" value="" />';
		echo '<input type="hidden" name="wpfchs_profile_id" value="" />';
		echo '</form>';

		$this->render_custom_editor();

		$core->admin->render_shell_close();

	}

	/**
	 * Custom profile editor: checks grouped by category, applicability
	 * exclusions shown in place.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_custom_editor() {

		$core          = wpfchs()->core;
		$categories    = $core->checks->get_categories();
		$applicability = $core->applicability;

		// Editing an existing custom profile?
		$edit_id = filter_input( INPUT_GET, 'edit', FILTER_SANITIZE_SPECIAL_CHARS );
		$edit_id = ( is_string( $edit_id ) ? sanitize_key( $edit_id ) : '' );
		$editing = null;
		if ( 0 === strpos( $edit_id, 'custom_' ) ) {
			$editing = $core->profiles->get( $edit_id );
		}
		$checked_ids = ( $editing ? (array) $editing['checks'] : array() );

		echo '<form method="post" id="wpfchs-profile-editor">';
		wp_nonce_field( 'wpfchs-profiles' );
		echo '<input type="hidden" name="wpfchs_profiles_action" value="save_custom" />';
		echo '<input type="hidden" name="wpfchs_profile_id" value="' . esc_attr( $editing ? $edit_id : '' ) . '" />';

		echo '<div class="wpfchs-card wpfchs-panel">';
		echo '<div class="wpfchs-panel-head"><h2>';
		echo esc_html(
			$editing ?
			/* translators: %s: profile name. */
			sprintf( __( 'Edit profile: %s', 'catalog-health-scanner-for-woocommerce' ), $editing['label'] ) :
			__( 'New custom profile', 'catalog-health-scanner-for-woocommerce' )
		);
		echo '</h2>';
		if ( $editing ) {
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=wpfchs-profiles' ) ) . '">' . esc_html__( 'Cancel editing', 'catalog-health-scanner-for-woocommerce' ) . '</a>';
		}
		echo '</div>';

		echo '<p class="wpfchs-panel-row">';
		echo '<label for="wpfchs-profile-label">' . esc_html__( 'Profile name', 'catalog-health-scanner-for-woocommerce' ) . '</label> ';
		echo '<input type="text" id="wpfchs-profile-label" name="wpfchs_profile_label" class="regular-text" value="' . esc_attr( $editing ? $editing['label'] : '' ) . '" required />';
		echo '</p>';

		foreach ( $categories as $category_id => $label ) {

			$checks = $core->checks->get_by_category( $category_id );
			if ( empty( $checks ) ) {
				continue;
			}

			echo '<fieldset class="wpfchs-profile-category">';
			echo '<legend>' . esc_html( $label ) . '</legend>';

			foreach ( $checks as $check_id => $check ) {
				$group      = $check->get_group();
				$applicable = ( '' === $group || $applicability->resolve( $group )['applicable'] );
				echo '<label class="wpfchs-profile-check' . ( $applicable ? '' : ' wpfchs-check-excluded' ) . '">';
				echo '<input type="checkbox" name="wpfchs_profile_checks[]" value="' . esc_attr( $check_id ) . '"' . checked( in_array( $check_id, $checked_ids, true ), true, false ) . ( $applicable ? '' : ' disabled' ) . ' /> ';
				echo esc_html( $check->get_label() );
				echo ' ' . wp_kses_post( $core->admin->severity_badge( $check->get_severity() ) );
				if ( ! $applicable ) {
					echo ' <span class="wpfchs-muted">' . esc_html__( '(excluded by applicability)', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
				}
				echo '</label>';
			}

			echo '</fieldset>';

		}

		echo '<p class="wpfchs-panel-foot"><button type="submit" class="button button-primary">';
		echo esc_html( $editing ? __( 'Save changes', 'catalog-health-scanner-for-woocommerce' ) : __( 'Save custom profile', 'catalog-health-scanner-for-woocommerce' ) );
		echo '</button></p>';
		echo '</div>';
		echo '</form>';

	}

}

endif;

return new WPFCHS_Admin_Profiles();
