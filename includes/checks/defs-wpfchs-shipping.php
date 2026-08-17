<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - Check Definitions - Shipping
 *
 * Auto-detected: off for stores where shipping cost does not depend on
 * product dimensions.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

$wpfchs_is_physical = function ( $product ) {
	return (
		! $product->is_virtual() &&
		! $product->is_type( array( 'external', 'grouped' ) )
	);
};

return array(

	array(
		'id'          => 'weight_missing',
		'label'       => __( 'Physical product with no weight', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Shipping is being calculated incorrectly for this product, so you overcharge or undercharge on every order.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'fix'         => 'set_weight',
		'fix_type'    => 'bulk',
		'applies'     => $wpfchs_is_physical,
		'check'       => function ( $product ) {
			if ( '' !== $product->get_weight( 'edit' ) ) {
				return false;
			}
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$variation = wc_get_product( $child_id );
					if ( $variation && '' !== $variation->get_weight( 'edit' ) ) {
						return false;
					}
				}
			}
			return __( 'No weight', 'wpfactory-catalog-health-scanner-for-woocommerce' );
		},
	),

	array(
		'id'          => 'dimensions_missing',
		'label'       => __( 'Physical product with no dimensions', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Carriers that price by size cannot quote this product correctly.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'applies'     => $wpfchs_is_physical,
		'check'       => function ( $product ) {
			if ( $product->has_dimensions() ) {
				return false;
			}
			if ( $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $child_id ) {
					$variation = wc_get_product( $child_id );
					if ( $variation && $variation->has_dimensions() ) {
						return false;
					}
				}
			}
			return __( 'No dimensions', 'wpfactory-catalog-health-scanner-for-woocommerce' );
		},
	),

	array(
		'id'          => 'virtual_with_dimensions',
		'label'       => __( 'Virtual product carrying weight or dimensions', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Virtual products are never shipped; stored weights and sizes here only confuse exports and feeds.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'fix'         => 'clear_virtual_dimensions',
		'fix_type'    => 'auto',
		'applies'     => function ( $product ) {
			return $product->is_virtual();
		},
		'check'       => function ( $product ) {
			if ( '' !== $product->get_weight( 'edit' ) || $product->has_dimensions() ) {
				return trim( $product->get_weight( 'edit' ) . ' / ' . wc_format_dimensions( $product->get_dimensions( false ) ), ' /' );
			}
			return false;
		},
	),

	array(
		'id'          => 'downloadable_not_virtual',
		// Always on: a downloadable product that is not virtual asks the
		// customer to pay shipping on a file. That is wrong in every store,
		// whatever its shipping methods do with weight.
		'group'       => '',
		'label'       => __( 'Downloadable product not marked virtual, so shipping is charged', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Customers pay shipping on a file. That is a refund request waiting to happen.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'applies'     => function ( $product ) {
			return $product->is_downloadable();
		},
		'check'       => function ( $product ) {
			return ( ! $product->is_virtual() && $product->needs_shipping() ? __( 'Downloadable but shippable', 'wpfactory-catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'shipping_class_missing',
		'group'       => 'shipping_class',
		'label'       => __( 'Missing shipping class where classes are in use', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This product falls through your class-based shipping rules and ships at the wrong rate.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'fix'         => 'assign_shipping_class',
		'fix_type'    => 'bulk',
		'applies'     => function ( $product ) use ( $wpfchs_is_physical ) {
			if ( ! $wpfchs_is_physical( $product ) ) {
				return false;
			}
			static $store_has_classes = null;
			if ( null === $store_has_classes ) {
				$terms             = get_terms(
					array(
						'taxonomy'   => 'product_shipping_class',
						'hide_empty' => false,
						'number'     => 1,
						'fields'     => 'ids',
					)
				);
				$store_has_classes = ( ! is_wp_error( $terms ) && ! empty( $terms ) );
			}
			return $store_has_classes;
		},
		'check'       => function ( $product ) {
			return ( 0 === $product->get_shipping_class_id( 'edit' ) ? __( 'No shipping class', 'wpfactory-catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'weight_implausible',
		'label'       => __( 'Weight or dimension values outside plausible range', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'A zero, negative, or absurdly large weight is almost always a data-entry or import error, and it corrupts every shipping quote.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'applies'     => $wpfchs_is_physical,
		'check'       => function ( $product ) {
			$weight = $product->get_weight( 'edit' );
			if ( '' === $weight ) {
				return false; // Covered by `weight_missing`.
			}
			$max = (float) wpfchs()->core->get_threshold( 'max_weight' );
			if ( (float) $weight <= 0 || ( $max > 0 && (float) $weight > $max ) ) {
				return $weight . ' ' . get_option( 'woocommerce_weight_unit' );
			}
			return false;
		},
	),

	array(
		'id'          => 'shipping_class_deleted',
		'group'       => 'shipping_class',
		'label'       => __( 'Product referencing a deleted term', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This product still points at a shipping class, category, or tag whose term was deleted. Class-based rates and filters silently fall back, and the stale row confuses exports.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'fix'         => 'unassign_deleted_shipping_class',
		'fix_type'    => 'auto',
		'pass'        => 'catalog',
		// Deliberately a catalog pass reading term_relationships directly.
		// get_shipping_class_id() resolves through term_taxonomy, so once the
		// term row is gone WooCommerce reports "no shipping class" and the
		// orphaned relationship becomes invisible to every CRUD getter — the
		// per-product form of this check could never fire.
		'check'       => function () {
			global $wpdb;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Orphan detection needs a LEFT JOIN across the taxonomy tables; no WP API exposes it. Runs once per scan.
			$rows = $wpdb->get_results(
				"SELECT tr.object_id, COUNT(*) AS orphans
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->posts} p
					ON p.ID = tr.object_id AND p.post_type = 'product' AND p.post_status != 'trash'
				LEFT JOIN {$wpdb->term_taxonomy} tt
					ON tt.term_taxonomy_id = tr.term_taxonomy_id
				WHERE tt.term_taxonomy_id IS NULL
				GROUP BY tr.object_id"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

			$results = array();
			foreach ( (array) $rows as $row ) {
				$results[ (int) $row->object_id ] = array(
					'product_id' => (int) $row->object_id,
					'value'      => sprintf(
						/* translators: %d: number of orphaned term references. */
						_n( '%d deleted term still referenced', '%d deleted terms still referenced', (int) $row->orphans, 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						(int) $row->orphans
					),
				);
			}
			return $results;

		},
	),

);
