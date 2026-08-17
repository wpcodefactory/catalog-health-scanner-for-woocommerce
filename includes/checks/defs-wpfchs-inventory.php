<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - Check Definitions - Inventory
 *
 * Applicable when stock management is enabled store-wide.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

return array(

	array(
		'id'          => 'sku_missing',
		'group'       => 'sku',
		'label'       => __( 'Missing SKU', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'These products cannot be tracked in inventory systems or matched to supplier data.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'fix'         => 'generate_skus',
		'fix_type'    => 'bulk',
		'check'       => function ( $product ) {
			return ( '' === $product->get_sku( 'edit' ) ? __( 'No SKU', 'wpfactory-catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'sku_duplicate',
		'group'       => 'sku',
		'label'       => __( 'Duplicate SKU across products or variations', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Your inventory counts are wrong, and orders may be fulfilled with the wrong item.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'critical',
		'pass'        => 'catalog',
		'check'       => function () {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Catalog-wide SKU aggregation has no WP API; runs once per scan.
			$rows = $wpdb->get_results(
				"SELECT pm.post_id, pm.meta_value AS sku
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_sku'
					AND pm.meta_value != ''
					AND p.post_type IN ( 'product', 'product_variation' )
					AND p.post_status NOT IN ( 'trash', 'auto-draft' )
					AND pm.meta_value IN (
						SELECT sku FROM (
							SELECT pm2.meta_value AS sku
							FROM {$wpdb->postmeta} pm2
							INNER JOIN {$wpdb->posts} p2 ON p2.ID = pm2.post_id
							WHERE pm2.meta_key = '_sku'
								AND pm2.meta_value != ''
								AND p2.post_type IN ( 'product', 'product_variation' )
								AND p2.post_status NOT IN ( 'trash', 'auto-draft' )
							GROUP BY pm2.meta_value
							HAVING COUNT(*) > 1
						) dupes
					)"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$results = array();
			foreach ( (array) $rows as $row ) {
				$object_id = (int) $row->post_id;
				$parent_id = (int) wp_get_post_parent_id( $object_id );
				$results[ $object_id ] = array(
					'product_id' => ( $parent_id ? $parent_id : $object_id ),
					'value'      => $row->sku,
				);
			}
			return $results;
		},
	),

	array(
		'id'          => 'sku_near_duplicate',
		'group'       => 'sku',
		'label'       => __( 'Near-duplicate SKU differing only by case or whitespace', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Two SKUs that only differ by case or spaces are treated as different products by some systems and the same by others.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'pass'        => 'catalog',
		'check'       => function () {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Catalog-wide SKU aggregation has no WP API; runs once per scan.
			$rows = $wpdb->get_results(
				"SELECT pm.post_id, pm.meta_value AS sku
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = '_sku'
					AND pm.meta_value != ''
					AND p.post_type IN ( 'product', 'product_variation' )
					AND p.post_status NOT IN ( 'trash', 'auto-draft' )"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$by_normalized = array();
			foreach ( (array) $rows as $row ) {
				$by_normalized[ strtolower( trim( $row->sku ) ) ][ (int) $row->post_id ] = $row->sku;
			}
			$results = array();
			foreach ( $by_normalized as $group ) {
				// Exact duplicates are already covered by `sku_duplicate`.
				if ( count( $group ) < 2 || count( array_unique( $group ) ) < 2 ) {
					continue;
				}
				foreach ( $group as $object_id => $sku ) {
					$parent_id = (int) wp_get_post_parent_id( $object_id );
					$results[ $object_id ] = array(
						'product_id' => ( $parent_id ? $parent_id : $object_id ),
						'value'      => $sku,
					);
				}
			}
			return $results;
		},
	),

	array(
		'id'          => 'variation_sku_missing',
		'group'       => 'sku',
		'label'       => __( 'Variations missing SKUs while parent has one', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Individual variations cannot be told apart in exports, feeds, or fulfilment.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'fix'         => 'generate_skus',
		'fix_type'    => 'bulk',
		'applies'     => function ( $product ) {
			return $product->is_type( 'variable' );
		},
		'check'       => function ( $product ) {
			if ( '' === $product->get_sku( 'edit' ) ) {
				return false;
			}
			$missing = 0;
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation && '' === $variation->get_sku( 'edit' ) ) {
					$missing++;
				}
			}
			if ( $missing > 0 ) {
				return sprintf(
					/* translators: %d: number of variations without a SKU. */
					__( '%d variation(s) without a SKU', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					$missing
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'negative_stock',
		'label'       => __( 'Negative stock quantity', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Stock has gone below zero, which usually means orders were placed that could not be fulfilled.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'check'       => function ( $product ) {
			if ( ! $product->managing_stock() ) {
				return false;
			}
			$qty = $product->get_stock_quantity( 'edit' );
			return ( null !== $qty && $qty < 0 ? (string) $qty : false );
		},
	),

	array(
		'id'          => 'stock_qty_unmanaged',
		'label'       => __( 'Stock quantity set while stock management is off', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'These numbers are stored but ignored, which misleads anyone reading them.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'fix'         => 'clear_unmanaged_stock',
		'fix_type'    => 'auto',
		'check'       => function ( $product ) {
			if ( $product->managing_stock() ) {
				return false;
			}
			$raw = get_post_meta( $product->get_id(), '_stock', true );
			return ( '' !== $raw && null !== $raw ? (string) $raw : false );
		},
	),

	array(
		'id'          => 'stock_status_mismatch',
		'label'       => __( 'Stock status contradicting stock quantity', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Products showing in stock with zero units, or out of stock with units available.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'fix'         => 'sync_stock_status',
		'fix_type'    => 'auto',
		'check'       => function ( $product ) {
			if ( ! $product->managing_stock() ) {
				return false;
			}
			$qty    = $product->get_stock_quantity( 'edit' );
			$status = $product->get_stock_status( 'edit' );
			if ( null === $qty ) {
				return false;
			}
			if ( $qty > 0 && 'outofstock' === $status ) {
				return sprintf(
					/* translators: %d: stock quantity. */
					__( 'Out of stock with %d units', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					$qty
				);
			}
			if ( $qty <= 0 && 'instock' === $status && ! $product->backorders_allowed() ) {
				return sprintf(
					/* translators: %d: stock quantity. */
					__( 'In stock with %d units', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					$qty
				);
			}
			return false;
		},
	),

);
