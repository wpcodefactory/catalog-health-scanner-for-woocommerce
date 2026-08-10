<?php
/**
 * Catalog Health Scanner for WooCommerce - Upgrade Class
 *
 * The free build's doorway to Pro. Every Pro feature stays visible in the
 * free build — buttons, sections and columns render where they always do,
 * carrying a lock mark — because a feature that is hidden can never sell
 * itself. This class centralises the pieces of that: the upgrade URL, the
 * lock/badge markup, and the WPFactory promoting notice shown above disabled
 * settings.
 *
 * The conversion pattern is taste-then-buy: previews are never locked (a
 * store owner can always see exactly what a bulk fix would change), applying
 * to more than one product at a time is. Enforcement lives server-side in
 * WPFCHS_Ajax / WPFCHS_Report / WPFCHS_Schedule via wpfchs()->is_pro();
 * everything here is presentation.
 *
 * In the Pro build every method degrades to a no-op or plain markup, so
 * callers never branch — they call `lock_button()` and get a working button
 * when Pro, a locked one when free.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Upgrade' ) ) :

class WPFCHS_Upgrade {

	/**
	 * Where every lock leads.
	 *
	 * @since 1.0.0
	 */
	const URL = 'https://wpfactory.com/item/catalog-health-scanner-for-woocommerce/';

	/**
	 * Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function __construct() {
		add_action( 'admin_init', array( $this, 'add_promoting_notice' ) );
	}

	/**
	 * Whether Pro features should render locked.
	 *
	 * Deliberately evaluated per call, not cached in the constructor: this
	 * class is constructed while `plugins_loaded` is still running, before
	 * anything could filter `wpfchs_is_pro`.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  bool
	 */
	function is_locked() {
		return ! wpfchs()->is_pro();
	}

	/**
	 * Pro version title, used in notices and modals.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function pro_title() {
		return __( 'Catalog Health Scanner for WooCommerce Pro', 'catalog-health-scanner-for-woocommerce' );
	}

	/**
	 * WPFactory promoting notice above the settings screen's disabled
	 * schedule options: clicking a disabled option highlights the notice,
	 * which explains what unlocks it.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @see     https://github.com/wpcodefactory/wpfactory-promoting-notice
	 */
	function add_promoting_notice() {

		if (
			! $this->is_locked() ||
			! class_exists( '\WPFactory\Promoting_Notice\Core' )
		) {
			return;
		}

		$notice = new \WPFactory\Promoting_Notice\Core();
		$notice->set_args(
			array(
				'url_requirements'   => array(
					'page_filename' => 'admin.php',
					'params'        => array( 'page' => 'wpfchs-settings' ),
				),
				'template_variables' => array(
					'%pro_version_url%'    => self::URL,
					'%pro_version_title%'  => $this->pro_title(),
					'%main_text%'          => __( 'Locked options can be unlocked using <a href="%pro_version_url%" target="_blank"><strong>%pro_version_title%</strong></a>', 'catalog-health-scanner-for-woocommerce' ),
					'%btn_call_to_action%' => __( 'Upgrade to Pro version', 'catalog-health-scanner-for-woocommerce' ),
					// The stock template leads with a plugin icon served from
					// ps.w.org, which does not exist until after the wp.org
					// review — so the icon is left out of the template.
					'%content_template%'   => '<span class="wpfactory-pan-main-text">%main_text%</span>' .
						'<a target="_blank" class="wpfactory-pan-button button-primary" href="%pro_version_url%"><i class="%btn_icon_class%"></i>%btn_call_to_action%</a>',
				),
			)
		);
		$notice->init();

	}

	/**
	 * A small "Pro" chip for panel headings and table cells.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function badge() {
		return '<span class="wpfchs-pro-badge">' . esc_html__( 'Pro', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
	}

	/**
	 * A button for a Pro-gated action. Pro build: the working button,
	 * unchanged. Free build: same place, same label, plus a padlock — and the
	 * click opens the upgrade modal instead of the action.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $label   Button text.
	 * @param   string $feature Feature name for the modal title.
	 * @param   string $classes CSS classes for the working (Pro) button.
	 * @param   string $attrs   Pre-escaped extra attributes for the working button.
	 * @return  string
	 */
	function lock_button( $label, $feature, $classes = 'button', $attrs = '' ) {

		if ( ! $this->is_locked() ) {
			return '<button type="button" class="' . esc_attr( $classes ) . '"' . ( '' !== $attrs ? ' ' . $attrs : '' ) . '>' . esc_html( $label ) . '</button>';
		}

		return '<button type="button"' .
			' class="' . esc_attr( $classes ) . ' wpfchs-locked"' .
			' data-feature="' . esc_attr( $feature ) . '"' .
			' aria-label="' . esc_attr( sprintf( /* translators: %s: feature name. */ __( '%s — available in Pro', 'catalog-health-scanner-for-woocommerce' ), $feature ) ) . '"' .
		'>' .
			'<span class="dashicons dashicons-lock" aria-hidden="true"></span>' .
			esc_html( $label ) .
		'</button>';

	}

	/**
	 * The same lock treatment for what is semantically a link (the PDF
	 * download). Free build: a locked button. Pro build: the real link.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $label   Link text.
	 * @param   string $feature Feature name for the modal title.
	 * @param   string $url     The working (Pro) URL.
	 * @param   string $classes CSS classes.
	 * @return  string
	 */
	function lock_link( $label, $feature, $url, $classes = 'button' ) {

		if ( ! $this->is_locked() ) {
			return '<a class="' . esc_attr( $classes ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}

		return $this->lock_button( $label, $feature, $classes );

	}

	/**
	 * One-line explanation + upgrade link for a locked panel or section.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $text What Pro would do here, in one sentence.
	 * @return  string
	 */
	function lock_note( $text ) {
		return '<p class="wpfchs-lock-note">' .
			'<span class="dashicons dashicons-lock" aria-hidden="true"></span>' .
			esc_html( $text ) . ' ' .
			'<a href="' . esc_url( self::URL ) . '" target="_blank">' . esc_html__( 'Unlock with Pro', 'catalog-health-scanner-for-woocommerce' ) . '</a>' .
		'</p>';
	}

}

endif;

return new WPFCHS_Upgrade();
