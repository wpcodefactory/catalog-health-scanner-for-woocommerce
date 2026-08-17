<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - Check Definitions - Structure & Taxonomy
 *
 * Always applicable.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

return array(

	array(
		'id'          => 'category_missing',
		'label'       => __( 'Product in no category, or only Uncategorized', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This product is invisible to category browsing and filtered navigation.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'fix'         => 'assign_category',
		'fix_type'    => 'bulk',
		'check'       => function ( $product ) {
			$term_ids = $product->get_category_ids( 'edit' );
			$default  = (int) get_option( 'default_product_cat', 0 );
			$real     = array_diff( array_map( 'intval', $term_ids ), array( $default ) );
			if ( empty( $real ) ) {
				return (
					empty( $term_ids ) ?
					__( 'No category', 'wpfactory-catalog-health-scanner-for-woocommerce' ) :
					__( 'Only the default category', 'wpfactory-catalog-health-scanner-for-woocommerce' )
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'linked_products_deleted',
		'label'       => __( 'Cross-sells or upsells pointing at deleted or unpublished products', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Recommendation slots on this product page are silently empty or broken.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'fix'         => 'clean_linked_products',
		'fix_type'    => 'auto',
		'check'       => function ( $product ) {
			$linked = array_merge(
				$product->get_cross_sell_ids( 'edit' ),
				$product->get_upsell_ids( 'edit' )
			);
			$broken = 0;
			foreach ( $linked as $linked_id ) {
				if ( 'product' !== get_post_type( $linked_id ) || 'publish' !== get_post_status( $linked_id ) ) {
					$broken++;
				}
			}
			if ( $broken > 0 ) {
				return sprintf(
					/* translators: %d: number of broken linked product references. */
					__( '%d broken linked product(s)', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					$broken
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'orphaned_variation',
		'label'       => __( 'Orphaned variation with no surviving parent', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'These variations belong to no product but still exist in the database, and can leak into feeds and search.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'fix'         => 'delete_orphaned_variations',
		'fix_type'    => 'auto',
		'pass'        => 'catalog',
		'check'       => function () {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Orphan detection needs a LEFT JOIN on posts; no WP API exists. Runs once per scan.
			$rows = $wpdb->get_results(
				"SELECT v.ID, v.post_title
				FROM {$wpdb->posts} v
				LEFT JOIN {$wpdb->posts} p ON p.ID = v.post_parent
				WHERE v.post_type = 'product_variation'
					AND v.post_status != 'trash'
					AND ( v.post_parent = 0 OR p.ID IS NULL OR p.post_type != 'product' OR p.post_status = 'trash' )"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$results = array();
			foreach ( (array) $rows as $row ) {
				$results[ (int) $row->ID ] = array(
					'product_id' => (int) $row->ID,
					'value'      => $row->post_title,
				);
			}
			return $results;
		},
	),

	array(
		'id'          => 'attributes_not_for_variation',
		'label'       => __( 'Variable product with attributes not enabled for variations', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'The product has variations but no attribute drives them, so the variation picker is broken or empty.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'applies'     => function ( $product ) {
			return $product->is_type( 'variable' );
		},
		'check'       => function ( $product ) {
			if ( count( $product->get_children() ) > 0 && empty( $product->get_variation_attributes() ) ) {
				return __( 'No attribute is marked "used for variations"', 'wpfactory-catalog-health-scanner-for-woocommerce' );
			}
			return false;
		},
	),

	array(
		'id'          => 'variation_attribute_invalid',
		'label'       => __( 'Variation with missing or invalid attribute value', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'These variations reference attribute options that no longer exist, so they can never be selected.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'critical',
		'applies'     => function ( $product ) {
			return $product->is_type( 'variable' );
		},
		'check'       => function ( $product ) {
			$parent_attributes = $product->get_variation_attributes();
			if ( empty( $parent_attributes ) ) {
				return false; // Covered by `attributes_not_for_variation`.
			}
			$normalized = array();
			foreach ( $parent_attributes as $name => $options ) {
				$normalized[ sanitize_title( $name ) ] = array_map( 'sanitize_title', array_map( 'strval', (array) $options ) );
			}
			$invalid = 0;
			foreach ( $product->get_children() as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation ) {
					continue;
				}
				foreach ( $variation->get_attributes() as $name => $value ) {
					$key = sanitize_title( $name );
					if ( '' === (string) $value ) {
						continue; // "Any" is valid.
					}
					if ( isset( $normalized[ $key ] ) && ! in_array( sanitize_title( (string) $value ), $normalized[ $key ], true ) ) {
						$invalid++;
						break;
					}
					if ( ! isset( $normalized[ $key ] ) ) {
						$invalid++;
						break;
					}
				}
			}
			if ( $invalid > 0 ) {
				return sprintf(
					/* translators: %d: number of variations with invalid attribute values. */
					__( '%d variation(s) with invalid attribute values', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					$invalid
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'variation_count_mismatch',
		'label'       => __( 'Variation count not matching possible attribute combinations', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Some attribute combinations have no variation behind them, so customers can select options that cannot be bought.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'applies'     => function ( $product ) {
			return $product->is_type( 'variable' );
		},
		'check'       => function ( $product ) {
			$attributes = $product->get_variation_attributes();
			if ( empty( $attributes ) ) {
				return false;
			}
			$possible = 1;
			foreach ( $attributes as $options ) {
				$possible *= max( 1, count( (array) $options ) );
			}
			// Huge cartesians are almost never meant to be fully covered.
			if ( $possible < 2 || $possible > 200 ) {
				return false;
			}
			$actual = count( $product->get_children() );
			if ( $actual < $possible ) {
				return sprintf(
					/* translators: %1$d: existing variation count, %2$d: possible combination count. */
					__( '%1$d of %2$d combinations covered', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					$actual,
					$possible
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'category_not_in_menu',
		'label'       => __( 'Product assigned to a category that no longer appears in the menu structure', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'The only path to this product is search; browsing customers never reach it.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'low',
		'applies'     => function () {
			// Only meaningful on stores that actually put product categories
			// in their navigation menus.
			static $menu_cat_ids = null;
			if ( null === $menu_cat_ids ) {
				$menu_cat_ids = array();
				foreach ( wp_get_nav_menus() as $menu ) {
					foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
						if ( $item && 'taxonomy' === $item->type && 'product_cat' === $item->object ) {
							$menu_cat_ids[] = (int) $item->object_id;
						}
					}
				}
				$menu_cat_ids = array_unique( $menu_cat_ids );
			}
			return ! empty( $menu_cat_ids );
		},
		'check'       => function ( $product ) {
			static $menu_cat_ids = null;
			if ( null === $menu_cat_ids ) {
				$menu_cat_ids = array();
				foreach ( wp_get_nav_menus() as $menu ) {
					foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
						if ( $item && 'taxonomy' === $item->type && 'product_cat' === $item->object ) {
							$menu_cat_ids[] = (int) $item->object_id;
							foreach ( get_term_children( (int) $item->object_id, 'product_cat' ) as $child ) {
								$menu_cat_ids[] = (int) $child;
							}
						}
					}
				}
				$menu_cat_ids = array_unique( $menu_cat_ids );
			}
			$term_ids = array_map( 'intval', $product->get_category_ids( 'edit' ) );
			if ( ! empty( $term_ids ) && empty( array_intersect( $term_ids, $menu_cat_ids ) ) ) {
				return __( 'No assigned category is reachable from a menu', 'wpfactory-catalog-health-scanner-for-woocommerce' );
			}
			return false;
		},
	),

	array(
		'id'          => 'grouped_children_invalid',
		'label'       => __( 'Grouped product with deleted or unpublished children', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Part of this grouped product silently disappeared, so the page sells less than intended.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'fix'         => 'clean_grouped_children',
		'fix_type'    => 'auto',
		'applies'     => function ( $product ) {
			return $product->is_type( 'grouped' );
		},
		'check'       => function ( $product ) {
			$broken = 0;
			foreach ( $product->get_children() as $child_id ) {
				if ( 'product' !== get_post_type( $child_id ) || 'publish' !== get_post_status( $child_id ) ) {
					$broken++;
				}
			}
			if ( $broken > 0 ) {
				return sprintf(
					/* translators: %d: number of broken child product references. */
					__( '%d broken child reference(s)', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					$broken
				);
			}
			return false;
		},
	),

);
