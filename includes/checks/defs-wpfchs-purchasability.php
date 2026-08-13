<?php
/**
 * Catalog Health Scanner for WooCommerce - Check Definitions - Purchasability
 *
 * Always applicable: everything here means a product cannot be bought,
 * or is presenting a broken offer.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

return array(

	array(
		'id'          => 'no_price',
		'group'       => 'selling',
		'label'       => __( 'Published product with no price', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This product cannot be bought right now. It shows no price and no add to cart button.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'critical',
		'applies'     => function ( $product ) {
			return $product->is_type( array( 'simple', 'external' ) );
		},
		'check'       => function ( $product ) {
			return ( '' === $product->get_price( 'edit' ) ? __( 'No price set', 'catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'variable_no_purchasable',
		'group'       => 'selling',
		'label'       => __( 'Variable product where no variation is purchasable', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This product cannot be bought right now. None of its variations has a price.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'critical',
		'applies'     => function ( $product ) {
			return $product->is_type( 'variable' );
		},
		'check'       => function ( $product ) {
			$children = $product->get_children();
			if ( empty( $children ) ) {
				return __( 'No variations exist', 'catalog-health-scanner-for-woocommerce' );
			}
			foreach ( $children as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( $variation && '' !== $variation->get_price( 'edit' ) ) {
					return false;
				}
			}
			return __( 'No variation has a price', 'catalog-health-scanner-for-woocommerce' );
		},
	),

	array(
		'id'          => 'variation_price_missing',
		'group'       => 'selling',
		'label'       => __( 'Variable product where some variations have no price', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Customers who select these variations see no price and cannot buy them.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'critical',
		'applies'     => function ( $product ) {
			return $product->is_type( 'variable' );
		},
		'check'       => function ( $product ) {
			$children = $product->get_children();
			$missing  = 0;
			$have     = 0;
			foreach ( $children as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation ) {
					continue;
				}
				if ( '' === $variation->get_price( 'edit' ) ) {
					$missing++;
				} else {
					$have++;
				}
			}
			if ( $missing > 0 && $have > 0 ) {
				return sprintf(
					/* translators: %d: number of variations without a price. */
					__( '%d variation(s) without a price', 'catalog-health-scanner-for-woocommerce' ),
					$missing
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'sale_not_lower',
		'group'       => 'selling',
		'label'       => __( 'Sale price equal to or higher than regular price', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This product is showing a fake discount. Customers who notice lose trust in every other price.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'check'       => function ( $product ) {
			$sale    = $product->get_sale_price( 'edit' );
			$regular = $product->get_regular_price( 'edit' );
			if ( '' !== $sale && '' !== $regular && (float) $sale >= (float) $regular ) {
				return sprintf(
					/* translators: %1$s: sale price, %2$s: regular price. */
					__( 'Sale %1$s vs regular %2$s', 'catalog-health-scanner-for-woocommerce' ),
					$sale,
					$regular
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'sale_expired',
		'group'       => 'selling',
		'label'       => __( 'Sale schedule expired but sale price still set', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'The sale ended but the discounted price is still stored, so the product may still sell at the old sale price.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'fix'         => 'clear_expired_sale',
		'fix_type'    => 'auto',
		'check'       => function ( $product ) {
			$to = $product->get_date_on_sale_to( 'edit' );
			if ( $to && $to->getTimestamp() < time() && '' !== $product->get_sale_price( 'edit' ) ) {
				return sprintf(
					/* translators: %s: date the sale ended. */
					__( 'Sale ended %s', 'catalog-health-scanner-for-woocommerce' ),
					$to->date_i18n( 'Y-m-d' )
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'sale_dates_inverted',
		'group'       => 'selling',
		'label'       => __( 'Sale end date earlier than start date', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'The sale can never run with these dates, so the planned discount never shows.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'check'       => function ( $product ) {
			$from = $product->get_date_on_sale_from( 'edit' );
			$to   = $product->get_date_on_sale_to( 'edit' );
			if ( $from && $to && $to->getTimestamp() < $from->getTimestamp() ) {
				return sprintf(
					/* translators: %1$s: sale start date, %2$s: sale end date. */
					__( 'Starts %1$s, ends %2$s', 'catalog-health-scanner-for-woocommerce' ),
					$from->date_i18n( 'Y-m-d' ),
					$to->date_i18n( 'Y-m-d' )
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'hidden_from_catalog',
		'label'       => __( 'Product hidden from catalog and search', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This product is published but customers cannot find it anywhere on your store.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'check'       => function ( $product ) {
			return ( 'hidden' === $product->get_catalog_visibility() ? __( 'Visibility: hidden', 'catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'out_of_stock_stale',
		'label'       => __( 'Long-term out of stock, published, backorders disabled', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This product has been unbuyable for a long time while still taking up space in your catalog and search results.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'check'       => function ( $product ) {
			if ( 'outofstock' !== $product->get_stock_status() || $product->backorders_allowed() ) {
				return false;
			}
			$modified = $product->get_date_modified( 'edit' );
			$age_days = (int) wpfchs()->core->get_threshold( 'oos_age_days' );
			if ( $modified && $modified->getTimestamp() < ( time() - $age_days * DAY_IN_SECONDS ) ) {
				return sprintf(
					/* translators: %s: date of last product update. */
					__( 'Out of stock, last updated %s', 'catalog-health-scanner-for-woocommerce' ),
					$modified->date_i18n( 'Y-m-d' )
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'external_url_missing',
		'label'       => __( 'External product with a missing or malformed product URL', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'The buy button leads nowhere, so this product cannot be bought.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'critical',
		'applies'     => function ( $product ) {
			return $product->is_type( 'external' );
		},
		'check'       => function ( $product ) {
			$url = $product->get_product_url();
			if ( '' === $url ) {
				return __( 'No product URL', 'catalog-health-scanner-for-woocommerce' );
			}
			if ( ! wc_is_valid_url( $url ) ) {
				return $url;
			}
			return false;
		},
	),

	array(
		'id'          => 'grouped_no_children',
		'label'       => __( 'Grouped product with no purchasable children', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This grouped product page is empty or lists nothing that can be added to the cart.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'critical',
		'applies'     => function ( $product ) {
			return $product->is_type( 'grouped' );
		},
		'check'       => function ( $product ) {
			$children = $product->get_children();
			foreach ( $children as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && 'publish' === $child->get_status() && $child->is_purchasable() ) {
					return false;
				}
			}
			return (
				empty( $children ) ?
				__( 'No children assigned', 'catalog-health-scanner-for-woocommerce' ) :
				__( 'No purchasable children', 'catalog-health-scanner-for-woocommerce' )
			);
		},
	),

);
