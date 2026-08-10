<?php
/**
 * Plugin Name: Catalog Health Scanner for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/catalog-health-scanner-for-woocommerce/
 * Description: Scan your WooCommerce catalog and find the products that are silently costing you sales.
 * Version: 1.0.0
 * Author: WPFactory
 * Author URI: https://wpfactory.com
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: catalog-health-scanner-for-woocommerce
 * Domain Path: /langs
 * WC tested up to: 10.9
 * Requires Plugins: woocommerce
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package WPFactory\Catalog_Health_Scanner_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

if ( 'catalog-health-scanner-for-woocommerce.php' === basename( __FILE__ ) ) {
	/**
	 * Check if Pro plugin version is activated.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	$plugin = 'catalog-health-scanner-for-woocommerce-pro/catalog-health-scanner-for-woocommerce-pro.php';
	if (
		in_array( $plugin, (array) get_option( 'active_plugins', array() ), true ) ||
		(
			is_multisite() &&
			array_key_exists( $plugin, (array) get_site_option( 'active_sitewide_plugins', array() ) )
		)
	) {
		defined( 'WPFCHS_FILE_FREE' ) || define( 'WPFCHS_FILE_FREE', __FILE__ );
		return;
	}
}

defined( 'WPFCHS_VERSION' ) || define( 'WPFCHS_VERSION', '1.0.0' );

defined( 'WPFCHS_FILE' ) || define( 'WPFCHS_FILE', __FILE__ );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-wpfchs.php';

if ( ! function_exists( 'wpfchs' ) ) {
	/**
	 * Returns the main instance of WPFCHS to prevent the need to use globals.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  WPFCHS
	 */
	function wpfchs() {
		return WPFCHS::instance();
	}
}

add_action( 'plugins_loaded', 'wpfchs' );

/**
 * Registers the plugin activation hook.
 *
 * @version 1.0.0
 * @since   1.0.0
 */
register_activation_hook( WPFCHS_FILE, 'WPFCHS::activate' );
