<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - Setup Wizard Class
 *
 * Six questions, all skippable, each pre-answered with the auto-detect
 * result. The user is confirming, not filling in a form. Answers become
 * the applicability settings.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Admin_Wizard' ) ) :

class WPFCHS_Admin_Wizard {

	/**
	 * Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_save_step' ) );
	}

	/**
	 * Wizard steps, in order.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array
	 */
	function get_steps() {
		return array(
			'selling' => array(
				'group'   => 'selling',
				'title'   => __( 'Do customers buy directly on this site?', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'name'    => __( 'Selling', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'options' => array(
					'yes'         => array(
						__( 'Yes, this is a store with prices and checkout', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Price and purchasability checks stay on and count toward your score.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
					'no'          => array(
						__( 'No, it is a catalog — customers order by quote, phone, or elsewhere', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Missing-price and sale-price checks are skipped, so products without prices never count against you.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
					'report_only' => array(
						__( 'Mostly catalog, but I still want price problems reported', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Checks run and are reported, but stay out of the health score.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
				),
			),
			'shipping' => array(
				'group'    => 'shipping',
				'title'    => __( 'Does your shipping cost depend on weight or size?', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'name'     => __( 'Shipping', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'options'  => array(
					'yes'         => array(
						__( 'Yes, my shipping rates use weight or dimensions', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Weight and dimension checks stay on and count toward your score.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
					'no'          => array(
						__( 'No, I ship manually or use flat rates', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Shipping checks are marked not applicable. They will not lower your score.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
					'report_only' => array(
						__( 'I still want weights recorded for warehouse or customs purposes', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Checks run and are reported, but stay out of the health score.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
				),
			),
			'feed' => array(
				'group'   => 'feed',
				'title'   => __( 'Do you list products on Google Shopping, Meta, or a comparison engine?', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'name'    => __( 'Feeds', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'options' => array(
					'yes' => array(
						__( 'Yes, I run product feeds', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Feed readiness checks (identifiers, brand, image requirements) stay on.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
					'no'  => array(
						__( 'No feeds', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Feed checks are skipped and excluded from your score.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
				),
			),
			'cog' => array(
				'group'   => 'cog',
				'title'   => __( 'Do you track cost of goods and margin?', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'name'    => __( 'Costs', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'options' => array(
					'yes' => array(
						__( 'Yes, products carry cost data', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Margin checks warn you when a product sells at a loss.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
					'no'  => array(
						__( 'No cost tracking', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Cost and margin checks are skipped and excluded from your score.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
				),
			),
			'downloads' => array(
				'group'   => 'downloads',
				'title'   => __( 'Do you sell downloadable or virtual products?', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'name'    => __( 'Downloads', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'options' => array(
					'yes' => array(
						__( 'Yes', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Download checks verify every file actually exists and is attached.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
					'no'  => array(
						__( 'No', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Download checks are skipped and excluded from your score.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
				),
			),
			'tax' => array(
				'group'   => 'tax',
				'title'   => __( 'Do you sell to multiple tax regions or use multiple tax classes?', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'name'    => __( 'Tax', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'options' => array(
					'yes' => array(
						__( 'Yes', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Tax class checks stay on and count toward your score.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
					'no'  => array(
						__( 'No, one region and one rate', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						__( 'Tax checks are skipped and excluded from your score.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					),
				),
			),
		);
	}

	/**
	 * maybe_save_step.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function maybe_save_step() {

		if ( ! isset( $_POST['wpfchs_wizard_step'] ) ) {
			return;
		}

		check_admin_referer( 'wpfchs-wizard' );

		if ( ! current_user_can( wpfchs()->core->get_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wpfactory-catalog-health-scanner-for-woocommerce' ) );
		}

		$steps   = $this->get_steps();
		$step_id = sanitize_key( wp_unslash( $_POST['wpfchs_wizard_step'] ) );

		if ( ! isset( $steps[ $step_id ] ) ) {
			return;
		}

		$step   = $steps[ $step_id ];
		$answer = isset( $_POST['wpfchs_wizard_answer'] ) ? sanitize_key( wp_unslash( $_POST['wpfchs_wizard_answer'] ) ) : '';

		if ( isset( $step['options'][ $answer ] ) ) {
			$auto   = wpfchs()->core->applicability->auto_detect( $step['group'] );
			$states = (array) get_option( 'wpfchs_applicability', array() );
			// Confirming the detected answer keeps auto-detect live for the
			// future; overriding it pins the manual state.
			$detected = ( $auto['applicable'] ? 'yes' : 'no' );
			$states[ $step['group'] ] = ( $answer === $detected ? 'auto' : $answer );
			update_option( 'wpfchs_applicability', $states, false );
		}

		$step_ids = array_keys( $steps );
		$position = (int) array_search( $step_id, $step_ids, true );

		if ( $position === count( $step_ids ) - 1 ) {
			update_option( 'wpfchs_setup_complete', 'yes', false );
			wp_safe_redirect( admin_url( 'admin.php?page=wpfchs&wpfchs_setup_done=1' ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=wpfchs-setup&step=' . rawurlencode( $step_ids[ $position + 1 ] ) ) );
		}
		exit;

	}

	/**
	 * render_page.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_page() {

		$steps    = $this->get_steps();
		$step_ids = array_keys( $steps );

		$current = filter_input( INPUT_GET, 'step', FILTER_SANITIZE_SPECIAL_CHARS );
		$current = ( is_string( $current ) ? sanitize_key( $current ) : '' );
		if ( ! isset( $steps[ $current ] ) ) {
			$current = $step_ids[0];
		}

		$step     = $steps[ $current ];
		$position = (int) array_search( $current, $step_ids, true );
		$auto     = wpfchs()->core->applicability->auto_detect( $step['group'] );
		$detected = ( $auto['applicable'] ? 'yes' : 'no' );

		wpfchs()->core->admin->render_shell_open( 'setup' );

		echo '<div class="wpfchs-wizard">';

		// Progress.
		echo '<div class="wpfchs-wizard-progress">';
		echo '<span class="wpfchs-wizard-step-label">';
		printf(
			/* translators: %1$d: current step number, %2$d: total steps. */
			esc_html__( 'Step %1$d of %2$d', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
			(int) ( $position + 1 ),
			(int) count( $steps )
		);
		echo '</span>';
		echo '<span class="wpfchs-wizard-bars">';
		foreach ( $step_ids as $i => $id ) {
			echo '<span class="wpfchs-wizard-bar' . ( $i <= $position ? ' wpfchs-wizard-bar-done' : '' ) . '"></span>';
		}
		echo '</span>';
		echo '<span class="wpfchs-muted">' . esc_html( $step['name'] ) . '</span>';
		echo '</div>';

		echo '<form method="post" class="wpfchs-card wpfchs-wizard-card">';
		wp_nonce_field( 'wpfchs-wizard' );
		echo '<input type="hidden" name="wpfchs_wizard_step" value="' . esc_attr( $current ) . '" />';

		echo '<h2>' . esc_html( $step['title'] ) . '</h2>';
		echo '<p class="wpfchs-muted">';
		echo esc_html(
			sprintf(
				/* translators: %s: auto-detect reasoning. */
				__( 'We looked at your store configuration: %s If that is right, just continue.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				rtrim( $auto['reason'], '.' ) . '.'
			)
		);
		echo '</p>';

		echo '<div class="wpfchs-wizard-options">';
		foreach ( $step['options'] as $value => $option ) {
			echo '<label class="wpfchs-wizard-option">';
			echo '<input type="radio" name="wpfchs_wizard_answer" value="' . esc_attr( $value ) . '"' . checked( $value, $detected, false ) . ' />';
			echo '<span class="wpfchs-wizard-option-text">';
			echo '<span class="wpfchs-wizard-option-title">' . esc_html( $option[0] );
			if ( $value === $detected ) {
				echo ' <span class="wpfchs-badge wpfchs-badge-detected">' . esc_html__( 'Detected', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</span>';
			}
			echo '</span>';
			echo '<span class="wpfchs-muted">' . esc_html( $option[1] ) . '</span>';
			echo '</span>';
			echo '</label>';
		}
		echo '</div>';

		echo '<p class="wpfchs-muted wpfchs-wizard-note">' . esc_html__( 'You can change this later in Settings › Applicability.', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</p>';

		echo '<div class="wpfchs-wizard-actions">';
		echo '<a class="wpfchs-wizard-skip" href="' . esc_url( admin_url( 'admin.php?page=wpfchs' ) ) . '">' . esc_html__( 'Skip setup', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</a>';
		if ( $position > 0 ) {
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=wpfchs-setup&step=' . rawurlencode( $step_ids[ $position - 1 ] ) ) ) . '">' . esc_html__( 'Back', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</a>';
		}
		echo '<button type="submit" class="button button-primary">';
		echo esc_html( $position === count( $step_ids ) - 1 ? __( 'Finish', 'wpfactory-catalog-health-scanner-for-woocommerce' ) : __( 'Continue', 'wpfactory-catalog-health-scanner-for-woocommerce' ) );
		echo '</button>';
		echo '</div>';

		echo '</form>';
		echo '</div>';

		wpfchs()->core->admin->render_shell_close();

	}

}

endif;

return new WPFCHS_Admin_Wizard();
