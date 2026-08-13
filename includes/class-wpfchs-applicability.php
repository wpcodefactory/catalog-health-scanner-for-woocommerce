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
		return array( 'selling', 'sku', 'inventory', 'shipping', 'shipping_class', 'tax', 'tax_status', 'downloads', 'feed', 'cog', 'reviews', 'faq' );
	}

	/**
	 * Human label for a group — one source for the settings table, the
	 * dashboard, and the PDF's excluded-groups section.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $group
	 * @return  string
	 */
	function get_group_label( $group ) {
		$labels = array(
			'selling'        => __( 'Direct selling (prices & purchasability)', 'catalog-health-scanner-for-woocommerce' ),
			'sku'            => __( 'SKUs', 'catalog-health-scanner-for-woocommerce' ),
			'inventory'      => __( 'Stock levels', 'catalog-health-scanner-for-woocommerce' ),
			'shipping'       => __( 'Shipping weight & dimensions', 'catalog-health-scanner-for-woocommerce' ),
			'shipping_class' => __( 'Shipping classes', 'catalog-health-scanner-for-woocommerce' ),
			'tax'            => __( 'Tax classes', 'catalog-health-scanner-for-woocommerce' ),
			'tax_status'     => __( 'Tax status', 'catalog-health-scanner-for-woocommerce' ),
			'downloads'      => __( 'Downloads', 'catalog-health-scanner-for-woocommerce' ),
			'feed'           => __( 'Product feed readiness', 'catalog-health-scanner-for-woocommerce' ),
			'cog'            => __( 'Cost of goods & margin', 'catalog-health-scanner-for-woocommerce' ),
			'reviews'        => __( 'Product reviews', 'catalog-health-scanner-for-woocommerce' ),
			'faq'            => __( 'FAQ / Q&A content', 'catalog-health-scanner-for-woocommerce' ),
		);
		return ( $labels[ $group ] ?? $group );
	}

	/**
	 * Snapshot of every group's resolution, stored into a scan's score_data
	 * at finish so a report of that scan can explain what was excluded and
	 * why, exactly as it stood when the scan ran.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array group => {applicable, scored, reason, source}
	 */
	function snapshot() {
		$snapshot = array();
		foreach ( $this->get_groups() as $group ) {
			$snapshot[ $group ] = $this->resolve( $group );
		}
		return $snapshot;
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

			case 'selling':
				$signal     = $this->selling_signal();
				$applicable = ( 'selling' === $signal['result'] );
				$reason     = $signal['reason'];
				break;

			case 'sku':
				// SKU checks are governed by whether the store uses SKUs at
				// all — NOT by stock management. A store that turns stock
				// management off still needs unique SKUs for feeds, imports,
				// and order management, and hiding a duplicate-SKU critical
				// behind an unrelated setting is a false negative.
				$sku_count  = $this->count_products_with_sku();
				$applicable = ( $sku_count > 0 );
				$reason     = (
					$applicable ?
					sprintf(
						/* translators: %s: number of products carrying a SKU. */
						_n( '%s product in this catalog carries a SKU, so SKU checks apply.', '%s products in this catalog carry a SKU, so SKU checks apply.', $sku_count, 'catalog-health-scanner-for-woocommerce' ),
						number_format_i18n( $sku_count )
					) :
					__( 'No product in this catalog carries a SKU, so this store does not appear to use them.', 'catalog-health-scanner-for-woocommerce' )
				);
				break;

			case 'inventory':
				$applicable = ( 'yes' === get_option( 'woocommerce_manage_stock', 'yes' ) );
				$reason     = (
					$applicable ?
					__( 'Stock management is enabled store-wide.', 'catalog-health-scanner-for-woocommerce' ) :
					__( 'Stock management is disabled store-wide (WooCommerce › Settings › Products › Inventory).', 'catalog-health-scanner-for-woocommerce' )
				);
				break;

			case 'shipping':
				$methods    = $this->get_enabled_shipping_methods();
				$applicable = $this->store_shipping_uses_dimensions();
				$reason     = (
					$applicable ?
					sprintf(
						/* translators: %s: shipping method title. */
						__( 'Your store uses %s, which can price by weight or dimensions.', 'catalog-health-scanner-for-woocommerce' ),
						$this->list_method_titles( $methods, true )
					) :
					(
						empty( $methods ) ?
						__( 'No shipping method is enabled in any zone, so nothing prices by weight.', 'catalog-health-scanner-for-woocommerce' ) :
						sprintf(
							/* translators: %s: shipping method titles. */
							_n( 'Your only shipping method is %s, which does not price by weight or dimensions.', 'Your shipping methods are %s, none of which price by weight or dimensions.', count( $methods ), 'catalog-health-scanner-for-woocommerce' ),
							$this->list_method_titles( $methods )
						)
					)
				);
				break;

			case 'shipping_class':
				// Independent of the weight condition — a flat-rate store can
				// still price per class — but NOT unconditional. A missing
				// shipping class costs nothing unless some enabled rate
				// actually charges by class, so the check needs all three:
				// classes defined, a method enabled, and that method pricing
				// by class.
				$class_count = (int) wp_count_terms(
					array(
						'taxonomy'   => 'product_shipping_class',
						'hide_empty' => false,
					)
				);
				$methods    = $this->get_enabled_shipping_methods();
				$class_rate = $this->shipping_class_rate_in_use();
				$applicable = ( $class_count > 0 && ! empty( $methods ) && false !== $class_rate );
				if ( $applicable ) {
					$reason = sprintf(
						/* translators: %1$s: number of shipping classes, %2$s: shipping method title. */
						_n( '%1$s shipping class is defined and %2$s charges by class.', '%1$s shipping classes are defined and %2$s charges by class.', $class_count, 'catalog-health-scanner-for-woocommerce' ),
						number_format_i18n( $class_count ),
						$class_rate
					);
				} elseif ( 0 === $class_count ) {
					$reason = __( 'No shipping class is defined in this store.', 'catalog-health-scanner-for-woocommerce' );
				} elseif ( empty( $methods ) ) {
					$reason = __( 'No shipping method is enabled in any zone, so a shipping class cannot affect any rate.', 'catalog-health-scanner-for-woocommerce' );
				} else {
					$reason = sprintf(
						/* translators: %s: shipping method titles. */
						__( 'Shipping classes exist, but no enabled rate (%s) charges by class.', 'catalog-health-scanner-for-woocommerce' ),
						$this->list_method_titles( $methods )
					);
				}
				break;

			case 'tax':
				// Tax CLASS checks need more than the standard class.
				$classes    = \WC_Tax::get_tax_classes();
				$applicable = ( wc_tax_enabled() && count( $classes ) > 0 );
				$reason     = (
					$applicable ?
					sprintf(
						/* translators: %s: comma-separated additional tax class names. */
						__( 'Tax is enabled and this store defines additional tax classes (%s).', 'catalog-health-scanner-for-woocommerce' ),
						implode( ', ', array_slice( $classes, 0, 4 ) )
					) :
					(
						! wc_tax_enabled() ?
						__( 'Tax is disabled store-wide (WooCommerce › Settings › General).', 'catalog-health-scanner-for-woocommerce' ) :
						__( 'Only the standard tax class exists in this store.', 'catalog-health-scanner-for-woocommerce' )
					)
				);
				break;

			case 'tax_status':
				// "Tax status: none" under-collects tax whenever tax is on,
				// with or without additional tax classes.
				$applicable = wc_tax_enabled();
				$reason     = (
					$applicable ?
					__( 'Tax is enabled store-wide, so a product set to "none" collects no tax.', 'catalog-health-scanner-for-woocommerce' ) :
					__( 'Tax is disabled store-wide (WooCommerce › Settings › General).', 'catalog-health-scanner-for-woocommerce' )
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
				$feed_plugin = $this->feed_plugin_detected();
				$feed_fields = ( ! $feed_plugin && $this->any_feed_field_populated() );
				$applicable  = ( $feed_plugin || $feed_fields );
				$reason      = (
					$feed_plugin ?
					__( 'A product feed plugin is active on this site.', 'catalog-health-scanner-for-woocommerce' ) :
					(
						$feed_fields ?
						__( 'Products in this catalog already carry feed fields such as GTIN or brand.', 'catalog-health-scanner-for-woocommerce' ) :
						__( 'No feed plugin is active and no product carries feed fields.', 'catalog-health-scanner-for-woocommerce' )
					)
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
	 * Whether customers buy directly on this site, as opposed to a catalog or
	 * quote-based store that displays products without selling them.
	 *
	 * Two independent signals, either one flips the answer to "not selling":
	 *
	 * 1. A catalog-mode or request-a-quote plugin is active. Matching on slug
	 *    substrings covers the whole family (YITH, Webkul, Barn2, ELEX, …)
	 *    without maintaining a plugin list.
	 * 2. Price coverage: when fewer than 20% of published products carry a
	 *    price — measured on a catalog big enough to mean something — the
	 *    store is displaying products, not selling them. A store that simply
	 *    forgot prices on a handful of products stays "selling", which is
	 *    exactly when the no-price checks should fire.
	 *
	 * Fails open: an empty or tiny catalog counts as selling.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	function store_sells_directly() {
		return ( 'selling' === $this->selling_signal()['result'] );
	}

	/**
	 * The selling decision together with the signal that produced it, so the
	 * reason can name what was actually detected rather than list what it
	 * might have been.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array {result: 'selling'|'catalog', reason: string}
	 */
	function selling_signal() {
		global $wpdb;

		foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
			foreach ( array( 'catalog-mode', 'catalogue-mode', 'request-a-quote', 'catalog-enquiry', 'quote-request' ) as $needle ) {
				if ( false !== stripos( (string) $plugin, $needle ) ) {
					$name = ( function_exists( 'get_plugin_data' ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin ) )
						? (string) ( get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin, false, false )['Name'] ?? '' )
						: '';
					if ( '' === $name ) {
						$name = dirname( (string) $plugin );
					}
					return array(
						'result' => 'catalog',
						'reason' => sprintf(
							/* translators: %s: plugin name. */
							__( '%s is active, which turns this site into a catalog rather than a checkout.', 'catalog-health-scanner-for-woocommerce' ),
							$name
						),
					);
				}
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Two aggregate counts; the caller caches the result for an hour.
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
		);
		if ( $total < 10 ) {
			return array(
				'result' => 'selling',
				'reason' => __( 'This catalog is too small to infer catalog mode from, so price checks stay on.', 'catalog-health-scanner-for-woocommerce' ),
			);
		}
		$priced = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_price' AND pm.meta_value != ''
			WHERE p.post_type = 'product' AND p.post_status = 'publish'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$percent = (int) round( ( $priced / max( 1, $total ) ) * 100 );

		if ( ( $priced / $total ) >= 0.2 ) {
			return array(
				'result' => 'selling',
				'reason' => sprintf(
					/* translators: %1$s: percentage, %2$s: priced count, %3$s: total products. */
					__( '%1$s%% of published products carry a price (%2$s of %3$s), so customers buy directly here.', 'catalog-health-scanner-for-woocommerce' ),
					number_format_i18n( $percent ),
					number_format_i18n( $priced ),
					number_format_i18n( $total )
				),
			);
		}

		return array(
			'result' => 'catalog',
			'reason' => sprintf(
				/* translators: %1$s: priced count, %2$s: total products, %3$s: percentage. */
				__( 'Only %1$s of %2$s published products carry a price (%3$s%%), which reads as a catalog rather than a checkout.', 'catalog-health-scanner-for-woocommerce' ),
				number_format_i18n( $priced ),
				number_format_i18n( $total ),
				number_format_i18n( $percent )
			),
		);
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

		$dimensionless = apply_filters(
			'wpfchs_dimensionless_shipping_methods',
			array( 'flat_rate', 'free_shipping', 'local_pickup', 'pickup_location' )
		);

		foreach ( $this->get_enabled_shipping_methods() as $method ) {
			if ( ! in_array( $method['id'], $dimensionless, true ) ) {
				return true;
			}
		}

		return false;

	}

	/**
	 * Every enabled shipping method across all zones, deduplicated by method
	 * id — the raw material for naming which method was actually detected
	 * rather than listing the ones it might have been.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array [{id, title}]
	 */
	function get_enabled_shipping_methods() {

		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return array();
		}

		$zones = \WC_Shipping_Zones::get_zones();
		$zone0 = \WC_Shipping_Zones::get_zone_by( 'zone_id', 0 );
		if ( $zone0 ) {
			$zones[] = array( 'shipping_methods' => $zone0->get_shipping_methods() );
		}

		$found = array();
		foreach ( $zones as $zone ) {
			foreach ( (array) ( $zone['shipping_methods'] ?? array() ) as $method ) {
				if ( 'yes' !== $method->enabled || isset( $found[ $method->id ] ) ) {
					continue;
				}
				$title              = ( method_exists( $method, 'get_method_title' ) ? $method->get_method_title() : $method->id );
				$found[ $method->id ] = array(
					'id'    => $method->id,
					'title' => ( '' !== $title ? $title : $method->id ),
				);
			}
		}

		return array_values( $found );

	}

	/**
	 * Formats detected shipping method titles for an applicability reason.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $methods       From get_enabled_shipping_methods().
	 * @param   bool  $dimensional   Name only the weight-capable methods.
	 * @return  string
	 */
	protected function list_method_titles( $methods, $dimensional = false ) {

		$dimensionless = apply_filters(
			'wpfchs_dimensionless_shipping_methods',
			array( 'flat_rate', 'free_shipping', 'local_pickup', 'pickup_location' )
		);

		$titles = array();
		foreach ( $methods as $method ) {
			if ( $dimensional && in_array( $method['id'], $dimensionless, true ) ) {
				continue;
			}
			$titles[] = $method['title'];
		}

		if ( empty( $titles ) ) {
			return __( 'a custom shipping method', 'catalog-health-scanner-for-woocommerce' );
		}

		return implode( ', ', array_slice( $titles, 0, 3 ) );

	}

	/**
	 * Whether an enabled shipping rate actually prices by shipping class.
	 *
	 * Core flat rate stores per-class costs as `class_cost_<term_id>` in its
	 * instance settings; `no_class_cost` alone is not class pricing. Any
	 * non-core method (table rates, carrier plugins) is assumed to support
	 * classes, since we cannot read its schema — failing open here risks a
	 * false positive on one check, while failing closed would suppress a real
	 * finding on every store using a third-party shipping plugin.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string|false Method title that prices by class, or false.
	 */
	function shipping_class_rate_in_use() {

		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			return false;
		}

		$core_methods = array( 'flat_rate', 'free_shipping', 'local_pickup', 'pickup_location' );

		$zones = \WC_Shipping_Zones::get_zones();
		$zone0 = \WC_Shipping_Zones::get_zone_by( 'zone_id', 0 );
		if ( $zone0 ) {
			$zones[] = array( 'shipping_methods' => $zone0->get_shipping_methods() );
		}

		foreach ( $zones as $zone ) {
			foreach ( (array) ( $zone['shipping_methods'] ?? array() ) as $method ) {

				if ( 'yes' !== $method->enabled ) {
					continue;
				}

				$title = ( method_exists( $method, 'get_method_title' ) ? $method->get_method_title() : $method->id );

				// A method we cannot introspect may well price by class.
				if ( ! in_array( $method->id, $core_methods, true ) ) {
					return $title;
				}

				if ( 'flat_rate' !== $method->id ) {
					continue; // Free shipping and local pickup never use classes.
				}

				$settings = (array) get_option( 'woocommerce_flat_rate_' . $method->instance_id . '_settings', array() );
				foreach ( $settings as $key => $value ) {
					if ( 0 === strpos( $key, 'class_cost_' ) && '' !== trim( (string) $value ) ) {
						return $title;
					}
				}
			}
		}

		return false;

	}

	/**
	 * Number of published products carrying a non-empty SKU.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  int
	 */
	function count_products_with_sku() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One aggregate count; the caller caches the result for an hour.
		return (int) $wpdb->get_var(
			"SELECT COUNT(*)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_sku' AND pm.meta_value != ''
			WHERE p.post_type IN ( 'product', 'product_variation' ) AND p.post_status = 'publish'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
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
