<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - Product Screen Surfaces Class
 *
 * The two surfaces outside the plugin's own pages: an issue-count column
 * in the products list, and a panel on the product edit screen.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Admin_Products' ) ) :

class WPFCHS_Admin_Products {

	/**
	 * Issue counts for the visible list page, primed once per request.
	 *
	 * @var     array|null
	 * @since   1.0.0
	 */
	protected $list_counts = null;

	/**
	 * Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function __construct() {
		add_filter( 'manage_edit-product_columns', array( $this, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-product_sortable_columns', array( $this, 'sortable_column' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_issues' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
	}

	/**
	 * sortable_column.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $columns
	 * @return  array
	 */
	function sortable_column( $columns ) {
		$columns['wpfchs_issues'] = 'wpfchs_issues';
		return $columns;
	}

	/**
	 * Sorts the products list by open-issue count. Products without the
	 * count meta (no open issues) sort as zero via the NOT EXISTS clause.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   WP_Query $query
	 */
	function sort_by_issues( $query ) {

		if (
			! is_admin() ||
			! $query->is_main_query() ||
			'product' !== $query->get( 'post_type' ) ||
			'wpfchs_issues' !== $query->get( 'orderby' )
		) {
			return;
		}

		$query->set(
			'meta_query', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Required for sortable column; admin list only.
			array(
				'relation'         => 'OR',
				'wpfchs_no_issues' => array(
					'key'     => '_wpfchs_open_issues',
					'compare' => 'NOT EXISTS',
				),
				'wpfchs_issues'    => array(
					'key'     => '_wpfchs_open_issues',
					'compare' => 'EXISTS',
					'type'    => 'NUMERIC',
				),
			)
		);
		$query->set( 'orderby', 'meta_value_num' );

	}

	/**
	 * add_column.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $columns
	 * @return  array
	 */
	function add_column( $columns ) {
		$columns['wpfchs_issues'] = __( 'Health', 'wpfactory-catalog-health-scanner-for-woocommerce' );
		return $columns;
	}

	/**
	 * render_column.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $column
	 * @param   int    $post_id
	 */
	function render_column( $column, $post_id ) {

		if ( 'wpfchs_issues' !== $column ) {
			return;
		}

		if ( null === $this->list_counts ) {
			global $wp_query;
			$ids = wp_list_pluck( (array) $wp_query->posts, 'ID' );
			$this->list_counts = wpfchs()->core->issues->count_open_by_product( $ids );
		}

		$count = ( $this->list_counts[ $post_id ] ?? 0 );

		if ( $count < 1 ) {
			echo '<span class="wpfchs-col-ok" aria-hidden="true">—</span>';
			echo '<span class="screen-reader-text">' . esc_html__( 'No open issues', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</span>';
			return;
		}

		echo '<span class="wpfchs-col-count">' . esc_html( number_format_i18n( $count ) ) . '</span>';

	}

	/**
	 * add_meta_box.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function add_meta_box() {
		if ( ! current_user_can( wpfchs()->core->get_capability() ) ) {
			return;
		}
		add_meta_box(
			'wpfchs-product-issues',
			__( 'Catalog Health', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
			array( $this, 'render_meta_box' ),
			'product',
			'side',
			'default'
		);
	}

	/**
	 * Read-only panel with this product's open issues and inline ignore.
	 * No form fields of its own, so no save handler and no nonce of its
	 * own is needed; the Ignore links go through the admin AJAX endpoint.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   WP_Post $post
	 */
	function render_meta_box( $post ) {

		$core   = wpfchs()->core;
		$issues = $core->issues->query(
			array(
				'product_id' => $post->ID,
				'status'     => 'open',
				'limit'      => 50,
			)
		);

		if ( empty( $issues ) ) {
			echo '<p>' . esc_html__( 'No open issues on this product.', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<ul class="wpfchs-metabox-list">';
		foreach ( $issues as $issue ) {
			$check = $core->checks->get( $issue->check_id );
			echo '<li data-issue="' . esc_attr( $issue->id ) . '">';
			echo wp_kses_post( $core->admin->severity_badge( $issue->severity ) ) . ' ';
			echo esc_html( $check ? $check->get_label() : $issue->check_id );
			if ( '' !== (string) $issue->issue_value ) {
				echo '<br /><span class="wpfchs-muted">' . esc_html( $issue->issue_value ) . '</span>';
			}
			echo '<br /><button type="button" class="button-link wpfchs-ignore-issue" data-issue="' . esc_attr( $issue->id ) . '">' . esc_html__( 'Ignore for this product', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</button>';
			echo '</li>';
		}
		echo '</ul>';

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpfchs' ) ) . '">' . esc_html__( 'Open Catalog Health dashboard', 'wpfactory-catalog-health-scanner-for-woocommerce' ) . '</a></p>';

	}

}

endif;

return new WPFCHS_Admin_Products();
