<?php
/**
 * Catalog Health Scanner for WooCommerce - Admin Category Tab Class
 *
 * One tab per check category: category score, applicability state,
 * expandable issue groups, product tables, bulk actions.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Admin_Category' ) ) :

class WPFCHS_Admin_Category {

	/**
	 * Active list filters from the URL (spec 8.2).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array {severity, product_cat, product_type, new_since}
	 */
	function get_filters() {
		$severity     = filter_input( INPUT_GET, 'severity', FILTER_SANITIZE_SPECIAL_CHARS );
		$product_cat  = filter_input( INPUT_GET, 'product_cat', FILTER_SANITIZE_NUMBER_INT );
		$product_type = filter_input( INPUT_GET, 'product_type', FILTER_SANITIZE_SPECIAL_CHARS );
		$new_since    = filter_input( INPUT_GET, 'new_since', FILTER_SANITIZE_NUMBER_INT );
		return array(
			'severity'     => ( is_string( $severity ) ? sanitize_key( $severity ) : '' ),
			'product_cat'  => absint( $product_cat ),
			'product_type' => ( is_string( $product_type ) ? sanitize_key( $product_type ) : '' ),
			'new_since'    => (bool) $new_since,
		);
	}

	/**
	 * Maps UI filters to issue-query args.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array $filters
	 * @return  array
	 */
	function filters_to_args( $filters ) {
		$args = array();
		if ( $filters['product_cat'] ) {
			$args['product_cat'] = $filters['product_cat'];
		}
		if ( '' !== $filters['product_type'] ) {
			$args['product_type'] = $filters['product_type'];
		}
		if ( $filters['new_since'] ) {
			$last = wpfchs()->core->scanner->get_last_completed();
			if ( $last ) {
				$args['first_seen_scan'] = (int) $last->id;
			}
		}
		return $args;
	}

	/**
	 * render.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $category
	 */
	function render( $category ) {

		$core       = wpfchs()->core;
		$categories = $core->checks->get_categories();
		$label      = ( $categories[ $category ] ?? $category );

		$this->render_header( $category, $label );

		$checks      = $core->checks->get_by_category( $category );
		$disabled    = $core->checks->get_disabled();
		$filters     = $this->get_filters();
		$open_counts = $core->issues->count_open_by_check_filtered(
			array_merge( array( 'category' => $category ), $this->filters_to_args( $filters ) )
		);

		$focus = filter_input( INPUT_GET, 'check', FILTER_SANITIZE_SPECIAL_CHARS );
		$focus = ( is_string( $focus ) ? sanitize_key( $focus ) : '' );

		$groups  = array();
		$passing = 0;
		foreach ( $checks as $check_id => $check ) {
			if ( in_array( $check_id, $disabled, true ) ) {
				continue;
			}
			if ( '' !== $filters['severity'] && $check->get_severity() !== $filters['severity'] ) {
				continue;
			}
			$count = ( $open_counts[ $check_id ] ?? 0 );
			if ( $count > 0 ) {
				$groups[ $check_id ] = array(
					'check' => $check,
					'count' => $count,
				);
			} else {
				$passing++;
			}
		}

		uasort(
			$groups,
			function ( $a, $b ) {
				$severity_order = array( 'critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3 );
				$cmp            = $severity_order[ $a['check']->get_severity() ] <=> $severity_order[ $b['check']->get_severity() ];
				return ( 0 !== $cmp ? $cmp : $b['count'] <=> $a['count'] );
			}
		);

		$this->render_toolbar( $category, $filters );

		echo '<div class="wpfchs-card wpfchs-groups"' .
			' data-product-cat="' . esc_attr( $filters['product_cat'] ) . '"' .
			' data-product-type="' . esc_attr( $filters['product_type'] ) . '"' .
			' data-new-since="' . esc_attr( $filters['new_since'] ? 1 : 0 ) . '">';

		echo '<div class="wpfchs-groups-head">';
		printf(
			/* translators: %d: number of checks with open issues. */
			esc_html( _n( '%d check with issues', '%d checks with issues', count( $groups ), 'catalog-health-scanner-for-woocommerce' ) ),
			(int) count( $groups )
		);
		echo '<span class="wpfchs-muted">' . esc_html__( 'Sorted by severity', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
		echo '</div>';

		if ( empty( $groups ) ) {
			echo '<p class="wpfchs-panel-empty">' . esc_html__( 'No open issues in this category.', 'catalog-health-scanner-for-woocommerce' ) . '</p>';
		}

		foreach ( $groups as $check_id => $group ) {
			$this->render_group( $group['check'], $group['count'], ( $check_id === $focus ) );
		}

		echo '</div>';

		if ( $passing > 0 ) {
			echo '<p class="wpfchs-muted wpfchs-passing-note">';
			printf(
				/* translators: %d: number of passing checks. */
				esc_html( _n( '%d other check in this category is passing on all products.', '%d other checks in this category are passing on all products.', $passing, 'catalog-health-scanner-for-woocommerce' ) ),
				(int) $passing
			);
			echo '</p>';
		}

	}

	/**
	 * render_header.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $category
	 * @param   string $label
	 */
	function render_header( $category, $label ) {

		$core = wpfchs()->core;
		$last = $core->admin->get_last_scan_data();
		$open = $core->issues->count(
			array(
				'category' => $category,
				'status'   => 'open',
			)
		);

		// Applicability summary: use the first grouped check's resolution.
		$group_key = '';
		foreach ( $core->checks->get_by_category( $category ) as $check ) {
			if ( '' !== $check->get_group() ) {
				$group_key = $check->get_group();
				break;
			}
		}

		echo '<div class="wpfchs-card wpfchs-category-header">';

		echo '<div class="wpfchs-category-header-main">';
		echo '<h2>' . esc_html( $label ) . '</h2>';

		$score = ( $last['categories'][ $category ] ?? null );
		if ( null !== $score ) {
			$band = $core->scores->get_category_band( $score['earned'], $score['possible'] );
			echo '<span class="wpfchs-category-score">' . esc_html( wc_format_decimal( $score['earned'], 1 ) ) . ' <span class="wpfchs-muted">/ ' . esc_html( wc_format_decimal( $score['possible'], 0 ) ) . '</span></span>';
			echo '<span class="wpfchs-band" style="color:' . esc_attr( $band['color'] ) . '">' . esc_html( $band['label'] ) . '</span>';
			$percent = ( $score['possible'] > 0 ? round( ( $score['earned'] / $score['possible'] ) * 100 ) : 100 );
			echo '<div class="wpfchs-card-bar wpfchs-category-bar"><div style="width:' . esc_attr( $percent ) . '%;background:' . esc_attr( $band['color'] ) . '"></div></div>';
		}

		echo '<div class="wpfchs-muted">';
		printf(
			/* translators: %s: number of open issues. */
			esc_html( _n( '%s open issue', '%s open issues', $open, 'catalog-health-scanner-for-woocommerce' ) ),
			esc_html( number_format_i18n( $open ) )
		);
		echo '</div>';
		echo '</div>';

		if ( '' !== $group_key ) {
			$resolve = $core->applicability->resolve( $group_key );
			echo '<div class="wpfchs-category-header-side">';
			if ( $resolve['applicable'] && $resolve['scored'] ) {
				echo '<span class="wpfchs-applicable">' . esc_html__( 'Applicable', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
			} elseif ( $resolve['applicable'] ) {
				echo '<span class="wpfchs-applicable wpfchs-report-only">' . esc_html__( 'Reported, not scored', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
			} else {
				echo '<span class="wpfchs-not-applicable">' . esc_html__( 'Not applicable', 'catalog-health-scanner-for-woocommerce' ) . '</span>';
			}
			echo ' &mdash; ' . esc_html( $resolve['reason'] ) . ' ';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=wpfchs-settings#wpfchs-applicability' ) ) . '">' . esc_html__( 'Change', 'catalog-health-scanner-for-woocommerce' ) . '</a>';
			echo '</div>';
		}

		echo '</div>';

	}

	/**
	 * Toolbar: a GET form so every filter is server-side, shareable, and
	 * survives reloads.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $category
	 * @param   array  $filters
	 */
	function render_toolbar( $category, $filters ) {

		$export_url = wpfchs()->core->export->get_url( array( 'category' => $category ) );

		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="wpfchs-card wpfchs-toolbar">';
		echo '<input type="hidden" name="page" value="wpfchs" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $category ) . '" />';

		echo '<select name="severity">';
		echo '<option value="">' . esc_html__( 'All severities', 'catalog-health-scanner-for-woocommerce' ) . '</option>';
		foreach ( array(
			'critical' => __( 'Critical', 'catalog-health-scanner-for-woocommerce' ),
			'high'     => __( 'High', 'catalog-health-scanner-for-woocommerce' ),
			'medium'   => __( 'Medium', 'catalog-health-scanner-for-woocommerce' ),
			'low'      => __( 'Low', 'catalog-health-scanner-for-woocommerce' ),
		) as $severity => $severity_label ) {
			echo '<option value="' . esc_attr( $severity ) . '"' . selected( $severity, $filters['severity'], false ) . '>' . esc_html( $severity_label ) . '</option>';
		}
		echo '</select>';

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'number'     => 500,
			)
		);
		echo '<select name="product_cat">';
		echo '<option value="">' . esc_html__( 'All product categories', 'catalog-health-scanner-for-woocommerce' ) . '</option>';
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				echo '<option value="' . esc_attr( $term->term_id ) . '"' . selected( (int) $term->term_id, $filters['product_cat'], false ) . '>' . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select>';

		echo '<select name="product_type">';
		echo '<option value="">' . esc_html__( 'All product types', 'catalog-health-scanner-for-woocommerce' ) . '</option>';
		foreach ( wc_get_product_types() as $type => $type_label ) {
			echo '<option value="' . esc_attr( $type ) . '"' . selected( $type, $filters['product_type'], false ) . '>' . esc_html( $type_label ) . '</option>';
		}
		echo '</select>';

		echo '<label class="wpfchs-toolbar-check"><input type="checkbox" name="new_since" value="1"' . checked( $filters['new_since'], true, false ) . ' /> ' . esc_html__( 'New since last scan', 'catalog-health-scanner-for-woocommerce' ) . '</label>';

		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'catalog-health-scanner-for-woocommerce' ) . '</button>';

		echo '<a class="button wpfchs-toolbar-right" href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export CSV', 'catalog-health-scanner-for-woocommerce' ) . '</a>';

		echo '</form>';

	}

	/**
	 * render_group.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   WPFCHS_Check $check
	 * @param   int          $count
	 * @param   bool         $expanded
	 */
	function render_group( $check, $count, $expanded = false ) {

		$check_id = $check->get_id();
		$fix_type = ( $check->get_fixer() ? $check->get_fix_type() : 'manual' );

		echo '<div class="wpfchs-group' . ( $expanded ? ' wpfchs-group-open' : '' ) . '" data-check="' . esc_attr( $check_id ) . '" data-severity="' . esc_attr( $check->get_severity() ) . '" data-fix-type="' . esc_attr( $fix_type ) . '">';

		echo '<div class="wpfchs-group-row" role="button" tabindex="0" aria-expanded="' . ( $expanded ? 'true' : 'false' ) . '">';
		echo wp_kses_post( wpfchs()->core->admin->severity_badge( $check->get_severity() ) );
		echo '<span class="wpfchs-group-main">';
		echo '<strong>' . esc_html( $check->get_label() ) . '</strong>';
		echo '<span class="wpfchs-muted">' . esc_html( $check->get_explanation() ) . '</span>';
		echo '</span>';
		echo '<span class="wpfchs-group-count">';
		printf(
			/* translators: %s: number of affected products. */
			esc_html( _n( '%s product', '%s products', $count, 'catalog-health-scanner-for-woocommerce' ) ),
			esc_html( number_format_i18n( $count ) )
		);
		echo '</span>';

		if ( 'auto' === $fix_type ) {
			echo '<button type="button" class="button button-primary wpfchs-fix-preview" data-check="' . esc_attr( $check_id ) . '">' . esc_html__( 'Fix all', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
		} elseif ( 'bulk' === $fix_type ) {
			echo '<button type="button" class="button button-primary wpfchs-group-toggle-btn">' . esc_html__( 'Bulk assign', 'catalog-health-scanner-for-woocommerce' ) . '</button>';
		} else {
			// These checks have no automatic fix, so the useful next step is
			// getting to the products themselves. "Review" only restated the
			// row click; naming the destination and its size tells the user
			// what they are about to take on.
			echo '<button type="button" class="button wpfchs-group-toggle-btn">';
			printf(
				/* translators: %s: number of affected products. */
				esc_html( _n( 'View %s product', 'View %s products', $count, 'catalog-health-scanner-for-woocommerce' ) ),
				esc_html( number_format_i18n( $count ) )
			);
			echo '</button>';
		}

		echo '<span class="wpfchs-group-caret" aria-hidden="true"></span>';
		echo '</div>';

		echo '<div class="wpfchs-group-body"' . ( $expanded ? '' : ' hidden' ) . '>';
		echo '<div class="wpfchs-group-body-inner" data-loaded="0"></div>';
		echo '</div>';

		echo '</div>';

	}

}

endif;

return new WPFCHS_Admin_Category();
