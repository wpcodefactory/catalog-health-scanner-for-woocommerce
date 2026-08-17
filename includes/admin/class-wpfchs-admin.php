<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - Admin Class
 *
 * Top-level menu, screen routing, assets, and shared render helpers.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Admin' ) ) :

class WPFCHS_Admin {

	/**
	 * dashboard.
	 *
	 * @var     WPFCHS_Admin_Dashboard
	 * @since   1.0.0
	 */
	public $dashboard;

	/**
	 * category.
	 *
	 * @var     WPFCHS_Admin_Category
	 * @since   1.0.0
	 */
	public $category;

	/**
	 * history.
	 *
	 * @var     WPFCHS_Admin_History
	 * @since   1.0.0
	 */
	public $history;

	/**
	 * profiles.
	 *
	 * @var     WPFCHS_Admin_Profiles
	 * @since   1.0.0
	 */
	public $profiles;

	/**
	 * settings.
	 *
	 * @var     WPFCHS_Admin_Settings
	 * @since   1.0.0
	 */
	public $settings;

	/**
	 * wizard.
	 *
	 * @var     WPFCHS_Admin_Wizard
	 * @since   1.0.0
	 */
	public $wizard;

	/**
	 * products.
	 *
	 * @var     WPFCHS_Admin_Products
	 * @since   1.0.0
	 */
	public $products;

	/**
	 * Slugs registered for capability resolution but hidden from the menu.
	 *
	 * @var     array
	 * @since   1.0.0
	 */
	protected $hidden_submenus = array();

	/**
	 * Parent slug the screens were attached to: the shared WPFactory menu when
	 * available, this plugin's own otherwise.
	 *
	 * @var     string
	 * @since   1.0.0
	 */
	protected $menu_parent = '';

	/**
	 * Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function __construct() {

		$path = plugin_dir_path( __FILE__ );

		$this->dashboard = require_once $path . 'class-wpfchs-admin-dashboard.php';
		$this->category  = require_once $path . 'class-wpfchs-admin-category.php';
		$this->history   = require_once $path . 'class-wpfchs-admin-history.php';
		$this->profiles  = require_once $path . 'class-wpfchs-admin-profiles.php';
		$this->settings  = require_once $path . 'class-wpfchs-admin-settings.php';
		$this->wizard    = require_once $path . 'class-wpfchs-admin-wizard.php';
		$this->products  = require_once $path . 'class-wpfchs-admin-products.php';

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_head', array( $this, 'hide_secondary_submenus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_wizard' ) );

	}

	/**
	 * add_menu.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function add_menu() {

		$capability = wpfchs()->core->get_capability();

		// Under the shared "WPFactory" menu when that library is present, so
		// the site gets one WPFactory item rather than one per plugin. Only
		// the dashboard is listed there — every other screen is reachable from
		// this plugin's own tab bar, and adding four entries would bury the
		// other WPFactory plugins alongside it.
		$parent = 'wpfchs';

		if ( wpfchs()->has_wpfactory_admin_menu() ) {

			$parent = \WPFactory\WPFactory_Admin_Menu\WPFactory_Admin_Menu::get_instance()->get_menu_slug();

			add_submenu_page(
				$parent,
				__( 'Catalog Health Scanner', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				__( 'Catalog Health', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				$capability,
				'wpfchs',
				array( $this, 'render_main_page' )
			);

		} else {

			// Standalone fallback: no WPFactory menu to join, so keep our own.
			add_menu_page(
				__( 'Catalog Health Scanner', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				__( 'Catalog Health', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				$capability,
				'wpfchs',
				array( $this, 'render_main_page' ),
				'dashicons-yes-alt',
				56
			);

			add_submenu_page(
				'wpfchs',
				__( 'Catalog Health Scanner', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				__( 'Dashboard', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				$capability,
				'wpfchs',
				array( $this, 'render_main_page' )
			);

		}

		// The remaining screens are registered against the same parent so that
		// WordPress can resolve their capability. They are hidden from the menu
		// afterwards rather than registered with a null parent, because the tab
		// bar is what navigates between them.
		//
		// Note this is NOT remove_submenu_page(): that unregisters the entry
		// `user_can_access_admin_page()` reads the capability from and makes
		// the page 403 for everyone — the "Sorry, you are not allowed to access
		// this page" bug hit earlier on the setup wizard.
		$hidden = array(
			'wpfchs-profiles' => array(
				__( 'Scan Profiles', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				array( $this->profiles, 'render_page' ),
			),
			'wpfchs-settings' => array(
				__( 'Settings', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				array( $this->settings, 'render_page' ),
			),
			'wpfchs-setup'    => array(
				__( 'Catalog Health Scanner Setup', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				array( $this->wizard, 'render_page' ),
			),
		);

		foreach ( $hidden as $slug => $screen ) {
			add_submenu_page( $parent, $screen[0], $screen[0], $capability, $slug, $screen[1] );
		}

		$this->hidden_submenus = array_keys( $hidden );
		$this->menu_parent     = $parent;

	}

	/**
	 * Drops the secondary screens out of the menu display without touching the
	 * registration WordPress uses for capability checks.
	 *
	 * Runs on `admin_head`, i.e. after `user_can_access_admin_page()` has
	 * already done its work for this request.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function hide_secondary_submenus() {
		global $submenu;

		if ( empty( $this->menu_parent ) || empty( $submenu[ $this->menu_parent ] ) ) {
			return;
		}

		foreach ( $submenu[ $this->menu_parent ] as $index => $item ) {
			if ( in_array( $item[2], (array) $this->hidden_submenus, true ) ) {
				unset( $submenu[ $this->menu_parent ][ $index ] );
			}
		}

	}

	/**
	 * Whether the current screen belongs to the plugin.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	function is_plugin_screen() {
		$page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS );
		return ( is_string( $page ) && 0 === strpos( $page, 'wpfchs' ) );
	}

	/**
	 * enqueue_assets.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $hook_suffix
	 */
	function enqueue_assets( $hook_suffix ) {

		$screen           = get_current_screen();
		$screen_post_type = ( $screen ? $screen->post_type : '' );
		$is_products_list = ( 'edit.php' === $hook_suffix && 'product' === $screen_post_type );
		$is_product_edit  = ( in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) && 'product' === $screen_post_type );

