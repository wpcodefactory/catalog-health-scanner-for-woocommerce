<?php
/**
 * Catalog Health Scanner for WooCommerce - Checks Registry Class
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Checks' ) ) :

class WPFCHS_Checks {

	/**
	 * Registered checks (id => WPFCHS_Check), lazy-built.
	 *
	 * @var     array|null
	 * @since   1.0.0
	 */
	protected $checks = null;

	/**
	 * Category ids in display order (spec section 6) with labels.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array
	 */
	function get_categories() {
		return apply_filters(
			'wpfchs_categories',
			array(
				'purchasability' => __( 'Purchasability', 'catalog-health-scanner-for-woocommerce' ),
				'inventory'      => __( 'Inventory', 'catalog-health-scanner-for-woocommerce' ),
				'shipping'       => __( 'Shipping', 'catalog-health-scanner-for-woocommerce' ),
				'tax'            => __( 'Tax', 'catalog-health-scanner-for-woocommerce' ),
				'media'          => __( 'Media', 'catalog-health-scanner-for-woocommerce' ),
				'structure'      => __( 'Structure', 'catalog-health-scanner-for-woocommerce' ),
				'downloads'      => __( 'Downloads', 'catalog-health-scanner-for-woocommerce' ),
				'content'        => __( 'Content', 'catalog-health-scanner-for-woocommerce' ),
				'feed'           => __( 'Feed Readiness', 'catalog-health-scanner-for-woocommerce' ),
				'pricing'        => __( 'Pricing', 'catalog-health-scanner-for-woocommerce' ),
			)
		);
	}

	/**
	 * Returns all registered checks.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array id => WPFCHS_Check
	 */
	function get_all() {

		if ( null !== $this->checks ) {
			return $this->checks;
		}

		$path = plugin_dir_path( __FILE__ );
		$defs = array();

		foreach ( array_keys( $this->get_categories() ) as $category ) {
			$file = $path . 'defs-wpfchs-' . $category . '.php';
			if ( file_exists( $file ) ) {
				foreach ( (array) require $file as $def ) {
					$def['category'] = $category;
					$defs[]          = $def;
				}
			}
		}

		/**
		 * Extension point: add or modify check definitions.
		 *
		 * @since 1.0.0
		 */
		$defs = apply_filters( 'wpfchs_checks', $defs );

		$this->checks = array();
		foreach ( $defs as $def ) {
			$check = new WPFCHS_Check( $def );
			if ( '' !== $check->get_id() ) {
				$this->checks[ $check->get_id() ] = $check;
			}
		}

		return $this->checks;

	}

	/**
	 * get.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $id
	 * @return  WPFCHS_Check|null
	 */
	function get( $id ) {
		$checks = $this->get_all();
		return ( $checks[ $id ] ?? null );
	}

	/**
	 * get_by_category.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $category
	 * @return  array id => WPFCHS_Check
	 */
	function get_by_category( $category ) {
		return array_filter(
			$this->get_all(),
			function ( $check ) use ( $category ) {
				return ( $check->get_category() === $category );
			}
		);
	}

	/**
	 * Checks disabled individually from settings.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array of check ids
	 */
	function get_disabled() {
		return array_filter( (array) get_option( 'wpfchs_disabled_checks', array() ) );
	}

	/**
	 * Returns the checks that should actually run for a scan:
	 * enabled, in the profile, and in an applicable group.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array|null $profile_check_ids Null = all checks.
	 * @return  array id => WPFCHS_Check
	 */
	function get_runnable( $profile_check_ids = null ) {

		$applicability = wpfchs()->core->applicability;
		$disabled      = $this->get_disabled();
		$runnable      = array();

		foreach ( $this->get_all() as $id => $check ) {
			if ( in_array( $id, $disabled, true ) ) {
				continue;
			}
			if ( null !== $profile_check_ids && ! in_array( $id, $profile_check_ids, true ) ) {
				continue;
			}
			$group = $check->get_group();
			if ( '' !== $group && ! $applicability->resolve( $group )['applicable'] ) {
				continue;
			}
			$runnable[ $id ] = $check;
		}

		return $runnable;

	}

	/**
	 * Whether a check counts toward the health score.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   WPFCHS_Check $check
	 * @return  bool
	 */
	function is_scored( $check ) {
		$group = $check->get_group();
		if ( '' === $group ) {
			return true;
		}
		return wpfchs()->core->applicability->resolve( $group )['scored'];
	}

}

endif;

return new WPFCHS_Checks();
