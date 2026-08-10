<?php
/**
 * Catalog Health Scanner for WooCommerce - Applicability Class
 *
 * Decides which check groups apply to this store: auto-detect from store
 * configuration, overridable per group from settings or the setup wizard.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Applicability' ) ) :

class WPFCHS_Applicability {

	/**
	 * Cached resolved states for the current request.
	 *
	 * @var     array
	 * @since   1.0.0
	 */
	protected $resolved = array();

	/**
	 * Returns all applicability group keys.
	 *
	 * Groups not listed here (purchasability, media, structure, content,
	 * pricing basics) are always applicable and always scored.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array
	 */
	function get_groups() {
		return array( 'inventory', 'shipping', 'tax', 'downloads', 'feed', 'cog', 'reviews', 'faq' );
	}

	/**
	 * Returns the stored (raw) state for a group: auto|yes|no|report_only.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $group
	 * @return  string
	 */
	function get_state( $group ) {
		$states = get_option( 'wpfchs_applicability', array() );
		$state  = ( $states[ $group ] ?? 'auto' );
		return ( in_array( $state, array( 'auto', 'yes', 'no', 'report_only' ), true ) ? $state : 'auto' );
	}

	/**
	 * Resolves a group to its effective behaviour.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $group
	 * @return  array {applicable: bool, scored: bool, reason: string, source: string}
	 */
	function resolve( $group ) {

		if ( isset( $this->resolved[ $group ] ) ) {
			return $this->resolved[ $group ];
		}

		if ( ! in_array( $group, $this->get_groups(), true ) ) {
			$result = array(
				'applicable' => true,
				'scored'     => true,
				'reason'     => __( 'Always applies to every store.', 'catalog-health-scanner-for-woocommerce' ),
				'source'     => 'always',
			);
			$this->resolved[ $group ] = $result;
			return $result;
		}

		$state = $this->get_state( $group );

		if ( 'auto' === $state ) {
			$auto   = $this->auto_detect( $group );
			$result = array(
				'applicable' => $auto['applicable'],
				'scored'     => $auto['applicable'],
				'reason'     => $auto['reason'],
				'source'     => 'auto',
			);
		} elseif ( 'yes' === $state ) {
			$result = array(
				'applicable' => true,
				'scored'     => true,
				'reason'     => __( 'Enabled manually in settings.', 'catalog-health-scanner-for-woocommerce' ),
				'source'     => 'manual',
			);
		} elseif ( 'report_only' === $state ) {
			$result = array(
				'applicable' => true,
				'scored'     => false,
				'reason'     => __( 'Checks run and are reported, but stay out of the health score.', 'catalog-health-scanner-for-woocommerce' ),
				'source'     => 'manual',
			);
		} else {
			$result = array(
				'applicable' => false,
				'scored'     => false,
				'reason'     => __( 'Disabled manually in settings.', 'catalog-health-scanner-for-woocommerce' ),
				'source'     => 'manual',
			);
		}

		$result = apply_filters( 'wpfchs_applicability_resolve', $result, $group );

		$this->resolved[ $group ] = $result;
		return $result;

	}

	/**
	 * Auto-detects whether a group applies, from the store's own configuration.
	 *
	 * Results are cached for an hour; `flush_cache()` runs on settings save
	 * and after every completed scan.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $group
	 * @return  array {applicable: bool, reason: string}
	 */
	function auto_detect( $group ) {

		$cache = get_transient( 'wpfchs_autodetect' );
		if ( is_array( $cache ) && isset( $cache[ $group ] ) ) {
			return $cache[ $group ];
		}

		switch ( $group ) {

			case 'inventory':
				$applicable = ( 'yes' === get_option( 'woocommerce_manage_stock', 'yes' ) );
				$reason     = (
					$applicable ?
					__( 'Stock management is enabled store-wide.', 'catalog-health-scanner-for-woocommerce' ) :
					__( 'Stock management is disabled store-wide.', 'catalog-health-scanner-for-woocommerce' )
				);
				break;

			case 'shipping':
				$applicable = $this->store_shipping_uses_dimensions();
				$reason     = (
					$applicable ?
					__( 'An active shipping method appears to use weight or dimensions.', 'catalog-health-scanner-for-woocommerce' ) :
					__( 'Your store uses flat rate, free shipping, or local pickup with no weight-based rules.', 'catalog-health-scanner-for-woocommerce' )
				);
				break;

			case 'tax':
				$applicable = ( wc_tax_enabled() && count( \WC_Tax::get_tax_classes() ) > 0 );
				$reason     = (
					$applicable ?
					__( 'Tax is enabled and more than one tax class exists.', 'catalog-health-scanner-for-woocommerce' ) :
					__( 'Tax is disabled, or only the standard tax class exists.', 'catalog-health-scanner-for-woocommerce' )
				);
				break;

			case 'downloads':
				$applicable = $this->any_product_meta_equals( '_downloadable', 'yes' );
				$reason     = (
					$applicable ?
					__( 'Downloadable products exist in the catalog.', 'catalog-health-scanner-for-woocommerce' ) :
					__( 'No downloadable products found.', 'catalog-health-scanner-for-woocommerce' )
				);
				break;

			case 'feed':
				$applicable = $this->feed_plugin_detected() || $this->any_feed_field_populated();
				$reason     = (
					$applicable ?
					__( 'A product feed plugin was detected, or feed fields are populated on products.', 'catalog-health-scanner-for-woocommerce' ) :
					__( 'No product feed detected.', 'catalog-health-scanner-for-woocommerce' )
				);
				break;

			case 'cog':
				$applicable = $this->any_product_meta_populated( array( '_alg_wc_cog_cost', '_wc_cog_cost' ) );
				$reason     = (
					$applicable ?
					__( 'Cost of goods data is present on products.', 'catalog-health-scanner-for-woocommerce' ) :
					__( 'No cost of goods data found on any product.', 'catalog-health-scanner-for-woocommerce' )
				);
				break;

			case 'reviews':
				$applicable = ( 'yes' === get_option( 'woocommerce_enable_reviews', 'yes' ) );
				$reason     = (
					$applicable ?
					__( 'Product reviews are enabled store-wide.', 'catalog-health-scanner-for-woocommerce' ) :
					__( 'Product reviews are disabled store-wide.', 'catalog-health-scanner-for-woocommerce' )
				);
				break;

			case 'faq':
				$applicable = true;
				$reason     = __( 'Question-and-answer content helps AI assistants answer buyer questions from your product pages.', 'catalog-health-scanner-for-woocommerce' );
				break;

			default:
				$applicable = true;
				$reason     = '';
		}

		$result = apply_filters(
			'wpfchs_applicability_auto_detect',
			array(
				'applicable' => $applicable,
				'reason'     => $reason,
			),
			$group
		);

		$cache           = ( is_array( $cache ) ? $cache : array() );
		$cache[ $group ] = $result;
		set_transient( 'wpfchs_autodetect', $cache, HOUR_IN_SECONDS );

		return $result;

	}

	/**
	 * flush_cache.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function flush_cache() {
		delete_transient( 'wpfchs_autodetect' );
		delete_transient( 'wpfchs_schema_probe' );
		$this->resolved = array();
	}

	/**
	 * Checks whether any enabled shipping method could depend on product
	 * weight or dimensions.
	 *
	 * Core flat rate, free shipping, and local pickup never do; any other
	 * method id (table rates, weight-based plugins, carrier calculators)
	 * is assumed to.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	function store_shipping_uses_dimensions() {

		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return false;
		}

		$dimensionless = apply_filters(
			'wpfchs_dimensionless_shipping_methods',
			array( 'flat_rate', 'free_shipping', 'local_pickup', 'pickup_location' )
		);

		$zones   = \WC_Shipping_Zones::get_zones();
		$zones[] = array( 'shipping_methods' => \WC_Shipping_Zones::get_zone_by( 'zone_id', 0 )->get_shipping_methods() );

		foreach ( $zones as $zone ) {
			foreach ( $zone['shipping_methods'] as $method ) {
				if ( 'yes' !== $method->enabled ) {
					continue;
				}
				if ( ! in_array( $method->id, $dimensionless, true ) ) {
					return true;
				}
			}
		}

		return false;

	}

	/**
	 * Checks whether a known product feed plugin is active.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	function feed_plugin_detected() {

		$signals = apply_filters(
			'wpfchs_feed_plugin_signals',
			array(
				'classes'   => array(
					'\Automattic\WooCommerce\GoogleListingsAndAds\Plugin',
					'Woo_Feed',
					'WooSEA_Get_Products',
					'RexProductFeedRetriever',
				),
				'constants' => array(
					'WOO_FEED_FREE_VERSION',
					'WOOCOMMERCESEA_PLUGIN_VERSION',
					'ALG_WC_PXF_VERSION',
					'REX_PRODUCT_FEED_VERSION',
				),
			)
		);

		foreach ( $signals['classes'] as $class ) {
			if ( class_exists( $class ) ) {
				return true;
			}
		}
		foreach ( $signals['constants'] as $constant ) {
			if ( defined( $constant ) ) {
				return true;
			}
		}

		return false;

	}

	/**
	 * Checks whether any product has a feed identifier field populated.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	function any_feed_field_populated() {
		return $this->any_product_meta_populated(
			apply_filters(
				'wpfchs_feed_meta_keys',
				array( '_global_unique_id', '_alg_ean', '_wpm_gtin_code' )
			)
		);
	}

	/**
	 * Checks whether any product has one of the given meta keys populated.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $meta_keys
	 * @return  bool
	 */
	function any_product_meta_populated( $meta_keys ) {
		foreach ( $meta_keys as $meta_key ) {
			$ids = get_posts(
				array(
					'post_type'      => array( 'product', 'product_variation' ),
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-row existence probe, cached in a transient by the caller.
						array(
							'key'     => $meta_key,
							'value'   => '',
							'compare' => '!=',
						),
					),
				)
			);
			if ( ! empty( $ids ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the store outputs Product structured data (schema.org JSON-LD)
	 * on product pages. Probes one representative published product's rendered
	 * page and caches the result for a day.
	 *
	 * WooCommerce emits this by default, so on most stores the schema check is
	 * a silent no-op; it only fires when a theme or plugin has suppressed it.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	function store_emits_product_schema() {

		$cached = get_transient( 'wpfchs_schema_probe' );
		if ( false !== $cached ) {
			return ( 'yes' === $cached );
		}

		$emits = true; // Assume present; only flip to false on a confident negative.

		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $ids ) ) {
			$url      = get_permalink( (int) $ids[0] );
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 8,
					'redirection' => 3,
					'user-agent'  => 'CatalogHealthScanner/1.0; ' . home_url(),
				)
			);
			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$body = wp_remote_retrieve_body( $response );
				// Look for a JSON-LD block naming the Product type.
				$emits = ( false !== stripos( $body, 'application/ld+json' ) && preg_match( '/"@type"\s*:\s*(\[[^\]]*)?"Product"/i', $body ) );
			}
			// A transport error leaves $emits = true (fail open — never flag a
			// whole catalog because our own HTTP call failed).
		}

		set_transient( 'wpfchs_schema_probe', ( $emits ? 'yes' : 'no' ), DAY_IN_SECONDS );

		return $emits;

	}

	/**
	 * Checks whether any product has the given meta key equal to a value.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $meta_key
	 * @param   string $value
	 * @return  bool
	 */
	function any_product_meta_equals( $meta_key, $value ) {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One-row existence probe, cached in a transient by the caller.
					array(
						'key'   => $meta_key,
						'value' => $value,
					),
				),
			)
		);
		return ! empty( $ids );
	}

}

endif;

return new WPFCHS_Applicability();