		if ( ! $this->is_plugin_screen() && ! $is_products_list && ! $is_product_edit ) {
			return;
		}

		$min = ( defined( 'WP_DEBUG' ) && WP_DEBUG ? '' : '.min' );

		// The settings screen uses the media library for the report logo.
		if ( 'wpfchs-settings' === filter_input( INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS ) ) {
			wp_enqueue_media();
		}

		wp_enqueue_style(
			'wpfchs-admin',
			wpfchs()->plugin_url() . '/assets/css/wpfchs-admin' . $min . '.css',
			array(),
			$this->asset_version( 'assets/css/wpfchs-admin' . $min . '.css' )
		);

		wp_enqueue_script(
			'wpfchs-admin',
			wpfchs()->plugin_url() . '/assets/js/wpfchs-admin' . $min . '.js',
			array( 'jquery' ),
			$this->asset_version( 'assets/js/wpfchs-admin' . $min . '.js' ),
			true
		);

		wp_localize_script(
			'wpfchs-admin',
			'wpfchs_admin',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wpfchs-admin' ),
				// Presentation only — every Pro feature is enforced
				// server-side. This just decides whether a wall is drawn
				// before the request instead of after it.
				'i18n'     => array(
					'scanning'       => __( 'Scanning…', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'scan_complete'  => __( 'Scan complete.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'scan_failed'    => __( 'The scan could not continue. You can resume it from the dashboard.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'confirm_cancel' => __( 'Cancel this scan? Progress so far is kept.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'ignored'        => __( 'Ignored. This check will no longer flag this product.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'restored'       => __( 'Restored.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'fixed'          => __( 'Fixed.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'undo'           => __( 'Undo', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'undone'         => __( 'Fix undone. The issues are open again.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'error'          => __( 'Something went wrong. Please try again.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'loading'        => __( 'Loading…', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					/* translators: %d: number of products the fix will be applied to. */
					'apply_to'       => __( 'Apply to %d products', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'no_selection'   => __( 'Select at least one product first.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'cancel'         => __( 'Cancel', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					/* translators: %d: number of rows currently selected. */
					'selected'       => __( '%d selected', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'confirm_delete_profile' => __( 'Delete this profile? Scans that used it keep their history.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'confirm_reset'  => __( 'Reset all settings and re-run the setup wizard? Scan history is kept.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'confirm_fix_all' => __( 'Preview and apply all auto-fixable quick wins?', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'fixing'         => __( 'Applying fixes…', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'choose_logo'    => __( 'Choose report logo', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'use_logo'       => __( 'Use this logo', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'apply_fixes'    => __( 'Apply all fixes', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'unlock'         => __( 'Unlock with Pro', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					/* translators: %s: feature name. */
					'pro_feature'    => __( '%s is a Pro feature', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'pro_generic'    => __( 'This feature', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'pro_body'       => __( 'Available in the Pro version, along with bulk fixing, scheduled scans, email alerts, scan comparison, and the white-label PDF audit report.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					'pro_free_hint'  => __( 'The free version fixes one product at a time. Pro applies a reviewed fix to every affected product in one click.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					/* translators: %d: number of selected products that will be ignored. */
					'confirm_ignore_selected'  => __( 'Ignore %d selected products for this check? They will count as passing until you restore them from Settings.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					/* translators: %d: total number of products that will be ignored. */
					'confirm_ignore_all'       => __( 'Ignore all %d products for this check? They will count as passing until you restore them from Settings.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					/* translators: %d: number of selected issues that will be restored. */
					'confirm_restore_selected' => __( 'Restore %d selected issues? They become open again and count against your score.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					/* translators: %d: total number of ignored issues that will be restored. */
					'confirm_restore_all'      => __( 'Restore all %d ignored issues? They become open again and count against your score.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				),
			)
		);

	}

	/**
	 * Routes the main page to the requested tab.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_main_page() {

		$tab        = filter_input( INPUT_GET, 'tab', FILTER_SANITIZE_SPECIAL_CHARS );
		$tab        = ( is_string( $tab ) ? sanitize_key( $tab ) : 'dashboard' );
		$categories = wpfchs()->core->checks->get_categories();

		$this->render_shell_open( $tab );

		if ( isset( $categories[ $tab ] ) ) {
			$this->category->render( $tab );
		} elseif ( 'history' === $tab ) {
			$this->history->render();
		} else {
			$this->dashboard->render();
		}

		$this->render_shell_close();

	}

	/**
	 * Opens the shared page shell. Every plugin screen — dashboard, category
	 * tabs, history, profiles, settings, setup — renders inside the same
	 * wrapper and the same tab bar, so moving between them never changes the
	 * layout underneath you.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $active Active tab key.
	 */
	function render_shell_open( $active ) {
		echo '<div class="wrap wpfchs-wrap">';
		echo '<h1>' . esc_html__( 'Catalog Health Scanner', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</h1>';
		$this->render_tabs( $active );
	}

	/**
	 * Closes the shared page shell.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function render_shell_close() {
		echo '</div>';
	}

	/**
	 * Asset version from the file's modified time, so every CSS/JS change
	 * busts the browser cache without a manual version bump.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $relative_path
	 * @return  string
	 */
	function asset_version( $relative_path ) {
		$file = wpfchs()->plugin_path() . '/' . ltrim( $relative_path, '/' );
		return ( file_exists( $file ) ? (string) filemtime( $file ) : wpfchs()->version );
	}

	/**
	 * render_tabs.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $active
	 */
	function render_tabs( $active ) {

		$tabs = array_merge(
			array( 'dashboard' => __( 'Dashboard', 'wpfactory-catalog-health-scanner-for-woocommerce' ) ),
			wpfchs()->core->checks->get_categories(),
			array( 'history' => __( 'History', 'wpfactory-catalog-health-scanner-for-woocommerce' ) )
		);

		// Screens that live on their own admin page but belong to the same
		// tab bar. Keyed by the tab key their render_page() passes in.
		$pages = array(
			'profiles' => array(
				'label' => __( 'Profiles', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'url'   => admin_url( 'admin.php?page=wpfchs-profiles' ),
			),
			'settings' => array(
				'label' => __( 'Settings', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'url'   => admin_url( 'admin.php?page=wpfchs-settings' ),
			),
			'setup'    => array(
				'label' => __( 'Setup', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				'url'   => admin_url( 'admin.php?page=wpfchs-setup' ),
			),
		);

		echo '<nav class="nav-tab-wrapper wpfchs-tabs">';

		foreach ( $tabs as $tab_id => $label ) {
			$url = add_query_arg(
				array(
					'page' => 'wpfchs',
					'tab'  => $tab_id,
				),
				admin_url( 'admin.php' )
			);
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . ( $tab_id === $active ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}

		echo '<span class="wpfchs-tabs-spacer"></span>';

		foreach ( $pages as $page_id => $page ) {
			echo '<a href="' . esc_url( $page['url'] ) . '" class="nav-tab wpfchs-tab-secondary' . ( $page_id === $active ? ' nav-tab-active' : '' ) . '">' . esc_html( $page['label'] ) . '</a>';
		}

		echo '</nav>';

	}

	/**
	 * Severity badge markup.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $severity
	 * @return  string
	 */
	function severity_badge( $severity ) {
		$labels = array(
			'critical' => __( 'Critical', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
			'high'     => __( 'High', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
			'medium'   => __( 'Medium', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
			'low'      => __( 'Low', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		);
		$severity = ( isset( $labels[ $severity ] ) ? $severity : 'medium' );
		return '<span class="wpfchs-badge wpfchs-badge-' . esc_attr( $severity ) . '">' . esc_html( $labels[ $severity ] ) . '</span>';
	}

	/**
	 * Last completed scan's per-category score data.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array {scan: object|null, categories: array}
	 */
	function get_last_scan_data() {
		$scan = wpfchs()->core->scanner->get_last_completed();
		if ( ! $scan ) {
			return array(
				'scan'       => null,
				'categories' => array(),
			);
		}
		$data = json_decode( (string) $scan->score_data, true );
		return array(
			'scan'       => $scan,
			'categories' => (array) ( $data['categories'] ?? array() ),
		);
	}

	/**
	 * Redirects to the setup wizard once, right after activation.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function maybe_redirect_to_wizard() {

		if ( 'yes' !== get_option( 'wpfchs_activation_redirect', '' ) ) {
			return;
		}

		// One-shot flag; clear before redirecting so failures can't loop.
		delete_option( 'wpfchs_activation_redirect' );

		if (
			wp_doing_ajax() ||
			is_network_admin() ||
			! current_user_can( wpfchs()->core->get_capability() )
		) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=wpfchs-setup' ) );
		exit;

	}

}

endif;

return new WPFCHS_Admin();
