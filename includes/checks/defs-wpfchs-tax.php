<?php
/**
 * Catalog Health Scanner for WooCommerce - Check Definitions - Tax
 *
 * Auto-detected: off when tax is disabled or only the standard class exists.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

return array(

	array(
		'id'          => 'tax_class_invalid',
		'label'       => __( 'Product referencing a deleted tax class', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This product falls back to the standard rate, which may be the wrong tax for it.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'fix'         => 'reset_tax_class',
		'fix_type'    => 'auto',
		'check'       => function ( $product ) {
			// Read the raw meta, not get_tax_class(). WooCommerce sanitizes an
			// unknown tax class to '' when it reads the product, so the CRUD
			// getter can never show us the broken value — it silently reports
			// the standard rate, which is exactly the failure being looked for.
			$tax_class = (string) get_post_meta( $product->get_id(), '_tax_class', true );
			if ( '' === $tax_class || 'standard' === $tax_class ) {
				return false;
			}
			$valid = \WC_Tax::get_tax_class_slugs();
			return ( ! in_array( $tax_class, $valid, true ) ? $tax_class : false );
		},
	),

	array(
		'id'          => 'tax_status_none',
		'group'       => 'tax_status',
		'label'       => __( 'Tax status set to none', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'No tax is ever charged on this product. If that is not deliberate, you are under-collecting tax.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'check'       => function ( $product ) {
			return ( 'none' === $product->get_tax_status( 'edit' ) ? __( 'Tax status: none', 'catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'tax_class_inconsistent',
		'label'       => __( 'Inconsistent tax class within a product category', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'A handful of products taxed differently from the rest of their category is usually a mistake, not a policy.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'low',
		'fix'         => 'assign_tax_class',
		'fix_type'    => 'bulk',
		'pass'        => 'catalog',
		'check'       => function () {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Catalog-wide tax class aggregation has no WP API; runs once per scan.
			$rows = $wpdb->get_results(
				"SELECT p.ID, tr.term_taxonomy_id AS cat, COALESCE( pm.meta_value, '' ) AS tax_class
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
				LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_tax_class'
				WHERE p.post_type = 'product' AND p.post_status = 'publish'"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$by_cat = array();
			foreach ( (array) $rows as $row ) {
				$by_cat[ (int) $row->cat ][ (int) $row->ID ] = (string) $row->tax_class;
			}
			$results = array();
			foreach ( $by_cat as $products ) {
				if ( count( $products ) < 10 ) {
					continue;
				}
				$tally = array_count_values( $products );
				arsort( $tally );
				$dominant       = (string) array_key_first( $tally );
				$dominant_share = $tally[ $dominant ] / count( $products );
				if ( $dominant_share < 0.8 ) {
					continue; // No clear norm in this category.
				}
				foreach ( $products as $product_id => $tax_class ) {
					if ( $tax_class !== $dominant ) {
						$results[ $product_id ] = array(
							'product_id' => $product_id,
							'value'      => sprintf(
								/* translators: %1$s: product's tax class, %2$s: dominant tax class in the category. */
								__( '"%1$s" while the category uses "%2$s"', 'catalog-health-scanner-for-woocommerce' ),
								( '' !== $tax_class ? $tax_class : __( 'standard', 'catalog-health-scanner-for-woocommerce' ) ),
								( '' !== $dominant ? $dominant : __( 'standard', 'catalog-health-scanner-for-woocommerce' ) )
							),
						);
					}
				}
			}
			return $results;
		},
	),

);
