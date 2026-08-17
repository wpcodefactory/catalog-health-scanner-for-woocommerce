<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - Scan Profiles Class
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Profiles' ) ) :

class WPFCHS_Profiles {

	/**
	 * Returns all profiles: built-in plus user-saved custom ones.
	 *
	 * Each profile: label, description, checks (array of check ids, or
	 * null for "every applicable check").
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array id => array{label, description, checks, custom}
	 */
	function get_all() {

		$checks = wpfchs()->core->checks;

		$critical_ids = array();
		$categories   = array();
		foreach ( $checks->get_all() as $id => $check ) {
			$categories[ $check->get_category() ][] = $id;
			if ( 'critical' === $check->get_severity() ) {
				$critical_ids[] = $id;
			}
		}

		$profiles = array(
			'revenue_blockers' => array(
				'label'       => __( 'Revenue Blockers', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'description' => __( 'Critical severity only, across all categories. The recommended routine scan.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'checks'      => $critical_ids,
			),
			'pre_launch' => array(
				'label'       => __( 'Pre-launch', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'description' => __( 'Purchasability, media, structure, and content, for a store about to go live.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'checks'      => array_merge(
					$categories['purchasability'] ?? array(),
					$categories['media'] ?? array(),
					$categories['structure'] ?? array(),
					$categories['content'] ?? array()
				),
			),
			'post_migration' => array(
				'label'       => __( 'Post-migration', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'description' => __( 'Orphans, broken references, duplicates, encoding errors, and missing images after an import.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'checks'      => array(
					'orphaned_variation',
					'linked_products_deleted',
					'grouped_children_invalid',
					'sku_duplicate',
					'sku_near_duplicate',
					'title_duplicate',
					'title_artifacts',
					'broken_shortcodes',
					'image_missing',
					'image_file_missing',
					'gallery_deleted_refs',
					'shipping_class_deleted',
					'tax_class_invalid',
				),
			),
			'feed_readiness' => array(
				'label'       => __( 'Feed Readiness', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'description' => __( 'Feed checks plus media resolution and content limits, for stores running shopping campaigns.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'checks'      => array_merge(
					$categories['feed'] ?? array(),
					array( 'image_missing', 'image_low_res', 'title_duplicate' )
				),
			),
			'inventory_audit' => array(
				'label'       => __( 'Inventory Audit', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'description' => __( 'Inventory plus stock-related purchasability checks, for reconciling physical stock.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'checks'      => array_merge(
					$categories['inventory'] ?? array(),
					array( 'out_of_stock_stale' )
				),
			),
			'full' => array(
				'label'       => __( 'Full Scan', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'description' => __( 'Every applicable check. The periodic deep audit.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'checks'      => null,
			),
		);

		foreach ( $profiles as $id => $profile ) {
			$profiles[ $id ]['custom'] = false;
		}

		foreach ( (array) get_option( 'wpfchs_custom_profiles', array() ) as $id => $profile ) {
			$profiles[ $id ] = array(
				'label'       => (string) ( $profile['label'] ?? $id ),
				'description' => (string) ( $profile['description'] ?? '' ),
				'checks'      => array_values( array_filter( (array) ( $profile['checks'] ?? array() ) ) ),
				'custom'      => true,
			);
		}

		return apply_filters( 'wpfchs_profiles', $profiles );

	}

	/**
	 * get.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $id
	 * @return  array|null
	 */
	function get( $id ) {
		$profiles = $this->get_all();
		return ( $profiles[ $id ] ?? null );
	}

	/**
	 * Saves (or updates) a custom profile.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $id     Sanitized key; auto-generated from label when empty.
	 * @param   string $label
	 * @param   array  $check_ids
	 * @return  string         The profile id.
	 */
	function save_custom( $id, $label, $check_ids ) {

		$custom = (array) get_option( 'wpfchs_custom_profiles', array() );

		if ( '' === $id ) {
			$id = 'custom_' . sanitize_key( $label );
		}

		$known     = array_keys( wpfchs()->core->checks->get_all() );
		$check_ids = array_values( array_intersect( array_map( 'sanitize_key', (array) $check_ids ), $known ) );

		$custom[ $id ] = array(
			'label'  => $label,
			'checks' => $check_ids,
		);

		update_option( 'wpfchs_custom_profiles', $custom, false );

		return $id;

	}

	/**
	 * delete_custom.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $id
	 */
	function delete_custom( $id ) {
		$custom = (array) get_option( 'wpfchs_custom_profiles', array() );
		unset( $custom[ $id ] );
		update_option( 'wpfchs_custom_profiles', $custom, false );
	}

	/**
	 * Default profile for manual and scheduled scans.
	 *
	 * The very first scan is always Revenue Blockers (spec: the first
	 * result must be short and alarming in the right way, not a wall of
	 * four thousand issues).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function get_default() {
		$default = get_option( 'wpfchs_default_profile', 'revenue_blockers' );
		return ( null !== $this->get( $default ) ? $default : 'revenue_blockers' );
	}

	/**
	 * Profile the dashboard dropdown should preselect: the last one the user
	 * ran manually, falling back to the configured default.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function get_selected() {
		$last = get_option( 'wpfchs_last_profile', '' );
		if ( '' !== $last && null !== $this->get( $last ) ) {
			return $last;
		}
		return $this->get_default();
	}

}

endif;

return new WPFCHS_Profiles();
