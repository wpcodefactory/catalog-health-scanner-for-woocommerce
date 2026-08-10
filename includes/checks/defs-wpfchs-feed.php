<?php
/**
 * Catalog Health Scanner for WooCommerce - Check Definitions - Feed Readiness
 *
 * Auto-detected: off for stores not running product feeds.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

/**
 * Returns the first registered brand taxonomy, if any.
 */
$wpfchs_brand_taxonomy = function () {
	static $taxonomy = null;
	if ( null === $taxonomy ) {
		$taxonomy = '';
		foreach ( apply_filters( 'wpfchs_brand_taxonomies', array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) ) as $candidate ) {
			if ( taxonomy_exists( $candidate ) ) {
				$taxonomy = $candidate;
				break;
			}
		}
	}
	return $taxonomy;
};

return array(

	array(
		'id'          => 'gtin_missing',
		'label'       => __( 'Missing GTIN, EAN, UPC, or MPN', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Feed platforms reject listings without a product identifier, so this product earns no paid traffic.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'check'       => function ( $product ) {
			if ( is_callable( array( $product, 'get_global_unique_id' ) ) && '' !== (string) $product->get_global_unique_id() ) {
				return false;
			}
			$meta_keys = apply_filters(
				'wpfchs_feed_meta_keys',
				array( '_global_unique_id', '_alg_ean', '_wpm_gtin_code' )
			);
			foreach ( $meta_keys as $meta_key ) {
				if ( '' !== (string) get_post_meta( $product->get_id(), $meta_key, true ) ) {
					return false;
				}
			}
			return __( 'No identifier', 'catalog-health-scanner-for-woocommerce' );
		},
	),

	array(
		'id'          => 'brand_missing',
		'label'       => __( 'Missing brand', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Most feed platforms require a brand; listings without one are rejected or demoted.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'fix'         => 'assign_brand',
		'fix_type'    => 'bulk',
		'applies'     => function () use ( $wpfchs_brand_taxonomy ) {
			return ( '' !== $wpfchs_brand_taxonomy() );
		},
		'check'       => function ( $product ) use ( $wpfchs_brand_taxonomy ) {
			$terms = get_the_terms( $product->get_id(), $wpfchs_brand_taxonomy() );
			return ( empty( $terms ) || is_wp_error( $terms ) ? __( 'No brand', 'catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'condition_missing',
		'label'       => __( 'Missing product condition', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Feed platforms assume "new" when condition is missing; wrong assumptions get listings suspended.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'applies'     => function () {
			// Only meaningful when the store actually maintains condition
			// data (i.e. at least one product has it set).
			static $in_use = null;
			if ( null === $in_use ) {
				$in_use = wpfchs()->core->applicability->any_product_meta_populated(
					apply_filters( 'wpfchs_condition_meta_keys', array( '_woosea_condition', '_woo_feed_condition', '_wpfchs_condition' ) )
				);
			}
			return $in_use;
		},
		'check'       => function ( $product ) {
			foreach ( apply_filters( 'wpfchs_condition_meta_keys', array( '_woosea_condition', '_woo_feed_condition', '_wpfchs_condition' ) ) as $meta_key ) {
				if ( '' !== (string) get_post_meta( $product->get_id(), $meta_key, true ) ) {
					return false;
				}
			}
			return __( 'No condition', 'catalog-health-scanner-for-woocommerce' );
		},
	),

	array(
		'id'          => 'google_category_missing',
		'label'       => __( 'Missing Google product category mapping', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Unmapped products land in the wrong Google Shopping category and reach the wrong buyers.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'applies'     => function () {
			static $in_use = null;
			if ( null === $in_use ) {
				$in_use = wpfchs()->core->applicability->any_product_meta_populated(
					apply_filters( 'wpfchs_google_category_meta_keys', array( '_woosea_google_product_category', '_woo_feed_google_category', '_wpfchs_google_category' ) )
				);
			}
			return $in_use;
		},
		'check'       => function ( $product ) {
			foreach ( apply_filters( 'wpfchs_google_category_meta_keys', array( '_woosea_google_product_category', '_woo_feed_google_category', '_wpfchs_google_category' ) ) as $meta_key ) {
				if ( '' !== (string) get_post_meta( $product->get_id(), $meta_key, true ) ) {
					return false;
				}
			}
			return __( 'No mapping', 'catalog-health-scanner-for-woocommerce' );
		},
	),

	array(
		'id'          => 'feed_description_too_long',
		'label'       => __( 'Description exceeding feed platform character limits', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Feed platforms truncate or reject descriptions past their limit.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'low',
		'check'       => function ( $product ) {
			$length = mb_strlen( trim( wp_strip_all_tags( $product->get_description( 'edit' ) ) ) );
			$max    = (int) wpfchs()->core->get_threshold( 'max_feed_desc_chars' );
			if ( $max > 0 && $length > $max ) {
				return sprintf(
					/* translators: %1$d: description length, %2$d: limit. */
					__( '%1$d of %2$d characters', 'catalog-health-scanner-for-woocommerce' ),
					$length,
					$max
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'feed_title_too_long',
		'label'       => __( 'Title exceeding feed platform character limits', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Google Shopping truncates titles over 150 characters, cutting off what you wrote.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'check'       => function ( $product ) {
			$length = mb_strlen( $product->get_name( 'edit' ) );
			if ( $length > 150 ) {
				return sprintf(
					/* translators: %d: title length in characters. */
					__( '%d characters', 'catalog-health-scanner-for-woocommerce' ),
					$length
				);
			}
			return false;
		},
	),

);
