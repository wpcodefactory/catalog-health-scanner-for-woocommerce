<?php
/**
 * Catalog Health Scanner for WooCommerce - Check Definitions - Pricing Sanity
 *
 * Basic price checks always apply; cost/margin checks belong to the
 * auto-detected `cog` applicability group.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads a product's cost of goods from the known COG meta keys.
 */
$wpfchs_get_cost = function ( $product ) {
	foreach ( apply_filters( 'wpfchs_cog_meta_keys', array( '_alg_wc_cog_cost', '_wc_cog_cost' ) ) as $meta_key ) {
		$cost = get_post_meta( $product->get_id(), $meta_key, true );
		if ( '' !== $cost && null !== $cost ) {
			return (float) $cost;
		}
	}
	return null;
};

return array(

	array(
		'id'          => 'price_zero',
		'group'       => 'selling',
		'label'       => __( 'Price of zero on a product not marked as free', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This product sells for nothing. If that is not deliberate, every order is pure loss.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'applies'     => function ( $product ) {
			return $product->is_type( array( 'simple', 'external' ) );
		},
		'check'       => function ( $product ) {
			$price = $product->get_price( 'edit' );
			return ( '' !== $price && 0.0 === (float) $price ? wc_format_decimal( $price ) : false );
		},
	),

	array(
		'id'          => 'price_outside_median',
		'group'       => 'selling',
		'label'       => __( 'Price far outside the category median', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'A price several times above or below everything comparable is usually a misplaced decimal point.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'pass'        => 'catalog',
		'check'       => function () {
			global $wpdb;
			$factor = max( 2.0, (float) wpfchs()->core->get_threshold( 'price_deviation_factor' ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Catalog-wide price aggregation has no WP API; runs once per scan.
			$rows = $wpdb->get_results(
				"SELECT p.ID, tr.term_taxonomy_id AS cat, pm.meta_value AS price
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_price' AND pm.meta_value != ''
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
				WHERE p.post_type = 'product' AND p.post_status = 'publish'"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$by_cat   = array();
			$products = array();
			foreach ( (array) $rows as $row ) {
				$price = (float) $row->price;
				if ( $price <= 0 ) {
					continue; // Zero prices are covered by `price_zero`.
				}
				$by_cat[ (int) $row->cat ][]         = $price;
				$products[ (int) $row->ID ]['price'] = $price;
				$products[ (int) $row->ID ]['cats'][] = (int) $row->cat;
			}
			$medians = array();
			foreach ( $by_cat as $cat => $prices ) {
				if ( count( $prices ) < 5 ) {
					continue; // Too small a sample to define "normal".
				}
				sort( $prices );
				$mid            = (int) floor( ( count( $prices ) - 1 ) / 2 );
				$medians[ $cat ] = (
					0 === count( $prices ) % 2 ?
					( $prices[ $mid ] + $prices[ $mid + 1 ] ) / 2 :
					$prices[ $mid ]
				);
			}
			$results = array();
			foreach ( $products as $product_id => $data ) {
				$outlier_in = 0;
				$judged_in  = 0;
				$median_ref = 0.0;
				foreach ( array_unique( $data['cats'] ) as $cat ) {
					if ( ! isset( $medians[ $cat ] ) ) {
						continue;
					}
					$judged_in++;
					if ( $data['price'] > $medians[ $cat ] * $factor || $data['price'] < $medians[ $cat ] / $factor ) {
						$outlier_in++;
						$median_ref = $medians[ $cat ];
					}
				}
				// Conservative: only flag when the price is an outlier in
				// EVERY category the product belongs to.
				if ( $judged_in > 0 && $outlier_in === $judged_in ) {
					$results[ $product_id ] = array(
						'product_id' => $product_id,
						'value'      => sprintf(
							/* translators: %1$s: product price, %2$s: category median price. */
							__( 'Price %1$s vs category median %2$s', 'catalog-health-scanner-for-woocommerce' ),
							wc_format_decimal( $data['price'] ),
							wc_format_decimal( $median_ref )
						),
					);
				}
			}
			return $results;
		},
	),

	array(
		'id'          => 'price_decimals_excess',
		'group'       => 'selling',
		'label'       => __( 'Price with more decimals than the store currency supports', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'The displayed price is rounded, so what customers see is not what is charged.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'check'       => function ( $product ) {
			$price = $product->get_regular_price( 'edit' );
			if ( '' === $price || false === strpos( $price, '.' ) ) {
				return false;
			}
			$decimals = strlen( rtrim( substr( $price, strpos( $price, '.' ) + 1 ), '0' ) );
			return ( $decimals > wc_get_price_decimals() ? $price : false );
		},
	),

	array(
		'id'          => 'cog_missing',
		'label'       => __( 'Cost of goods missing', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Without a cost you cannot see margin, so you cannot see which products lose money.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'group'       => 'cog',
		'fix'         => 'set_cog_percent',
		'fix_type'    => 'bulk',
		'check'       => function ( $product ) use ( $wpfchs_get_cost ) {
			return ( null === $wpfchs_get_cost( $product ) ? __( 'No cost set', 'catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'cog_above_price',
		'label'       => __( 'Cost of goods higher than selling price', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'You lose money on every unit sold.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'critical',
		'group'       => 'cog',
		'check'       => function ( $product ) use ( $wpfchs_get_cost ) {
			$cost  = $wpfchs_get_cost( $product );
			$price = $product->get_price( 'edit' );
			if ( null !== $cost && '' !== $price && $cost > (float) $price ) {
				return sprintf(
					/* translators: %1$s: cost of goods, %2$s: selling price. */
					__( 'Cost %1$s vs price %2$s', 'catalog-health-scanner-for-woocommerce' ),
					wc_format_decimal( $cost ),
					wc_format_decimal( $price )
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'margin_below_threshold',
		'label'       => __( 'Margin below your minimum threshold', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This product sells below the margin floor you set in settings.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'group'       => 'cog',
		'applies'     => function () {
			return ( (float) wpfchs()->core->get_threshold( 'min_margin_percent' ) > 0 );
		},
		'check'       => function ( $product ) use ( $wpfchs_get_cost ) {
			$cost  = $wpfchs_get_cost( $product );
			$price = $product->get_price( 'edit' );
			if ( null === $cost || '' === $price || (float) $price <= 0 ) {
				return false;
			}
			$margin    = ( ( (float) $price - $cost ) / (float) $price ) * 100;
			$threshold = (float) wpfchs()->core->get_threshold( 'min_margin_percent' );
			if ( $margin < $threshold ) {
				return sprintf(
					/* translators: %1$s: actual margin percentage, %2$s: minimum margin percentage. */
					__( '%1$s%% margin, minimum is %2$s%%', 'catalog-health-scanner-for-woocommerce' ),
					number_format_i18n( $margin, 1 ),
					number_format_i18n( $threshold, 1 )
				);
			}
			return false;
		},
	),

);
