<?php
/**
 * Catalog Health Scanner for WooCommerce - Recommendations Class
 *
 * Cross-catalog plugin recommendations (spec section 14), under two hard
 * constraints: restraint (one subtle dismissible line, neutral styling)
 * and honesty (a recommendation only appears where the plugin genuinely
 * solves the finding — a bad mapping destroys the credibility of all the
 * others, so several spec-suggested mappings are deliberately absent).
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Recommendations' ) ) :

class WPFCHS_Recommendations {

	/**
	 * Check id => recommendation map.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array
	 */
	function get_map() {

		$ean = array(
			'plugin' => __( 'EAN for WooCommerce', 'catalog-health-scanner-for-woocommerce' ),
			'url'    => 'https://wpfactory.com/item/ean-for-woocommerce/',
			'text'   => __( 'manages GTIN, EAN, and UPC codes in bulk, including auto-generation.', 'catalog-health-scanner-for-woocommerce' ),
		);
		$cog = array(
			'plugin' => __( 'Cost of Goods for WooCommerce', 'catalog-health-scanner-for-woocommerce' ),
			'url'    => 'https://wpfactory.com/item/cost-of-goods-for-woocommerce/',
			'text'   => __( 'tracks cost and profit per product and per order.', 'catalog-health-scanner-for-woocommerce' ),
		);
		$vat = array(
			'plugin' => __( 'EU VAT for WooCommerce', 'catalog-health-scanner-for-woocommerce' ),
			'url'    => 'https://wpfactory.com/item/eu-vat-for-woocommerce/',
			'text'   => __( 'handles VAT validation and tax handling across EU regions.', 'catalog-health-scanner-for-woocommerce' ),
		);

		return apply_filters(
			'wpfchs_recommendations_map',
			array(
				'gtin_missing'           => $ean,
				'cog_missing'            => $cog,
				'cog_above_price'        => $cog,
				'sale_negative_margin'   => $cog,
				'margin_below_threshold' => $cog,
				'tax_class_invalid'      => $vat,
				'tax_status_none'        => $vat,
				'tax_class_inconsistent' => $vat,
			)
		);

	}

	/**
	 * Recommendation for a check, unless dismissed.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $check_id
	 * @return  array|null
	 */
	function get( $check_id ) {
		if ( in_array( $check_id, $this->get_dismissed(), true ) ) {
			return null;
		}
		$map = $this->get_map();
		return ( $map[ $check_id ] ?? null );
	}

	/**
	 * get_dismissed.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array of check ids
	 */
	function get_dismissed() {
		return array_filter( (array) get_option( 'wpfchs_dismissed_recs', array() ) );
	}

	/**
	 * dismiss.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $check_id
	 */
	function dismiss( $check_id ) {
		$dismissed = $this->get_dismissed();
		if ( ! in_array( $check_id, $dismissed, true ) ) {
			$dismissed[] = $check_id;
			update_option( 'wpfchs_dismissed_recs', $dismissed, false );
		}
	}

	/**
	 * Renders the single subtle line, neutral styling, dismissible.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $check_id
	 */
	function render( $check_id ) {

		$recommendation = $this->get( $check_id );
		if ( null === $recommendation ) {
			return;
		}

		echo '<p class="wpfchs-recommendation" data-check="' . esc_attr( $check_id ) . '">';
		echo '<a href="' . esc_url( $recommendation['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $recommendation['plugin'] ) . '</a> ';
		echo esc_html( $recommendation['text'] );
		echo ' <button type="button" class="button-link wpfchs-dismiss-rec" data-check="' . esc_attr( $check_id ) . '">' . esc_html__( 'Dismiss', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
		echo '</p>';

	}

}

endif;

return new WPFCHS_Recommendations();
