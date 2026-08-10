<?php
/**
 * Catalog Health Scanner for WooCommerce - Admin AJAX Class
 *
 * Every handler: `check_ajax_referer()` first line, then capability.
 * The nonce is delivered via `wp_localize_script`.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Ajax' ) ) :

class WPFCHS_Ajax {

	/**
	 * Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function __construct() {
		add_action( 'wp_ajax_wpfchs_scan_start', array( $this, 'scan_start' ) );
		add_action( 'wp_ajax_wpfchs_scan_step', array( $this, 'scan_step' ) );
		add_action( 'wp_ajax_wpfchs_scan_control', array( $this, 'scan_control' ) );
		add_action( 'wp_ajax_wpfchs_group_products', array( $this, 'group_products' ) );
		add_action( 'wp_ajax_wpfchs_ignore_issue', array( $this, 'ignore_issue' ) );
		add_action( 'wp_ajax_wpfchs_restore_issue', array( $this, 'restore_issue' ) );
		add_action( 'wp_ajax_wpfchs_ignore_bulk', array( $this, 'ignore_bulk' ) );
		add_action( 'wp_ajax_wpfchs_restore_bulk', array( $this, 'restore_bulk' ) );
		add_action( 'wp_ajax_wpfchs_fix_preview', array( $this, 'fix_preview' ) );
		add_action( 'wp_ajax_wpfchs_fix_apply', array( $this, 'fix_apply' ) );
		add_action( 'wp_ajax_wpfchs_fix_undo', array( $this, 'fix_undo' ) );
		add_action( 'wp_ajax_wpfchs_fix_all_quick_wins', array( $this, 'fix_all_quick_wins' ) );
		add_action( 'wp_ajax_wpfchs_preview_quick_wins', array( $this, 'preview_quick_wins' ) );
		add_action( 'wp_ajax_wpfchs_dismiss_rec', array( $this, 'dismiss_rec' ) );
	}

	/**
	 * preview_quick_wins: combined before/after preview for "Fix all".
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function preview_quick_wins() {
		$this->gate();

		$preview = wpfchs()->core->fixes->preview_all_quick_wins();

		if ( 0 === $preview['total'] ) {
			wp_send_json_error( array( 'message' => __( 'Nothing auto-fixable right now.', 'catalog-health-scanner-for-woocommerce' ) ) );
		}

		ob_start();

		echo '<p class="wpfchs-preview-summary">';
		printf(
			/* translators: %1$d: total products, %2$d: number of checks. */
			esc_html( _n( '%1$d product will be fixed across %2$d check.', '%1$d products will be fixed across %2$d checks.', $preview['total'], 'catalog-health-scanner-for-woocommerce' ) ),
			(int) $preview['total'],
			(int) count( $preview['checks'] )
		);
		echo '</p>';

		echo '<ul class="wpfchs-preview-checks">';
		foreach ( $preview['checks'] as $c ) {
			echo '<li>' . esc_html( $c['label'] ) . ' <span class="wpfchs-muted">' . esc_html( sprintf( /* translators: %d: count. */ _n( '%d product', '%d products', $c['count'], 'catalog-health-scanner-for-woocommerce' ), $c['count'] ) ) . '</span></li>';
		}
		echo '</ul>';

		echo '<table class="widefat striped wpfchs-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Fix', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Current', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'After', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $preview['rows'], 0, 60 ) as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['title'] ) . '</td>';
			echo '<td class="wpfchs-muted">' . esc_html( $row['check'] ) . '</td>';
			echo '<td class="wpfchs-before">' . esc_html( $row['before'] ) . '</td>';
			echo '<td class="wpfchs-after">' . esc_html( $row['after'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<p class="wpfchs-muted">';
		printf(
			/* translators: %d: undo window in days. */
			esc_html__( 'Every change is logged and can be undone for %d days.', 'catalog-health-scanner-for-woocommerce' ),
			(int) wpfchs()->core->get_threshold( 'undo_window_days' )
		);
		echo '</p>';

		wp_send_json_success(
			array(
				'title' => sprintf(
					/* translators: %d: number of products. */
					__( 'Preview: fix %d products', 'catalog-health-scanner-for-woocommerce' ),
					$preview['total']
				),
				'html'  => ob_get_clean(),
				'total' => $preview['total'],
				// The preview is free to see; applying it is not. The JS
				// layer swaps the apply button for an upgrade link.
				'pro_required' => ( ! wpfchs()->is_pro() ),
				'upgrade'      => WPFCHS_Upgrade::URL,
			)
		);
	}

	/**
	 * fix_all_quick_wins: applies every auto-fixable group in one action.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function fix_all_quick_wins() {
		$this->gate();

		if ( ! wpfchs()->is_pro() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Fixing every quick win in one click is a Pro feature. The free version fixes one product at a time.', 'catalog-health-scanner-for-woocommerce' ),
					'upgrade' => WPFCHS_Upgrade::URL,
					'feature' => __( 'Fix all quick wins', 'catalog-health-scanner-for-woocommerce' ),
				)
			);
		}

		$result = wpfchs()->core->fixes->fix_all_quick_wins();

		if ( 0 === $result['products_fixed'] ) {
			wp_send_json_error( array( 'message' => __( 'Nothing auto-fixable right now.', 'catalog-health-scanner-for-woocommerce' ) ) );
		}

		$result['message'] = sprintf(
			/* translators: %1$d: number of products fixed, %2$d: number of checks. */
			_n(
				'Fixed %1$d product across %2$d check.',
				'Fixed %1$d products across %2$d checks.',
				$result['products_fixed'],
				'catalog-health-scanner-for-woocommerce'
			),
			$result['products_fixed'],
			$result['checks_fixed']
		);

		wp_send_json_success( $result );
	}

	/**
	 * dismiss_rec: permanently hides a plugin recommendation line.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function dismiss_rec() {
		$this->gate();
		$check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		wpfchs()->core->recommendations->dismiss( $check_id );
		wp_send_json_success();
	}

	/**
	 * Shared gate: nonce + capability.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	protected function gate() {
		check_ajax_referer( 'wpfchs-admin', 'nonce' );
		if ( ! current_user_can( wpfchs()->core->get_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'catalog-health-scanner-for-woocommerce' ) ) );
		}
	}

	/**
	 * Pro gate for bulk fixing. Previews are never gated — the free build
	 * always shows exactly what a fix would change (taste-then-buy); applying
	 * to more than one product at a time is what Pro unlocks. The JS layer
	 * turns the `upgrade` key into an upgrade modal instead of an error
	 * notice, but this is the enforcement: the free build refuses the write
	 * regardless of what the client sends.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $target_count Products the request would write to.
	 */
	protected function gate_bulk_fix( $target_count ) {
		if ( wpfchs()->is_pro() || $target_count <= 1 ) {
			return;
		}
		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: %d: number of products in the request. */
					__( 'Fixing %d products at once is a Pro feature. The free version fixes one product at a time.', 'catalog-health-scanner-for-woocommerce' ),
					$target_count
				),
				'upgrade' => WPFCHS_Upgrade::URL,
				'feature' => __( 'Bulk fixing', 'catalog-health-scanner-for-woocommerce' ),
			)
		);
	}

	/**
	 * scan_start.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function scan_start() {
		$this->gate();

		$profile = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		$scan_id = wpfchs()->core->scanner->start( $profile, 'manual' );

		if ( is_wp_error( $scan_id ) ) {
			wp_send_json_error( array( 'message' => $scan_id->get_error_message() ) );
		}

		wp_send_json_success( array( 'scan_id' => $scan_id ) );
	}

	/**
	 * scan_step.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function scan_step() {
		$this->gate();

		$scan_id  = isset( $_POST['scan_id'] ) ? absint( wp_unslash( $_POST['scan_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		$progress = wpfchs()->core->scanner->step( $scan_id, (float) apply_filters( 'wpfchs_ajax_step_budget', 8.0 ) );

		if ( is_wp_error( $progress ) ) {
			wp_send_json_error( array( 'message' => $progress->get_error_message() ) );
		}

		wp_send_json_success( $progress );
	}

	/**
	 * scan_control: pause / resume / cancel.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function scan_control() {
		$this->gate();

		$scan_id = isset( $_POST['scan_id'] ) ? absint( wp_unslash( $_POST['scan_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		$control = isset( $_POST['control'] ) ? sanitize_key( wp_unslash( $_POST['control'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.

		$map = array(
			'pause'  => 'paused',
			'resume' => 'running',
			'cancel' => 'cancelled',
		);

		if ( ! isset( $map[ $control ] ) || ! wpfchs()->core->scanner->set_status( $scan_id, $map[ $control ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not update the scan.', 'catalog-health-scanner-for-woocommerce' ) ) );
		}

		wp_send_json_success( array( 'status' => $map[ $control ] ) );
	}

	/**
	 * group_products: server-rendered product table for an expanded group.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function group_products() {
		$this->gate();

		$check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		$offset   = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		$per_page = 20;

		$core  = wpfchs()->core;
		$check = $core->checks->get( $check_id );
		if ( ! $check ) {
			wp_send_json_error( array( 'message' => __( 'Unknown check.', 'catalog-health-scanner-for-woocommerce' ) ) );
		}

		$filter_args = array(
			'check_id' => $check_id,
			'status'   => 'open',
		);
		$product_cat = isset( $_POST['product_cat'] ) ? absint( wp_unslash( $_POST['product_cat'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		if ( $product_cat ) {
			$filter_args['product_cat'] = $product_cat;
		}
		$product_type = isset( $_POST['product_type'] ) ? sanitize_key( wp_unslash( $_POST['product_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		if ( '' !== $product_type ) {
			$filter_args['product_type'] = $product_type;
		}
		if ( ! empty( $_POST['new_since'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
			$last = $core->scanner->get_last_completed();
			if ( $last ) {
				$filter_args['first_seen_scan'] = (int) $last->id;
			}
		}

		$issues = $core->issues->query(
			array_merge(
				$filter_args,
				array(
					'limit'  => $per_page,
					'offset' => $offset,
				)
			)
		);
		$total = $core->issues->count( $filter_args );

		ob_start();
		$this->render_bulk_controls( $check );
		$this->render_selection_bar( $check, $total );
		$this->render_products_table( $check, $issues );
		$this->render_table_footer( $check, $offset, $per_page, $total );
		$core->recommendations->render( $check_id );
		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'html'  => $html,
				'total' => $total,
			)
		);
	}

	/**
	 * render_bulk_controls.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   WPFCHS_Check $check
	 */
	protected function render_bulk_controls( $check ) {

		if ( 'bulk' !== $check->get_fix_type() || ! $check->get_fixer() ) {
			return;
		}

		$fixer = $check->get_fixer();

		echo '<div class="wpfchs-bulk-controls" data-fixer="' . esc_attr( $fixer ) . '">';

		switch ( $fixer ) {

			case 'generate_skus':
				echo '<span class="wpfchs-muted">' . esc_html__( 'SKUs are generated from the product slug and id, guaranteed unique.', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
				break;

			case 'set_weight':
				echo '<label>' . esc_html__( 'Weight to assign', 'catalog-health-scanner-for-woocommerce' ) . ' ';
				echo '<input type="number" step="any" min="0" class="small-text wpfchs-bulk-value" data-arg="value" /> ' . esc_html( get_option( 'woocommerce_weight_unit' ) );
				echo '</label>';
				break;

			case 'assign_shipping_class':
				$this->render_term_select( 'product_shipping_class', __( 'Shipping class to assign', 'catalog-health-scanner-for-woocommerce' ) );
				break;

			case 'assign_category':
				$this->render_term_select( 'product_cat', __( 'Category to assign', 'catalog-health-scanner-for-woocommerce' ) );
				break;

			case 'assign_brand':
				$taxonomy = wpfchs()->core->fixes->get_brand_taxonomy();
				if ( '' !== $taxonomy ) {
					$this->render_term_select( $taxonomy, __( 'Brand to assign', 'catalog-health-scanner-for-woocommerce' ) );
				}
				break;

			case 'assign_tax_class':
				echo '<label>' . esc_html__( 'Tax class to assign', 'catalog-health-scanner-for-woocommerce' ) . ' ';
				echo '<select class="wpfchs-bulk-value" data-arg="value">';
				echo '<option value="">' . esc_html__( '— Select —', 'catalog-health-scanner-for-woocommerce' ) . '</option>';
				echo '<option value="standard">' . esc_html__( 'Standard rate', 'catalog-health-scanner-for-woocommerce' ) . '</option>';
				foreach ( \WC_Tax::get_tax_class_slugs() as $slug ) {
					echo '<option value="' . esc_attr( $slug ) . '">' . esc_html( $slug ) . '</option>';
				}
				echo '</select></label>';
				break;

			case 'set_cog_percent':
				echo '<label>' . esc_html__( 'Cost as % of price', 'catalog-health-scanner-for-woocommerce' ) . ' ';
				echo '<input type="number" step="any" min="1" max="100" class="small-text wpfchs-bulk-value" data-arg="percent" />%';
				echo '</label>';
				echo ' <span class="wpfchs-muted">' . esc_html__( 'A starting estimate; refine per product later.', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
				break;

		}

		echo '<button type="button" class="button button-primary wpfchs-fix-preview" data-check="' . esc_attr( $check->get_id() ) . '" data-selected-only="1">' . esc_html__( 'Preview changes', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
		echo '</div>';

	}

	/**
	 * Selection bar above the product table: a live count of what is ticked,
	 * the actions that apply to the selection, and an escape hatch that acts
	 * on every matching issue rather than only the visible page.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   WPFCHS_Check $check
	 * @param   int          $total Issues matching the active filters.
	 */
	protected function render_selection_bar( $check, $total ) {

		// Only auto-fixable checks get a selection-scoped fix button here.
		// Bulk checks already have their own "Preview changes" button in the
		// controls row above, next to the value they need.
		$has_fixer = ( $check->get_fixer() && 'auto' === $check->get_fix_type() );

		echo '<div class="wpfchs-selection-bar" data-total="' . esc_attr( $total ) . '">';

		echo '<label class="wpfchs-selection-toggle"><input type="checkbox" class="wpfchs-select-all" /> ' . esc_html__( 'Select all on this page', 'catalog-health-scanner-for-woocommerce' ) . '</label>';

		echo '<span class="wpfchs-selection-count" hidden>';
		echo '<strong class="wpfchs-selected-number">0</strong> ' . esc_html__( 'selected', 'catalog-health-scanner-for-woocommerce' );
		echo '</span>';

		echo '<span class="wpfchs-selection-actions">';

		if ( $has_fixer ) {
			echo '<button type="button" class="button button-primary wpfchs-fix-preview wpfchs-action-selected" data-check="' . esc_attr( $check->get_id() ) . '" data-selected-only="1" hidden>' . esc_html__( 'Fix selected', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
		}

		echo '<button type="button" class="button wpfchs-ignore-selected wpfchs-action-selected" hidden>' . esc_html__( 'Ignore selected', 'catalog-health-scanner-for-woocommerce' ) . '</button>';

		echo '<button type="button" class="button-link wpfchs-ignore-all" data-check="' . esc_attr( $check->get_id() ) . '" data-total="' . esc_attr( $total ) . '">';
		printf(
			/* translators: %s: number of matching issues. */
			esc_html( _n( 'Ignore all %s product', 'Ignore all %s products', $total, 'catalog-health-scanner-for-woocommerce' ) ),
			esc_html( number_format_i18n( $total ) )
		);
		echo '</button>';

		echo '</span>';
		echo '</div>';

	}

	/**
	 * render_term_select.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $taxonomy
	 * @param   string $label
	 */
	protected function render_term_select( $taxonomy, $label ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		echo '<label>' . esc_html( $label ) . ' ';
		echo '<select class="wpfchs-bulk-value" data-arg="term_id">';
		echo '<option value="">' . esc_html__( '— Select —', 'catalog-health-scanner-for-woocommerce' ) . '</option>';
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				echo '<option value="' . esc_attr( $term->term_id ) . '">' . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select></label>';
	}

	/**
	 * render_products_table.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   WPFCHS_Check $check
	 * @param   array        $issues
	 */
	protected function render_products_table( $check, $issues ) {

		echo '<table class="widefat striped wpfchs-table wpfchs-products-table">';
		echo '<thead><tr>';
		echo '<th class="wpfchs-col-cb"><input type="checkbox" class="wpfchs-select-all" /></th>';
		echo '<th class="wpfchs-col-thumb"></th>';
		echo '<th>' . esc_html__( 'Product', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Offending value', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th class="wpfchs-col-actions">' . esc_html__( 'Actions', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $issues as $issue ) {

			$product  = wc_get_product( $issue->object_id );
			$edit_url = get_edit_post_link( $issue->product_id, 'url' );
			$title    = ( $product ? $product->get_name() : get_the_title( $issue->object_id ) );
			$types    = wc_get_product_types();

			echo '<tr data-issue="' . esc_attr( $issue->id ) . '" data-object="' . esc_attr( $issue->object_id ) . '">';
			echo '<td><input type="checkbox" class="wpfchs-select-row" value="' . esc_attr( $issue->object_id ) . '" data-issue="' . esc_attr( $issue->id ) . '" /></td>';
			echo '<td>' . wp_kses_post( $product ? $product->get_image( array( 40, 40 ) ) : '' ) . '</td>';
			echo '<td>';
			if ( $edit_url ) {
				echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ) . '</a>';
			} else {
				echo esc_html( $title );
			}
			if ( $product && '' !== $product->get_sku() ) {
				echo '<div class="wpfchs-muted">' . esc_html( $product->get_sku() ) . '</div>';
			}
			echo '</td>';
			echo '<td class="wpfchs-muted">' . esc_html( $product ? ( $types[ $product->get_type() ] ?? $product->get_type() ) : '—' ) . '</td>';
			echo '<td class="wpfchs-muted">' . esc_html( (string) $issue->issue_value ) . '</td>';
			echo '<td class="wpfchs-col-actions">';
			if ( $edit_url ) {
				echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'catalog-health-scanner-for-woocommerce' ) . '</a> | ';
			}
			echo '<button type="button" class="button-link wpfchs-ignore-issue" data-issue="' . esc_attr( $issue->id ) . '">' . esc_html__( 'Ignore', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
			echo '</td>';
			echo '</tr>';

		}

		echo '</tbody></table>';

	}

	/**
	 * render_table_footer.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   WPFCHS_Check $check
	 * @param   int          $offset
	 * @param   int          $per_page
	 * @param   int          $total
	 */
	protected function render_table_footer( $check, $offset, $per_page, $total ) {

		$page  = (int) floor( $offset / $per_page ) + 1;
		$pages = max( 1, (int) ceil( $total / $per_page ) );

		echo '<div class="wpfchs-table-footer">';
		echo '<span class="wpfchs-muted">';
		printf(
			/* translators: %s: number of matching products. */
			esc_html( _n( '%s product', '%s products', $total, 'catalog-health-scanner-for-woocommerce' ) ),
			esc_html( number_format_i18n( $total ) )
		);
		echo '</span>';
		echo '<span class="wpfchs-table-pagination">';
		echo '<button type="button" class="button wpfchs-page-prev" data-offset="' . esc_attr( max( 0, $offset - $per_page ) ) . '"' . ( $page <= 1 ? ' disabled' : '' ) . '>&lsaquo;</button>';
		echo '<span class="wpfchs-muted">';
		printf(
			/* translators: %1$d: current page, %2$d: total pages. */
			esc_html__( '%1$d of %2$d', 'catalog-health-scanner-for-woocommerce' ),
			(int) $page,
			(int) $pages
		);
		echo '</span>';
		echo '<button type="button" class="button wpfchs-page-next" data-offset="' . esc_attr( $offset + $per_page ) . '"' . ( $page >= $pages ? ' disabled' : '' ) . '>&rsaquo;</button>';
		echo '</span>';
		echo '</div>';

	}

	/**
	 * ignore_issue.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function ignore_issue() {
		$this->gate();

		$issue_id = isset( $_POST['issue_id'] ) ? absint( wp_unslash( $_POST['issue_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.

		if ( ! wpfchs()->core->issues->ignore( $issue_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not ignore this issue.', 'catalog-health-scanner-for-woocommerce' ) ) );
		}

		$issue = wpfchs()->core->issues->get( $issue_id );
		if ( $issue ) {
			wpfchs()->core->issues->update_counts_for_objects( array( $issue->object_id ) );
		}

		wp_send_json_success();
	}

	/**
	 * restore_issue.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function restore_issue() {
		$this->gate();

		$issue_id = isset( $_POST['issue_id'] ) ? absint( wp_unslash( $_POST['issue_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.

		if ( ! wpfchs()->core->issues->restore( $issue_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not restore this issue.', 'catalog-health-scanner-for-woocommerce' ) ) );
		}

		$issue = wpfchs()->core->issues->get( $issue_id );
		if ( $issue ) {
			wpfchs()->core->issues->update_counts_for_objects( array( $issue->object_id ) );
		}

		wp_send_json_success();
	}

	/**
	 * Reads the filter set a category tab currently has applied, so a bulk
	 * action never reaches beyond what the user can see.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array
	 */
	protected function read_filter_args() {

		$args = array();

		$check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		if ( '' !== $check_id ) {
			$args['check_id'] = $check_id;
		}

		$category = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		if ( '' !== $category ) {
			$args['category'] = $category;
		}

		$product_cat = isset( $_POST['product_cat'] ) ? absint( wp_unslash( $_POST['product_cat'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		if ( $product_cat ) {
			$args['product_cat'] = $product_cat;
		}

		$product_type = isset( $_POST['product_type'] ) ? sanitize_key( wp_unslash( $_POST['product_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		if ( '' !== $product_type ) {
			$args['product_type'] = $product_type;
		}

		if ( ! empty( $_POST['new_since'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
			$last = wpfchs()->core->scanner->get_last_completed();
			if ( $last ) {
				$args['first_seen_scan'] = (int) $last->id;
			}
		}

		return $args;

	}

	/**
	 * ignore_bulk: ignore an explicit selection, or every open issue matching
	 * the active filters ("Ignore all").
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function ignore_bulk() {
		$this->gate();

		$core       = wpfchs()->core;
		$issue_ids  = isset( $_POST['issue_ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['issue_ids'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		$ignore_all = ! empty( $_POST['all'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.

		if ( $ignore_all ) {
			$count = $core->issues->ignore_matching( $this->read_filter_args() );
		} else {
			if ( empty( $issue_ids ) ) {
				wp_send_json_error( array( 'message' => __( 'Select at least one product first.', 'catalog-health-scanner-for-woocommerce' ) ) );
			}
			$object_ids = $core->issues->get_object_ids_for_issues( $issue_ids );
			$count      = $core->issues->set_status_for_ids( $issue_ids, 'ignored' );
			$core->issues->update_counts_for_objects( $object_ids );
		}

		if ( $count < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Nothing left to ignore here.', 'catalog-health-scanner-for-woocommerce' ) ) );
		}

		wp_send_json_success(
			array(
				'count'   => $count,
				'message' => sprintf(
					/* translators: %s: number of issues ignored. */
					_n( '%s issue ignored. It counts as passing until you restore it from Settings. Re-run the scan to refresh your score.', '%s issues ignored. They count as passing until you restore them from Settings. Re-run the scan to refresh your score.', $count, 'catalog-health-scanner-for-woocommerce' ),
					number_format_i18n( $count )
				),
			)
		);
	}

	/**
	 * restore_bulk: restore an explicit selection, or every ignored issue.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function restore_bulk() {
		$this->gate();

		$core        = wpfchs()->core;
		$issue_ids   = isset( $_POST['issue_ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['issue_ids'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		$restore_all = ! empty( $_POST['all'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.

		if ( $restore_all ) {
			$count = $core->issues->restore_matching( array() );
		} else {
			if ( empty( $issue_ids ) ) {
				wp_send_json_error( array( 'message' => __( 'Select at least one product first.', 'catalog-health-scanner-for-woocommerce' ) ) );
			}
			$object_ids = $core->issues->get_object_ids_for_issues( $issue_ids );
			$count      = $core->issues->set_status_for_ids( $issue_ids, 'open' );
			$core->issues->update_counts_for_objects( $object_ids );
		}

		if ( $count < 1 ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to restore.', 'catalog-health-scanner-for-woocommerce' ) ) );
		}

		wp_send_json_success(
			array(
				'count'   => $count,
				'message' => sprintf(
					/* translators: %s: number of issues restored. */
					_n( '%s issue restored. Re-run the scan to refresh your score.', '%s issues restored. Re-run the scan to refresh your score.', $count, 'catalog-health-scanner-for-woocommerce' ),
					number_format_i18n( $count )
				),
			)
		);
	}

	/**
	 * Reads and sanitizes fix inputs shared by preview and apply.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array {check_id, object_ids, args}
	 */
	protected function read_fix_request() {

		$check_id = isset( $_POST['check_id'] ) ? sanitize_key( wp_unslash( $_POST['check_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.

		$selected   = isset( $_POST['object_ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['object_ids'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		$object_ids = wpfchs()->core->fixes->get_target_object_ids( $check_id, $selected );

		$raw_args = isset( $_POST['args'] ) ? map_deep( wp_unslash( (array) $_POST['args'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		$args     = array();
		if ( isset( $raw_args['value'] ) ) {
			$args['value'] = $raw_args['value'];
		}
		if ( isset( $raw_args['term_id'] ) ) {
			$args['term_id'] = absint( $raw_args['term_id'] );
		}
		if ( isset( $raw_args['percent'] ) ) {
			$args['percent'] = (float) $raw_args['percent'];
		}

		return array( $check_id, $object_ids, $args );

	}

	/**
	 * fix_preview: exact before/after per product, nothing written.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function fix_preview() {
		$this->gate();

		list( $check_id, $object_ids, $args ) = $this->read_fix_request();

		$check   = wpfchs()->core->checks->get( $check_id );
		$preview = wpfchs()->core->fixes->preview( $check_id, $object_ids, $args );

		if ( is_wp_error( $preview ) ) {
			wp_send_json_error( array( 'message' => $preview->get_error_message() ) );
		}

		if ( 0 === $preview['total'] ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to change with these settings. Check the bulk value.', 'catalog-health-scanner-for-woocommerce' ) ) );
		}

		ob_start();

		echo '<table class="widefat striped wpfchs-table">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'Current', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '<th>' . esc_html__( 'After fix', 'catalog-health-scanner-for-woocommerce' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $preview['rows'] as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['title'] ) . '</td>';
			echo '<td class="wpfchs-before">' . esc_html( $row['before_label'] ) . '</td>';
			echo '<td class="wpfchs-after">' . esc_html( $row['after_label'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $preview['total'] > count( $preview['rows'] ) ) {
			echo '<p class="wpfchs-muted">';
			printf(
				/* translators: %1$d: rows shown, %2$d: total products affected. */
				esc_html__( 'Showing %1$d of %2$d.', 'catalog-health-scanner-for-woocommerce' ),
				(int) count( $preview['rows'] ),
				(int) $preview['total']
			);
			echo '</p>';
		}

		echo '<p class="wpfchs-muted">';
		printf(
			/* translators: %d: undo window in days. */
			esc_html__( 'This action can be undone for %d days.', 'catalog-health-scanner-for-woocommerce' ),
			(int) wpfchs()->core->get_threshold( 'undo_window_days' )
		);
		echo '</p>';

		$html = ob_get_clean();

		wp_send_json_success(
			array(
				'title' => sprintf(
					/* translators: %1$s: check label, %2$d: number of products. */
					__( 'Preview: %1$s — %2$d products', 'catalog-health-scanner-for-woocommerce' ),
					( $check ? $check->get_label() : $check_id ),
					$preview['total']
				),
				'html'       => $html,
				'total'      => $preview['total'],
				'object_ids' => $object_ids,
				// Free applies to one product at a time; the JS layer swaps
				// the apply button for an upgrade link when this is true.
				'pro_required' => ( ! wpfchs()->is_pro() && $preview['total'] > 1 ),
				'upgrade'      => WPFCHS_Upgrade::URL,
			)
		);
	}

	/**
	 * fix_apply.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function fix_apply() {
		$this->gate();

		list( $check_id, $object_ids, $args ) = $this->read_fix_request();

		$this->gate_bulk_fix( count( $object_ids ) );

		$result = wpfchs()->core->fixes->apply( $check_id, $object_ids, $args );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * fix_undo.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function fix_undo() {
		$this->gate();

		$log_id = isset( $_POST['log_id'] ) ? absint( wp_unslash( $_POST['log_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability are verified in gate(), called as the first statement of every handler.
		$result = wpfchs()->core->fixes->undo( $log_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( $result );
	}

}

endif;

return new WPFCHS_Ajax();
