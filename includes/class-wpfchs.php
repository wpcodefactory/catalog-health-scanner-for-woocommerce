<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - Main Class
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS' ) ) :

final class WPFCHS {

	/**
	 * Plugin version.
	 *
	 * @var     string
	 * @since   1.0.0
	 */
	public $version = WPFCHS_VERSION;

	/**
	 * core.
	 *
	 * @var     WPFCHS_Core
	 * @since   1.0.0
	 */
	public $core;

	/**
	 * pro.
	 *
	 * @var     WPFCHS_Pro
	 * @since   1.0.0
	 */
	public $pro;

	/**
	 * The single instance of the class.
	 *
	 * @var     WPFCHS
	 * @since   1.0.0
	 */
	protected static $_instance = null;

	/**
	 * Main WPFCHS Instance.
	 *
	 * Ensures only one instance of WPFCHS is loaded or can be loaded.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @static
	 * @return  WPFCHS - Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * WPFCHS Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @access  public
	 */
	function __construct() {

		// Check for active plugins
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		// Load libs
		if ( is_admin() && file_exists( plugin_dir_path( WPFCHS_FILE ) . 'vendor/autoload.php' ) ) {
			require_once plugin_dir_path( WPFCHS_FILE ) . 'vendor/autoload.php';
		}

		// Declare compatibility with custom order tables for WooCommerce
		add_action( 'before_woocommerce_init', array( $this, 'wc_declare_compatibility' ) );

		// Pro
		if ( 'wpfactory-catalog-health-scanner-for-woocommerce-pro.php' === basename( WPFCHS_FILE ) ) {
			$this->pro = require_once plugin_dir_path( __FILE__ ) . 'pro/class-wpfchs-pro.php';
		}

		// Include required files
		$this->includes();

		// Admin
		if ( is_admin() ) {
			$this->admin();
		}

	}

	/**
	 * wc_declare_compatibility.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @see     https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/
	 */
	function wc_declare_compatibility() {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}
		$files = (
			defined( 'WPFCHS_FILE_FREE' ) ?
			array( WPFCHS_FILE, WPFCHS_FILE_FREE ) :
			array( WPFCHS_FILE )
		);
		foreach ( $files as $file ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				$file,
				true
			);
		}
	}

	/**
	 * includes.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function includes() {
		// Core
		$this->core = require_once plugin_dir_path( __FILE__ ) . 'class-wpfchs-core.php';
	}

	/**
	 * admin.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function admin() {

		// Action links
		add_filter(
			'plugin_action_links_' . plugin_basename( WPFCHS_FILE ),
			array( $this, 'action_links' )
		);

		// "Recommendations" page
		add_action( 'init', array( $this, 'add_cross_selling_library' ) );

		// Shared "WPFactory" top-level menu. Must be instantiated before
		// `admin_menu` runs — the library registers the parent menu there at
		// priority 9, and our own screens attach to it at the default 10.
		add_action( 'init', array( $this, 'add_wpfactory_admin_menu' ) );

		// Version update
		if ( get_option( 'wpfchs_version', '' ) !== $this->version ) {
			add_action( 'admin_init', array( $this, 'version_updated' ) );
		}

	}

	/**
	 * action_links.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   mixed $links
	 * @return  array
	 */
	function action_links( $links ) {
		$custom_links = array();

		$custom_links[] = '<a' .
			' href="' . admin_url( 'admin.php?page=wpfchs' ) . '"' .
		'>' .
			__( 'Dashboard', 'wpfactory-catalog-health-scanner-for-woocommerce' ) .
		'</a>';

		$custom_links[] = '<a' .
			' href="' . admin_url( 'admin.php?page=wpfchs-settings' ) . '"' .
		'>' .
			__( 'Settings', 'wpfactory-catalog-health-scanner-for-woocommerce' ) .
		'</a>';

		return array_merge( $custom_links, $links );
	}

	/**
	 * add_cross_selling_library.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function add_cross_selling_library() {

		if ( ! class_exists( '\WPFactory\WPFactory_Cross_Selling\WPFactory_Cross_Selling' ) ) {
			return;
		}

		$cross_selling = new \WPFactory\WPFactory_Cross_Selling\WPFactory_Cross_Selling();
		$cross_selling->setup( array( 'plugin_file_path' => WPFCHS_FILE ) );
		$cross_selling->init();

	}

	/**
	 * Instantiates the shared WPFactory admin menu, so every WPFactory plugin
	 * on the site collects under one top-level item instead of each adding its
	 * own. Merely getting the instance is what registers the parent menu.
	 *
	 * Unlike most WPFactory plugins this one does not put its settings in a
	 * WooCommerce settings tab, so `move_wc_settings_tab_to_wpfactory_menu()`
	 * does not apply — the screens are attached directly in
	 * `WPFCHS_Admin::add_menu()` instead.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function add_wpfactory_admin_menu() {

		if ( ! class_exists( '\WPFactory\WPFactory_Admin_Menu\WPFactory_Admin_Menu' ) ) {
			return;
		}

		\WPFactory\WPFactory_Admin_Menu\WPFactory_Admin_Menu::get_instance();

	}

	/**
	 * Whether the shared WPFactory menu is available to attach to.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	function has_wpfactory_admin_menu() {
		return class_exists( '\WPFactory\WPFactory_Admin_Menu\WPFactory_Admin_Menu' );
	}

	/**
	 * version_updated.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function version_updated() {
		require_once plugin_dir_path( __FILE__ ) . 'class-wpfchs-install.php';
		WPFCHS_Install::install();
		do_action( 'wpfchs_before_version_update', $this->version );
		update_option( 'wpfchs_version', $this->version );
		do_action( 'wpfchs_version_updated', $this->version );
	}

	/**
	 * plugin_url.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function plugin_url() {
		return untrailingslashit( plugin_dir_url( WPFCHS_FILE ) );
	}

	/**
	 * plugin_path.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function plugin_path() {
		return untrailingslashit( plugin_dir_path( WPFCHS_FILE ) );
	}

	/**
	 * Handles plugin activation tasks.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	static function activate() {
		require_once plugin_dir_path( __FILE__ ) . 'class-wpfchs-install.php';
		WPFCHS_Install::install();
		if ( ! get_option( 'wpfchs_setup_complete', false ) ) {
			update_option( 'wpfchs_activation_redirect', 'yes' );
		}
	}

}

endif;
