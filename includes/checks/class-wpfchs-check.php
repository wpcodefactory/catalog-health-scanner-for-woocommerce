<?php
/**
 * Catalog Health Scanner for WooCommerce - Check Class
 *
 * A single catalog check: identity, severity, applicability, the test
 * itself, and (optionally) the fixer that can repair its findings.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Check' ) ) :

class WPFCHS_Check {

	/**
	 * Check definition.
	 *
	 * @var     array
	 * @since   1.0.0
	 */
	protected $def;

	/**
	 * Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $def
	 */
	function __construct( $def ) {
		$this->def = wp_parse_args(
			$def,
			array(
				'id'          => '',
				'category'    => '',
				'label'       => '',
				'explanation' => '',
				'severity'    => 'medium',
				'group'       => null,
				'store_level' => false,
				'pass'        => 'product',
				'applies'     => null,
				'check'       => null,
				'fix'         => null,
				'fix_type'    => 'manual',
			)
		);
	}

	/**
	 * get_id.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function get_id() {
		return $this->def['id'];
	}

	/**
	 * get_category.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function get_category() {
		return $this->def['category'];
	}

	/**
	 * get_label.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function get_label() {
		return $this->def['label'];
	}

	/**
	 * Plain-language explanation of the commercial impact.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function get_explanation() {
		return $this->def['explanation'];
	}

	/**
	 * get_severity.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function get_severity() {
		return $this->def['severity'];
	}

	/**
	 * Score weight for this check's severity.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  float
	 */
	function get_weight() {
		return self::severity_weight( $this->def['severity'] );
	}

	/**
	 * Applicability group this check belongs to ('' = always applicable).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	/**
	 * Whether one store-level cause produces this check's findings.
	 *
	 * A store-level check flags every product for a single root cause (a
	 * theme setting, say). Scoring and reporting treat it as one finding —
	 * the per-product rows exist only so the product tables can show reach.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	function is_store_level() {
		return ! empty( $this->def['store_level'] );
	}

	function get_group() {
		if ( null !== $this->def['group'] ) {
			return $this->def['group'];
		}
		$category_groups = array(
			'inventory' => 'inventory',
			'shipping'  => 'shipping',
			'tax'       => 'tax',
			'downloads' => 'downloads',
			'feed'      => 'feed',
		);
		return ( $category_groups[ $this->def['category'] ] ?? '' );
	}

	/**
	 * Whether this is a per-product or a catalog-wide (cross-product) pass.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	function is_catalog_pass() {
		return ( 'catalog' === $this->def['pass'] );
	}

	/**
	 * Whether the check runs on a given product at all.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   WC_Product $product
	 * @return  bool
	 */
	function applies_to( $product ) {
		if ( is_callable( $this->def['applies'] ) ) {
			return (bool) call_user_func( $this->def['applies'], $product );
		}
		return true;
	}

	/**
	 * Runs the per-product test.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   WC_Product $product
	 * @return  false|string False/empty when passing; offending value (or `true`) when failing.
	 */
	function run( $product ) {
		if ( ! is_callable( $this->def['check'] ) ) {
			return false;
		}
		return call_user_func( $this->def['check'], $product );
	}

	/**
	 * Runs the catalog-wide pass.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array object_id => array{product_id: int, value: string}
	 */
	function run_catalog() {
		if ( ! is_callable( $this->def['check'] ) ) {
			return array();
		}
		return (array) call_user_func( $this->def['check'] );
	}

	/**
	 * get_fixer.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string|null
	 */
	function get_fixer() {
		return $this->def['fix'];
	}

	/**
	 * Fix level: auto (unambiguous, one click), bulk (user supplies the
	 * value), or manual (edit-screen only).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function get_fix_type() {
		return $this->def['fix_type'];
	}

	/**
	 * severity_weight.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $severity
	 * @return  float
	 */
	static function severity_weight( $severity ) {
		$weights = array(
			'critical' => 4,
			'high'     => 2,
			'medium'   => 1,
			'low'      => 0.5,
		);
		return (float) ( $weights[ $severity ] ?? 1 );
	}

}

endif;
