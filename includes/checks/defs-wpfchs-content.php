<?php
/**
 * Catalog Health Scanner for WooCommerce - Check Definitions - Content
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
		'id'          => 'short_description_missing',
		'label'       => __( 'Missing short description', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'The space next to the price and buy button is empty on this product page.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'check'       => function ( $product ) {
			return ( '' === trim( wp_strip_all_tags( $product->get_short_description( 'edit' ) ) ) ? __( 'Empty', 'catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'description_thin',
		'label'       => __( 'Missing or very thin main description', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Thin product pages rank poorly and give customers nothing to base a purchase on.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'check'       => function ( $product ) {
			$text = trim( wp_strip_all_tags( $product->get_description( 'edit' ) ) );
			$min  = (int) wpfchs()->core->get_threshold( 'min_description_chars' );
			if ( mb_strlen( $text ) < $min ) {
				return sprintf(
					/* translators: %1$d: current description length, %2$d: minimum length. */
					__( '%1$d of %2$d characters', 'catalog-health-scanner-for-woocommerce' ),
					mb_strlen( $text ),
					$min
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'title_duplicate',
		'label'       => __( 'Duplicate product titles', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Identically named products compete with each other in search and confuse customers and staff.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'pass'        => 'catalog',
		'check'       => function () {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Catalog-wide title aggregation has no WP API; runs once per scan.
			$rows = $wpdb->get_results(
				"SELECT p.ID, p.post_title
				FROM {$wpdb->posts} p
				WHERE p.post_type = 'product'
					AND p.post_status = 'publish'
					AND LOWER(TRIM(p.post_title)) IN (
						SELECT title FROM (
							SELECT LOWER(TRIM(p2.post_title)) AS title
							FROM {$wpdb->posts} p2
							WHERE p2.post_type = 'product'
								AND p2.post_status = 'publish'
							GROUP BY LOWER(TRIM(p2.post_title))
							HAVING COUNT(*) > 1
						) dupes
					)"
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
		'id'          => 'title_artifacts',
		'label'       => __( 'Product title containing import artefacts or encoding errors', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Garbled characters in the title show on the product page, in search results, and in feeds.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'fix'         => 'clean_title_artifacts',
		'fix_type'    => 'auto',
		'check'       => function ( $product ) {
			$title = $product->get_name( 'edit' );
			// Deliberately conservative: only unambiguous mojibake / double-encoding signatures.
			if ( preg_match( '/â€|Ã©|Ã¨|Ã¼|Ã¶|Ã¤|Ã±|\x{FFFD}|&amp;(amp;|#0?39;|quot;)/u', $title ) ) {
				return $title;
			}
			return false;
		},
	),

	array(
		'id'          => 'schema_markup_missing',
		'label'       => __( 'Product pages emit no structured data (schema.org)', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Without Product structured data, your listings cannot earn Google rich results and are far less likely to be surfaced by AI shopping assistants. This is usually a store-wide theme or SEO setting.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'applies'     => function () {
			// One cached store-level probe decides this for the whole catalog:
			// if the store emits Product JSON-LD, the check is a no-op.
			return ! wpfchs()->core->applicability->store_emits_product_schema();
		},
		'check'       => function ( $product ) {
			// applies() already confirmed the store emits no schema; every
			// scanned product is affected.
			return ( 'publish' === $product->get_status() ? __( 'No Product schema on the page', 'catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'content_placeholder',
		'label'       => __( 'Placeholder or lorem-ipsum content', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'This product still has sample or template text. It reads as unfinished to shoppers and to AI shopping assistants.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'check'       => function ( $product ) {
			$text = strtolower( wp_strip_all_tags( $product->get_description( 'edit' ) . ' ' . $product->get_short_description( 'edit' ) ) );
			$needles = apply_filters(
				'wpfchs_placeholder_phrases',
				array(
					'lorem ipsum',
					'this is a simple product',
					'this is a variable product',
					'this is an external product',
					'this is a grouped product',
					'this is a simple, virtual product',
					'product description goes here',
					'add your description here',
				)
			);
			foreach ( $needles as $needle ) {
				if ( false !== strpos( $text, $needle ) ) {
					return sprintf(
						/* translators: %s: the placeholder phrase found. */
						__( 'Contains "%s"', 'catalog-health-scanner-for-woocommerce' ),
						$needle
					);
				}
			}
			return false;
		},
	),

	array(
		'id'          => 'description_duplicate',
		'label'       => __( 'Description duplicated across multiple products', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Identical descriptions compete with each other in search and give AI answer engines nothing to tell these products apart.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'pass'        => 'catalog',
		'check'       => function () {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Catalog-wide description aggregation has no WP API; runs once per scan.
			$rows = $wpdb->get_results(
				"SELECT p.ID, p.post_content
				FROM {$wpdb->posts} p
				WHERE p.post_type = 'product'
					AND p.post_status = 'publish'
					AND LENGTH( TRIM( p.post_content ) ) > 40"
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$by_hash = array();
			foreach ( (array) $rows as $row ) {
				$normalized = preg_replace( '/\s+/', ' ', strtolower( trim( wp_strip_all_tags( $row->post_content ) ) ) );
				if ( '' === $normalized ) {
					continue;
				}
				$by_hash[ md5( $normalized ) ][] = (int) $row->ID;
			}
			$results = array();
			foreach ( $by_hash as $ids ) {
				if ( count( $ids ) < 2 ) {
					continue;
				}
				foreach ( $ids as $product_id ) {
					$results[ $product_id ] = array(
						'product_id' => $product_id,
						'value'      => sprintf(
							/* translators: %d: number of products sharing the description. */
							__( 'Shared with %d other product(s)', 'catalog-health-scanner-for-woocommerce' ),
							count( $ids ) - 1
						),
					);
				}
			}
			return $results;
		},
	),

	array(
		'id'          => 'no_product_attributes',
		'label'       => __( 'No attributes or specifications', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Without structured attributes (material, size, colour…), filtered navigation and AI shopping assistants have no facts to match this product against a query.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'applies'     => function ( $product ) {
			return $product->is_type( array( 'simple', 'variable', 'external' ) );
		},
		'check'       => function ( $product ) {
			return ( empty( $product->get_attributes() ) ? __( 'No attributes set', 'catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'reviews_missing',
		'label'       => __( 'No reviews', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Products without reviews lack the star ratings that shoppers and AI assistants use as social proof, and miss the review rich-result in search.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'low',
		'group'       => 'reviews',
		'applies'     => function ( $product ) {
			return ( 'yes' === get_option( 'woocommerce_enable_reviews', 'yes' ) && $product->get_reviews_allowed() );
		},
		'check'       => function ( $product ) {
			return ( (int) $product->get_review_count() < 1 ? __( 'No reviews yet', 'catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'faq_missing',
		'label'       => __( 'No FAQ / question-and-answer content', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'FAQ content is what AI answer engines quote directly. Products without it are far less likely to be surfaced in AI shopping answers.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'low',
		'group'       => 'faq',
		'applies'     => function () {
			// Only meaningful once the store actually uses FAQ content somewhere.
			static $in_use = null;
			if ( null === $in_use ) {
				$in_use = wpfchs()->core->applicability->any_product_meta_populated(
					apply_filters( 'wpfchs_faq_meta_keys', array( '_wpfchs_faq', '_yoast_wpseo_faq', 'product_faqs' ) )
				);
			}
			return $in_use;
		},
		'check'       => function ( $product ) {
			foreach ( apply_filters( 'wpfchs_faq_meta_keys', array( '_wpfchs_faq', '_yoast_wpseo_faq', 'product_faqs' ) ) as $meta_key ) {
				if ( '' !== (string) get_post_meta( $product->get_id(), $meta_key, true ) ) {
					return false;
				}
			}
			// A description that already answers questions counts as FAQ content.
			$text = wp_strip_all_tags( $product->get_description( 'edit' ) );
			if ( preg_match( '/\b(FAQ|frequently asked|Q&A|Q:\s|question)/i', $text ) ) {
				return false;
			}
			return __( 'No FAQ content', 'catalog-health-scanner-for-woocommerce' );
		},
	),

	array(
		'id'          => 'broken_shortcodes',
		'label'       => __( 'Description containing broken shortcodes or leftover markup', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Raw shortcode text from a removed plugin is printed to customers instead of content.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'check'       => function ( $product ) {
			$text = $product->get_description( 'edit' ) . ' ' . $product->get_short_description( 'edit' );
			if ( ! preg_match_all( '/\[([a-zA-Z0-9_-]+)(?:\s[^\]]*)?\]/', $text, $matches ) ) {
				return false;
			}
			$unknown = array();
			foreach ( array_unique( $matches[1] ) as $tag ) {
				if ( ! shortcode_exists( $tag ) ) {
					$unknown[] = '[' . $tag . ']';
				}
			}
			return ( ! empty( $unknown ) ? implode( ' ', array_slice( $unknown, 0, 5 ) ) : false );
		},
	),

);
