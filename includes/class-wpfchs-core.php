<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - Core Class
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Core' ) ) :

class WPFCHS_Core {

	/**
	 * checks.
	 *
	 * @var     WPFCHS_Checks
	 * @since   1.0.0
	 */
	public $checks;

	/**
	 * applicability.
	 *
	 * @var     WPFCHS_Applicability
	 * @since   1.0.0
	 */
	public $applicability;

	/**
	 * issues.
	 *
	 * @var     WPFCHS_Issues
	 * @since   1.0.0
	 */
	public $issues;

	/**
	 * scanner.
	 *
	 * @var     WPFCHS_Scanner
	 * @since   1.0.0
	 */
	public $scanner;

	/**
	 * scores.
	 *
	 * @var     WPFCHS_Scores
	 * @since   1.0.0
	 */
	public $scores;

	/**
	 * profiles.
	 *
	 * @var     WPFCHS_Profiles
	 * @since   1.0.0
	 */
	public $profiles;

	/**
	 * fixes.
	 *
	 * @var     WPFCHS_Fixes
	 * @since   1.0.0
	 */
	public $fixes;

	/**
	 * schedule.
	 *
	 * @var     WPFCHS_Schedule
	 * @since   1.0.0
	 */
	public $schedule;

	/**
	 * export.
	 *
	 * @var     WPFCHS_Export
	 * @since   1.0.0
	 */
	public $export;

	/**
	 * recommendations.
	 *
	 * @var     WPFCHS_Recommendations
	 * @since   1.0.0
	 */
	public $recommendations;

	/**
	 * report.
	 *
	 * @var     WPFCHS_Report
	 * @since   1.0.0
	 */
	public $report;

	/**
	 * bulk. Pro only; null when the Pro plugin is not installed.
	 *
	 * @var     WPFCHS_Pro_Bulk|null
	 * @since   1.0.0
	 */
	public $bulk;

	/**
	 * compare. Pro only; null when the Pro plugin is not installed.
	 *
	 * @var     WPFCHS_Pro_Compare|null
	 * @since   1.0.0
	 */
	public $compare;

	/**
	 * admin.
	 *
	 * @var     WPFCHS_Admin
	 * @since   1.0.0
	 */
	public $admin;

	/**
	 * ajax.
	 *
	 * @var     WPFCHS_Ajax
	 * @since   1.0.0
	 */
	public $ajax;

	/**
	 * Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function __construct() {

		$path = plugin_dir_path( __FILE__ );

		$this->applicability = require_once $path . 'class-wpfchs-applicability.php';
		require_once $path . 'checks/class-wpfchs-check.php';
		$this->checks   = require_once $path . 'checks/class-wpfchs-checks.php';
		$this->issues   = require_once $path . 'class-wpfchs-issues.php';
		$this->scores   = require_once $path . 'class-wpfchs-scores.php';
		$this->profiles = require_once $path . 'class-wpfchs-profiles.php';
		$this->fixes    = require_once $path . 'class-wpfchs-fixes.php';
		$this->scanner  = require_once $path . 'class-wpfchs-scanner.php';
		$this->export   = require_once $path . 'class-wpfchs-export.php';
		$this->recommendations = require_once $path . 'class-wpfchs-recommendations.php';

		// Modules that ship only in the Pro plugin. Their absence is what
		// makes a feature unavailable here — nothing in this plugin disables
		// or restricts them, and no code for them is present when they are
		// not installed.
		if ( is_dir( $path . 'pro' ) ) {
			$this->schedule = require_once $path . 'pro/class-wpfchs-schedule.php';
			require_once $path . 'pro/report/class-wpfchs-pdf.php';
			$this->report   = require_once $path . 'pro/report/class-wpfchs-report.php';
			$this->bulk     = require_once $path . 'pro/class-wpfchs-pro-bulk.php';
			$this->compare  = require_once $path . 'pro/class-wpfchs-pro-compare.php';
		}

		if ( is_admin() ) {
			$this->ajax = require_once $path . 'admin/class-wpfchs-ajax.php';
			$this->admin = require_once $path . 'admin/class-wpfchs-admin.php';
			do_action( 'wpfchs_admin_loaded', $this );
		}

		// Restock history collection: powers the "never restocked" family of
		// checks once enough data has accumulated (they need real history,
		// not guesses).
		add_action( 'woocommerce_product_set_stock', array( $this, 'track_restock' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'track_restock' ) );

		do_action( 'wpfchs_core_loaded', $this );

	}

	/**
	 * Records the last moment a product's stock was set to a positive
	 * quantity.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   WC_Product $product
	 */
	function track_restock( $product ) {
		$qty = $product->get_stock_quantity();
		if ( null !== $qty && $qty > 0 ) {
			update_post_meta( $product->get_id(), '_wpfchs_last_restock', time() );
		}
	}

	/**
	 * Returns a threshold-type setting with its default.
	 *
	 * All thresholds live in one grouped option row (`wpfchs_thresholds`).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $key
	 * @return  mixed
	 */
	function get_threshold( $key ) {
		$defaults = array(
			'min_margin_percent'     => 0,
			'min_image_width'        => 500,
			'min_description_chars'  => 200,
			'low_stock_days'         => 30,
			// Off by default. The primary reason anyone installs this plugin is
			// to audit a catalog they just imported, migrated, or bulk-edited —
			// exactly the products a grace window hides. Stores that add one
			// product at a time can switch it on in Settings.
			'grace_period_days'      => 0,
			'undo_window_days'       => 30,
			'oos_age_days'           => 30,
			'max_weight'             => 1000,
			'price_deviation_factor' => 8,
			'image_reuse_count'      => 5,
			'max_feed_desc_chars'    => 5000,
		);
		$defaults   = apply_filters( 'wpfchs_threshold_defaults', $defaults );
		$thresholds = get_option( 'wpfchs_thresholds', array() );
		$value      = ( isset( $thresholds[ $key ] ) && '' !== $thresholds[ $key ] ? $thresholds[ $key ] : ( $defaults[ $key ] ?? 0 ) );
		return apply_filters( 'wpfchs_threshold', $value, $key );
	}

	/**
	 * Returns the user capability required for every plugin surface.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function get_capability() {
		return apply_filters( 'wpfchs_capability', 'manage_woocommerce' );
	}

}

endif;

return new WPFCHS_Core();
