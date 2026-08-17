<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - CSV Export Class
 *
 * Admin GET action link pattern: nonce in the URL, nonce + capability
 * verified in the handler, `wp_die()` on failure.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Export' ) ) :

class WPFCHS_Export {

	/**
	 * Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_export' ) );
	}

	/**
	 * Builds the export URL for the current filter set.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $args {category, check_id, status, severity}
	 * @return  string
	 */
	function get_url( $args = array() ) {
		return wp_nonce_url(
			add_query_arg(
				array_merge(
					array( 'wpfchs_export' => 'issues' ),
					array_filter( $args )
				),
				admin_url( 'admin.php?page=wpfchs' )
			),
			'wpfchs_export'
		);
	}

	/**
	 * Streams the CSV when the export action link is followed.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function maybe_export() {

		if ( ! isset( $_GET['wpfchs_export'] ) ) {
			return;
		}

		if (
			! isset( $_GET['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wpfchs_export' ) ||
			! current_user_can( wpfchs()->core->get_capability() )
		) {
			wp_die( esc_html__( 'Link expired.', 'wpfactory-catalog-health-scanner-for-woocommerce' ) );
		}

		$args = array(
			'category' => ( isset( $_GET['category'] ) ? sanitize_key( wp_unslash( $_GET['category'] ) ) : '' ),
			'check_id' => ( isset( $_GET['check_id'] ) ? sanitize_key( wp_unslash( $_GET['check_id'] ) ) : '' ),
			'status'   => ( isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open' ),
			'severity' => ( isset( $_GET['severity'] ) ? sanitize_key( wp_unslash( $_GET['severity'] ) ) : '' ),
			'limit'    => 100000,
		);

		$issues = wpfchs()->core->issues->query( $args );
		$checks = wpfchs()->core->checks;

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=catalog-health-issues-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );

		fputcsv(
			$out,
			array(
				__( 'Product ID', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				__( 'Product', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				__( 'SKU', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				__( 'Category', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				__( 'Check', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				__( 'Severity', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				__( 'Status', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				__( 'Value', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				__( 'First seen', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
				__( 'Edit link', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
			)
		);

		$category_labels = $checks->get_categories();

		foreach ( $issues as $issue ) {
			$check   = $checks->get( $issue->check_id );
			$product = wc_get_product( $issue->product_id );
			fputcsv(
				$out,
				array(
					$issue->product_id,
					( $product ? $product->get_name() : '#' . $issue->product_id ),
					( $product ? $product->get_sku() : '' ),
					( $category_labels[ $issue->category ] ?? $issue->category ),
					( $check ? $check->get_label() : $issue->check_id ),
					$issue->severity,
					$issue->status,
					$issue->issue_value,
					$issue->first_seen,
					get_edit_post_link( $issue->product_id, 'url' ),
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream, not a file write.

		exit;

	}

}

endif;

return new WPFCHS_Export();
