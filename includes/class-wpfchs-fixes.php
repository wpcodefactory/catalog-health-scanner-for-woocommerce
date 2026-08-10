<?php
/**
 * Catalog Health Scanner for WooCommerce - Fixes Class
 *
 * Every bulk action here is previewable (exact before/after per product),
 * reversible (one-click undo for the whole batch within the undo window),
 * and logged (user, timestamp, action, affected products).
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Fixes' ) ) :

class WPFCHS_Fixes {

	/**
	 * fixlog table.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function table() {
		global $wpdb;
		return $wpdb->prefix . 'wpfchs_fixlog';
	}

	/**
	 * Open-issue object ids for a check (the fix target set).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $check_id
	 * @param   array  $only_object_ids Optional subset selection from the UI.
	 * @return  array
	 */
	function get_target_object_ids( $check_id, $only_object_ids = array() ) {
		$rows = wpfchs()->core->issues->query(
			array(
				'check_id' => $check_id,
				'status'   => 'open',
				'limit'    => 5000,
			)
		);
		$ids = wp_list_pluck( $rows, 'object_id' );
		$ids = array_map( 'intval', $ids );
		if ( ! empty( $only_object_ids ) ) {
			$ids = array_values( array_intersect( $ids, array_map( 'intval', $only_object_ids ) ) );
		}
		return $ids;
	}

	/**
	 * Builds the preview: exact before and after values, nothing written.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $check_id
	 * @param   array  $object_ids
	 * @param   array  $args       Bulk value args (value, term_id, percent).
	 * @return  array|WP_Error     {rows: array, total: int, fixer: string}
	 */
	function preview( $check_id, $object_ids, $args = array() ) {

		$check = wpfchs()->core->checks->get( $check_id );
		if ( ! $check || ! $check->get_fixer() ) {
			return new WP_Error( 'wpfchs_no_fixer', __( 'This check has no automatic fix.', 'catalog-health-scanner-for-woocommerce' ) );
		}

		$rows  = array();
		$fixer = $check->get_fixer();

		foreach ( $object_ids as $object_id ) {
			$change = $this->compute_change( $fixer, (int) $object_id, $args );
			if ( null !== $change ) {
				$rows[] = $change;
			}
		}

		return array(
			'fixer' => $fixer,
			'total' => count( $rows ),
			'rows'  => array_slice( $rows, 0, 200 ),
		);

	}

	/**
	 * Applies a fix batch: writes the changes, resolves the issues, logs
	 * everything needed for undo.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $check_id
	 * @param   array  $object_ids
	 * @param   array  $args
	 * @return  array|WP_Error {log_id: int, fixed: int}
	 */
	function apply( $check_id, $object_ids, $args = array() ) {
		global $wpdb;

		$check = wpfchs()->core->checks->get( $check_id );
		if ( ! $check || ! $check->get_fixer() ) {
			return new WP_Error( 'wpfchs_no_fixer', __( 'This check has no automatic fix.', 'catalog-health-scanner-for-woocommerce' ) );
		}

		$fixer = $check->get_fixer();
		$items = array();

		foreach ( $object_ids as $object_id ) {
			$object_id = (int) $object_id;
			$change    = $this->compute_change( $fixer, $object_id, $args );
			if ( null === $change ) {
				continue;
			}
			if ( $this->write_change( $fixer, $object_id, $change, $args ) ) {
				$items[ $object_id ] = array(
					'before' => $change['before'],
					'after'  => $change['after'],
				);
			}
		}

		if ( empty( $items ) ) {
			return new WP_Error( 'wpfchs_nothing_fixed', __( 'Nothing needed fixing.', 'catalog-health-scanner-for-woocommerce' ) );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom fix log table; no WP API exists.
		$wpdb->insert(
			$this->table(),
			array(
				'user_id'     => get_current_user_id(),
				'check_id'    => $check_id,
				'fixer'       => $fixer,
				'created_at'  => current_time( 'mysql', true ),
				'items'       => wp_json_encode( $items ),
				'items_count' => count( $items ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$log_id = (int) $wpdb->insert_id;

		wpfchs()->core->issues->resolve_for_objects( $check_id, array_keys( $items ) );
		wpfchs()->core->issues->update_counts_for_objects( array_keys( $items ) );

		do_action( 'wpfchs_fix_applied', $log_id, $check_id, $items );

		return array(
			'log_id' => $log_id,
			'fixed'  => count( $items ),
		);

	}

	/**
	 * Builds a combined preview of every auto-fixable quick win: per-check
	 * counts plus a sample of before/after rows. Nothing is written.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $sample_per_check Rows to include per check in the preview.
	 * @return  array {total: int, checks: array, rows: array}
	 */
	function preview_all_quick_wins( $sample_per_check = 4 ) {

		$open_counts = wpfchs()->core->issues->count_open_by_check();
		$checks_out  = array();
		$rows        = array();
		$total       = 0;

		foreach ( wpfchs()->core->checks->get_all() as $check_id => $check ) {

			if ( 'auto' !== $check->get_fix_type() || ! $check->get_fixer() ) {
				continue;
			}
			if ( empty( $open_counts[ $check_id ] ) ) {
				continue;
			}

			$object_ids = $this->get_target_object_ids( $check_id );
			$changes    = 0;
			$samples    = array();

			foreach ( $object_ids as $object_id ) {
				$change = $this->compute_change( $check->get_fixer(), (int) $object_id );
				if ( null === $change ) {
					continue;
				}
				$changes++;
				if ( count( $samples ) < $sample_per_check ) {
					$samples[] = $change;
				}
			}

			if ( $changes < 1 ) {
				continue;
			}

			$total          += $changes;
			$checks_out[]    = array(
				'label' => $check->get_label(),
				'count' => $changes,
			);
			foreach ( $samples as $sample ) {
				$rows[] = array(
					'check'  => $check->get_label(),
					'title'  => $sample['title'],
					'before' => $sample['before_label'],
					'after'  => $sample['after_label'],
				);
			}
		}

		return array(
			'total'  => $total,
			'checks' => $checks_out,
			'rows'   => $rows,
		);

	}

	/**
	 * Applies every auto-fixable check that currently has open issues, in one
	 * pass. Each check is logged as its own batch, so each stays individually
	 * undoable. Used by the "Fix all quick wins" button and by unattended
	 * post-scan auto-remediation.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   array|null $only_check_ids Restrict to these checks (null = all auto-fixable).
	 * @return  array {checks_fixed: int, products_fixed: int, log_ids: array, details: array}
	 */
	function fix_all_quick_wins( $only_check_ids = null ) {

		$open_counts = wpfchs()->core->issues->count_open_by_check();
		$log_ids     = array();
		$details     = array();
		$total       = 0;

		foreach ( wpfchs()->core->checks->get_all() as $check_id => $check ) {

			if ( 'auto' !== $check->get_fix_type() || ! $check->get_fixer() ) {
				continue;
			}
			if ( empty( $open_counts[ $check_id ] ) ) {
				continue;
			}
			if ( null !== $only_check_ids && ! in_array( $check_id, $only_check_ids, true ) ) {
				continue;
			}

			$object_ids = $this->get_target_object_ids( $check_id );
			$result     = $this->apply( $check_id, $object_ids );

			if ( ! is_wp_error( $result ) ) {
				$log_ids[]              = $result['log_id'];
				$details[ $check_id ]   = $result['fixed'];
				$total                 += $result['fixed'];
			}
		}

		return array(
			'checks_fixed'   => count( $details ),
			'products_fixed' => $total,
			'log_ids'        => $log_ids,
			'details'        => $details,
		);

	}

	/**
	 * Undoes an entire fix batch.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $log_id
	 * @return  array|WP_Error {restored: int}
	 */
	function undo( $log_id ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom fix log table; no WP API exists.
		$log = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpfchs_fixlog WHERE id = %d", $log_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( ! $log || $log->undone ) {
			return new WP_Error( 'wpfchs_no_log', __( 'Fix batch not found or already undone.', 'catalog-health-scanner-for-woocommerce' ) );
		}

		$window_days = (int) wpfchs()->core->get_threshold( 'undo_window_days' );
		if ( strtotime( $log->created_at ) < ( time() - $window_days * DAY_IN_SECONDS ) ) {
			return new WP_Error( 'wpfchs_undo_expired', __( 'The undo window for this fix has expired.', 'catalog-health-scanner-for-woocommerce' ) );
		}

		$items    = (array) json_decode( (string) $log->items, true );
		$restored = 0;

		foreach ( $items as $object_id => $item ) {
			if ( $this->revert_change( $log->fixer, (int) $object_id, $item ) ) {
				$restored++;
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom fix log table; no WP API exists.
		$wpdb->update(
			$this->table(),
			array(
				'undone'    => 1,
				'undone_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $log_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		wpfchs()->core->issues->reopen_for_objects( $log->check_id, array_keys( $items ) );
		wpfchs()->core->issues->update_counts_for_objects( array_keys( $items ) );

		do_action( 'wpfchs_fix_undone', $log_id, $log->check_id, $items );

		return array( 'restored' => $restored );

	}

	/**
	 * Recent fix log rows (activity log).
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $limit
	 * @return  array
	 */
	function get_log( $limit = 25 ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Custom fix log table; no WP API exists.
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, check_id, fixer, created_at, items_count, undone FROM {$wpdb->prefix}wpfchs_fixlog ORDER BY id DESC LIMIT %d",
				absint( $limit )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Computes the change a fixer would make on one object.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $fixer
	 * @param   int    $object_id
	 * @param   array  $args
	 * @return  array|null {object_id, title, before, after, before_label, after_label} or null when no change.
	 */
	function compute_change( $fixer, $object_id, $args = array() ) {
		global $wpdb;

		$product = wc_get_product( $object_id );

		if ( 'delete_orphaned_variations' === $fixer ) {
			if ( 'product_variation' !== get_post_type( $object_id ) ) {
				return null;
			}
			return array(
				'object_id'    => $object_id,
				'title'        => get_the_title( $object_id ),
				'before'       => get_post_status( $object_id ),
				'after'        => 'trash',
				'before_label' => __( 'Orphaned variation', 'catalog-health-scanner-for-woocommerce' ),
				'after_label'  => __( 'Moved to trash', 'catalog-health-scanner-for-woocommerce' ),
			);
		}

		if ( ! $product ) {
			return null;
		}

		$row = array(
			'object_id' => $object_id,
			'title'     => $product->get_name(),
		);

		switch ( $fixer ) {

			case 'clear_expired_sale':
				$to = $product->get_date_on_sale_to( 'edit' );
				if ( '' === $product->get_sale_price( 'edit' ) || ! $to || $to->getTimestamp() >= time() ) {
					return null;
				}
				$from = $product->get_date_on_sale_from( 'edit' );
				$row['before']       = array(
					'sale_price' => $product->get_sale_price( 'edit' ),
					'date_from'  => ( $from ? $from->date( 'Y-m-d H:i:s' ) : '' ),
					'date_to'    => $to->date( 'Y-m-d H:i:s' ),
				);
				$row['after']        = array( 'sale_price' => '', 'date_from' => '', 'date_to' => '' );
				$row['before_label'] = wc_format_decimal( $product->get_sale_price( 'edit' ) );
				$row['after_label']  = __( 'Sale removed', 'catalog-health-scanner-for-woocommerce' );
				return $row;

			case 'sync_stock_status':
				$qty = $product->get_stock_quantity( 'edit' );
				if ( null === $qty || ! $product->managing_stock() ) {
					return null;
				}
				$current = $product->get_stock_status( 'edit' );
				$correct = ( $qty > 0 || $product->backorders_allowed() ? 'instock' : 'outofstock' );
				if ( $current === $correct ) {
					return null;
				}
				$row['before']       = $current;
				$row['after']        = $correct;
				$row['before_label'] = ( 'instock' === $current ? __( 'In stock', 'catalog-health-scanner-for-woocommerce' ) : __( 'Out of stock', 'catalog-health-scanner-for-woocommerce' ) );
				$row['after_label']  = ( 'instock' === $correct ? __( 'In stock', 'catalog-health-scanner-for-woocommerce' ) : __( 'Out of stock', 'catalog-health-scanner-for-woocommerce' ) );
				return $row;

			case 'clear_unmanaged_stock':
				$raw = get_post_meta( $object_id, '_stock', true );
				if ( '' === $raw || null === $raw || $product->managing_stock() ) {
					return null;
				}
				$row['before']       = (string) $raw;
				$row['after']        = '';
				$row['before_label'] = (string) $raw;
				$row['after_label']  = __( 'Cleared', 'catalog-health-scanner-for-woocommerce' );
				return $row;

			case 'clean_linked_products':
				$cross  = array_map( 'intval', $product->get_cross_sell_ids( 'edit' ) );
				$up     = array_map( 'intval', $product->get_upsell_ids( 'edit' ) );
				$valid  = function ( $id ) {
					return ( 'product' === get_post_type( $id ) && 'publish' === get_post_status( $id ) );
				};
				$cross_clean = array_values( array_filter( $cross, $valid ) );
				$up_clean    = array_values( array_filter( $up, $valid ) );
				if ( count( $cross_clean ) === count( $cross ) && count( $up_clean ) === count( $up ) ) {
					return null;
				}
				$removed             = ( count( $cross ) - count( $cross_clean ) ) + ( count( $up ) - count( $up_clean ) );
				$row['before']       = array( 'cross' => $cross, 'up' => $up );
				$row['after']        = array( 'cross' => $cross_clean, 'up' => $up_clean );
				/* translators: %d: number of linked product references. */
				$row['before_label'] = sprintf( __( '%d broken link(s)', 'catalog-health-scanner-for-woocommerce' ), $removed );
				$row['after_label']  = __( 'References removed', 'catalog-health-scanner-for-woocommerce' );
				return $row;

			case 'clean_gallery':
				$gallery = array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) );
				$clean   = array_values(
					array_filter(
						$gallery,
						function ( $id ) {
							return ( 'attachment' === get_post_type( $id ) );
						}
					)
				);
				if ( count( $clean ) === count( $gallery ) ) {
					return null;
				}
				$row['before']       = $gallery;
				$row['after']        = $clean;
				/* translators: %d: number of gallery references. */
				$row['before_label'] = sprintf( __( '%d dead reference(s)', 'catalog-health-scanner-for-woocommerce' ), count( $gallery ) - count( $clean ) );
				$row['after_label']  = __( 'References removed', 'catalog-health-scanner-for-woocommerce' );
				return $row;

			case 'clean_grouped_children':
				if ( ! $product->is_type( 'grouped' ) ) {
					return null;
				}
				$children = array_map( 'intval', $product->get_children() );
				$clean    = array_values(
					array_filter(
						$children,
						function ( $id ) {
							return ( 'product' === get_post_type( $id ) && 'publish' === get_post_status( $id ) );
						}
					)
				);
				if ( count( $clean ) === count( $children ) ) {
					return null;
				}
				$row['before']       = $children;
				$row['after']        = $clean;
				/* translators: %d: number of child references. */
				$row['before_label'] = sprintf( __( '%d broken child(ren)', 'catalog-health-scanner-for-woocommerce' ), count( $children ) - count( $clean ) );
				$row['after_label']  = __( 'References removed', 'catalog-health-scanner-for-woocommerce' );
				return $row;

			case 'unassign_deleted_shipping_class':
				// The relationship rows point at term_taxonomy rows that are
				// gone, so no CRUD getter can see them — go to the table.
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Orphan detection needs a LEFT JOIN across the taxonomy tables; no WP API exposes it.
				$orphans = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT tr.term_taxonomy_id
						FROM {$wpdb->term_relationships} tr
						LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
						WHERE tr.object_id = %d AND tt.term_taxonomy_id IS NULL",
						$object_id
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				if ( empty( $orphans ) ) {
					return null;
				}
				$row['before']       = array_map( 'intval', $orphans );
				$row['after']        = array();
				$row['before_label'] = sprintf(
					/* translators: %d: number of orphaned term references. */
					_n( '%d deleted term referenced', '%d deleted terms referenced', count( $orphans ), 'catalog-health-scanner-for-woocommerce' ),
					count( $orphans )
				);
				$row['after_label']  = __( 'Stale references removed', 'catalog-health-scanner-for-woocommerce' );
				return $row;

			case 'reset_tax_class':
				// Raw meta, not get_tax_class(): WooCommerce sanitizes an
				// unknown class to '' on read, so the getter can never see the
				// broken value this fixer exists to clear.
				$tax_class = (string) get_post_meta( $object_id, '_tax_class', true );
				if ( '' === $tax_class || 'standard' === $tax_class || in_array( $tax_class, \WC_Tax::get_tax_class_slugs(), true ) ) {
					return null;
				}
				$row['before']       = $tax_class;
				$row['after']        = '';
				$row['before_label'] = $tax_class;
				$row['after_label']  = __( 'Standard rate', 'catalog-health-scanner-for-woocommerce' );
				return $row;

			case 'clear_virtual_dimensions':
				if ( ! $product->is_virtual() || ( '' === $product->get_weight( 'edit' ) && ! $product->has_dimensions() ) ) {
					return null;
				}
				$row['before']       = array(
					'weight' => $product->get_weight( 'edit' ),
					'length' => $product->get_length( 'edit' ),
					'width'  => $product->get_width( 'edit' ),
					'height' => $product->get_height( 'edit' ),
				);
				$row['after']        = array( 'weight' => '', 'length' => '', 'width' => '', 'height' => '' );
				$row['before_label'] = trim( $product->get_weight( 'edit' ) . ' / ' . wc_format_dimensions( $product->get_dimensions( false ) ), ' /' );
				$row['after_label']  = __( 'Cleared', 'catalog-health-scanner-for-woocommerce' );
				return $row;

			case 'clean_title_artifacts':
				$title = $product->get_name( 'edit' );
				$fixed = $this->repair_encoding( $title );
				if ( $fixed === $title ) {
					return null;
				}
				$row['before']       = $title;
				$row['after']        = $fixed;
				$row['before_label'] = $title;
				$row['after_label']  = $fixed;
				return $row;

			case 'generate_skus':
				// Two checks share this fixer. `sku_missing` points at a product
				// with no SKU of its own; `variation_sku_missing` points at a
				// variable PARENT that has one while its variations do not, so
				// looking only at the parent's SKU made that fix a no-op.
				if ( '' === $product->get_sku( 'edit' ) ) {
					$sku                 = $this->generate_sku( $product );
					$row['before']       = '';
					$row['after']        = $sku;
					$row['before_label'] = __( 'No SKU', 'catalog-health-scanner-for-woocommerce' );
					$row['after_label']  = $sku;
					return $row;
				}

				if ( $product->is_type( 'variable' ) ) {
					$children = array();
					foreach ( $product->get_children() as $child_id ) {
						$variation = wc_get_product( $child_id );
						// A variation with no SKU of its own inherits the
						// parent's, so ask the raw value rather than get_sku().
						if ( $variation && '' === (string) get_post_meta( $child_id, '_sku', true ) ) {
							$children[ (int) $child_id ] = $this->generate_sku( $variation );
						}
					}
					if ( empty( $children ) ) {
						return null;
					}
					$row['before']       = array_fill_keys( array_keys( $children ), '' );
					$row['after']        = $children;
					$row['before_label'] = sprintf(
						/* translators: %d: number of variations without a SKU. */
						_n( '%d variation with no SKU', '%d variations with no SKU', count( $children ), 'catalog-health-scanner-for-woocommerce' ),
						count( $children )
					);
					$row['after_label']  = implode( ', ', array_slice( $children, 0, 3 ) ) . ( count( $children ) > 3 ? '…' : '' );
					return $row;
				}

				return null;

			case 'set_weight':
				$value = ( isset( $args['value'] ) ? wc_format_decimal( $args['value'] ) : '' );
				if ( '' === $value || '' !== $product->get_weight( 'edit' ) || $product->is_virtual() ) {
					return null;
				}
				$row['before']       = '';
				$row['after']        = $value;
				$row['before_label'] = __( 'No weight', 'catalog-health-scanner-for-woocommerce' );
				$row['after_label']  = $value . ' ' . get_option( 'woocommerce_weight_unit' );
				return $row;

			case 'assign_shipping_class':
				$term_id = absint( $args['term_id'] ?? 0 );
				$term    = ( $term_id ? get_term( $term_id, 'product_shipping_class' ) : null );
				if ( ! $term || is_wp_error( $term ) || $product->get_shipping_class_id( 'edit' ) > 0 ) {
					return null;
				}
				$row['before']       = 0;
				$row['after']        = $term_id;
				$row['before_label'] = __( 'No shipping class', 'catalog-health-scanner-for-woocommerce' );
				$row['after_label']  = $term->name;
				return $row;

			case 'assign_category':
				$term_id = absint( $args['term_id'] ?? 0 );
				$term    = ( $term_id ? get_term( $term_id, 'product_cat' ) : null );
				if ( ! $term || is_wp_error( $term ) ) {
					return null;
				}
				$current = array_map( 'intval', $product->get_category_ids( 'edit' ) );
				if ( in_array( $term_id, $current, true ) ) {
					return null;
				}
				$row['before']       = $current;
				$row['after']        = array_merge( $current, array( $term_id ) );
				$row['before_label'] = __( 'No category', 'catalog-health-scanner-for-woocommerce' );
				$row['after_label']  = $term->name;
				return $row;

			case 'assign_brand':
				$term_id  = absint( $args['term_id'] ?? 0 );
				$taxonomy = $this->get_brand_taxonomy();
				$term     = ( $term_id && $taxonomy ? get_term( $term_id, $taxonomy ) : null );
				if ( ! $term || is_wp_error( $term ) ) {
					return null;
				}
				$existing = get_the_terms( $object_id, $taxonomy );
				if ( ! empty( $existing ) && ! is_wp_error( $existing ) ) {
					return null;
				}
				$row['before']       = array();
				$row['after']        = array( $term_id );
				$row['before_label'] = __( 'No brand', 'catalog-health-scanner-for-woocommerce' );
				$row['after_label']  = $term->name;
				return $row;

			case 'assign_tax_class':
				$new_class = (string) ( $args['value'] ?? '' );
				$new_class = ( 'standard' === $new_class ? '' : $new_class );
				if ( '' !== $new_class && ! in_array( $new_class, \WC_Tax::get_tax_class_slugs(), true ) ) {
					return null;
				}
				if ( ! isset( $args['value'] ) || '' === (string) $args['value'] ) {
					return null;
				}
				// Raw meta, because the product this fixer targets frequently
				// holds an invalid class that WooCommerce sanitizes to '' on
				// read. Recording '' here would make undo restore the wrong
				// value — silently discarding what the store actually had.
				$current = (string) get_post_meta( $object_id, '_tax_class', true );
				if ( $current === $new_class ) {
					return null;
				}
				$row['before']       = $current;
				$row['after']        = $new_class;
				$row['before_label'] = ( '' !== $current ? $current : __( 'Standard rate', 'catalog-health-scanner-for-woocommerce' ) );
				$row['after_label']  = ( '' !== $new_class ? $new_class : __( 'Standard rate', 'catalog-health-scanner-for-woocommerce' ) );
				return $row;

			case 'set_cog_percent':
				$percent = (float) ( $args['percent'] ?? 0 );
				$price   = $product->get_price( 'edit' );
				$key     = $this->get_cog_meta_key();
				if ( $percent <= 0 || $percent > 100 || '' === $price ) {
					return null;
				}
				if ( '' !== (string) get_post_meta( $object_id, $key, true ) ) {
					return null;
				}
				$cost                = wc_format_decimal( (float) $price * $percent / 100 );
				$row['before']       = '';
				$row['after']        = $cost;
				$row['before_label'] = __( 'No cost', 'catalog-health-scanner-for-woocommerce' );
				/* translators: %1$s: computed cost, %2$s: percentage of price. */
				$row['after_label']  = sprintf( __( '%1$s (%2$s%% of price)', 'catalog-health-scanner-for-woocommerce' ), $cost, wc_format_decimal( $percent ) );
				return $row;

		}

		return null;

	}

	/**
	 * Writes a computed change to one object.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $fixer
	 * @param   int    $object_id
	 * @param   array  $change
	 * @param   array  $args
	 * @return  bool
	 */
	protected function write_change( $fixer, $object_id, $change, $args = array() ) {

		if ( 'delete_orphaned_variations' === $fixer ) {
			// Guarded destructive call: compute_change() has verified the post
			// type is `product_variation` and the parent is gone.
			return (bool) wp_trash_post( $object_id );
		}

		if ( 'unassign_deleted_shipping_class' === $fixer ) {
			global $wpdb;
			$removed = 0;
			foreach ( (array) $change['before'] as $tt_id ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Deleting an orphaned relationship row; wp_remove_object_terms() needs a live taxonomy, which is exactly what is missing.
				$removed += (int) $wpdb->delete(
					$wpdb->term_relationships,
					array(
						'object_id'        => $object_id,
						'term_taxonomy_id' => (int) $tt_id,
					),
					array( '%d', '%d' )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			}
			clean_object_term_cache( $object_id, 'product' );
			return ( $removed > 0 );
		}

		$product = wc_get_product( $object_id );
		if ( ! $product ) {
			return false;
		}

		try {

			switch ( $fixer ) {

				case 'clear_expired_sale':
					$product->set_sale_price( '' );
					$product->set_date_on_sale_from( null );
					$product->set_date_on_sale_to( null );
					break;

				case 'sync_stock_status':
					$product->set_stock_status( $change['after'] );
					break;

				case 'clear_unmanaged_stock':
					delete_post_meta( $object_id, '_stock' );
					return true;

				case 'clean_linked_products':
					$product->set_cross_sell_ids( $change['after']['cross'] );
					$product->set_upsell_ids( $change['after']['up'] );
					break;

				case 'clean_gallery':
					$product->set_gallery_image_ids( $change['after'] );
					break;

				case 'clean_grouped_children':
					update_post_meta( $object_id, '_children', array_map( 'intval', $change['after'] ) );
					return true;

				case 'assign_shipping_class':
					$product->set_shipping_class_id( (int) $change['after'] );
					break;

				case 'reset_tax_class':
					// set_tax_class('') is not enough: the product object was
					// read with the invalid class already sanitized away, so
					// saving it may not rewrite the row. Clear the meta itself.
					$product->set_tax_class( '' );
					$product->save();
					update_post_meta( $object_id, '_tax_class', '' );
					return true;

				case 'assign_tax_class':
					$product->set_tax_class( $change['after'] );
					break;

				case 'clear_virtual_dimensions':
					$product->set_weight( '' );
					$product->set_length( '' );
					$product->set_width( '' );
					$product->set_height( '' );
					break;

				case 'clean_title_artifacts':
					$product->set_name( $change['after'] );
					break;

				case 'generate_skus':
					if ( is_array( $change['after'] ) ) {
						foreach ( $change['after'] as $child_id => $child_sku ) {
							$variation = wc_get_product( (int) $child_id );
							if ( $variation ) {
								$variation->set_sku( $child_sku );
								$variation->save();
							}
						}
						return true;
					}
					$product->set_sku( $change['after'] );
					break;

				case 'set_weight':
					$product->set_weight( $change['after'] );
					break;

				case 'assign_category':
					$product->set_category_ids( $change['after'] );
					break;

				case 'assign_brand':
					$result = wp_set_object_terms( $object_id, array_map( 'intval', $change['after'] ), $this->get_brand_taxonomy(), true );
					return ! is_wp_error( $result );

				case 'set_cog_percent':
					update_post_meta( $object_id, $this->get_cog_meta_key(), $change['after'] );
					return true;

				default:
					return false;

			}

			$product->save();
			return true;

		} catch ( WC_Data_Exception $e ) {
			return false;
		}

	}

	/**
	 * Reverts one object to its logged before-state.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $fixer
	 * @param   int    $object_id
	 * @param   array  $item {before, after}
	 * @return  bool
	 */
	protected function revert_change( $fixer, $object_id, $item ) {

		if ( 'delete_orphaned_variations' === $fixer ) {
			return (bool) wp_untrash_post( $object_id );
		}

		if ( 'unassign_deleted_shipping_class' === $fixer ) {
			global $wpdb;
			foreach ( (array) $item['before'] as $tt_id ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Restoring an orphaned relationship row; no WP API can address a term_taxonomy row that does not exist.
				$wpdb->insert(
					$wpdb->term_relationships,
					array(
						'object_id'        => $object_id,
						'term_taxonomy_id' => (int) $tt_id,
						'term_order'       => 0,
					),
					array( '%d', '%d', '%d' )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			}
			clean_object_term_cache( $object_id, 'product' );
			return true;
		}

		$product = wc_get_product( $object_id );
		if ( ! $product ) {
			return false;
		}

		$before = $item['before'];

		try {

			switch ( $fixer ) {

				case 'clear_expired_sale':
					$product->set_sale_price( $before['sale_price'] );
					$product->set_date_on_sale_from( '' !== $before['date_from'] ? $before['date_from'] : null );
					$product->set_date_on_sale_to( '' !== $before['date_to'] ? $before['date_to'] : null );
					break;

				case 'sync_stock_status':
					// WooCommerce recomputes stock status from the quantity on
					// every save, so set_stock_status() alone cannot put back a
					// deliberately inconsistent value — which is exactly the
					// value this fix replaced. Save first, then write the meta,
					// then realign the product_visibility index by hand, or the
					// catalog would keep filtering on the value we just undid.
					$product->set_stock_status( $before );
					$product->save();
					update_post_meta( $object_id, '_stock_status', $before );
					if ( 'outofstock' === $before ) {
						wp_set_object_terms( $object_id, 'outofstock', 'product_visibility', true );
					} else {
						wp_remove_object_terms( $object_id, 'outofstock', 'product_visibility' );
					}
					wc_delete_product_transients( $object_id );
					return true;

				case 'clear_unmanaged_stock':
					update_post_meta( $object_id, '_stock', $before );
					return true;

				case 'clean_linked_products':
					$product->set_cross_sell_ids( $before['cross'] );
					$product->set_upsell_ids( $before['up'] );
					break;

				case 'clean_gallery':
					$product->set_gallery_image_ids( $before );
					break;

				case 'clean_grouped_children':
					update_post_meta( $object_id, '_children', array_map( 'intval', (array) $before ) );
					return true;

				case 'assign_shipping_class':
					$product->set_shipping_class_id( (int) $before );
					break;

				case 'reset_tax_class':
					// The before-value referenced a deleted class; restoring it
					// restores the original (broken) state, which is what undo means.
					update_post_meta( $object_id, '_tax_class', $before );
					return true;

				case 'assign_tax_class':
					// The prior value is often an invalid class — that is why
					// the product was flagged. set_tax_class() would sanitize it
					// away and undo would silently not undo, so write the meta.
					$product->set_tax_class( '' );
					$product->save();
					update_post_meta( $object_id, '_tax_class', (string) $before );
					return true;

				case 'clear_virtual_dimensions':
					$product->set_weight( $before['weight'] );
					$product->set_length( $before['length'] );
					$product->set_width( $before['width'] );
					$product->set_height( $before['height'] );
					break;

				case 'clean_title_artifacts':
					$product->set_name( $before );
					break;

				case 'generate_skus':
					if ( is_array( $before ) ) {
						foreach ( array_keys( $before ) as $child_id ) {
							$variation = wc_get_product( (int) $child_id );
							if ( $variation ) {
								$variation->set_sku( '' );
								$variation->save();
							}
						}
						return true;
					}
					$product->set_sku( '' );
					break;

				case 'set_weight':
					$product->set_weight( '' );
					break;

				case 'assign_category':
					$product->set_category_ids( array_map( 'intval', (array) $before ) );
					break;

				case 'assign_brand':
					$result = wp_set_object_terms( $object_id, array_map( 'intval', (array) $before ), $this->get_brand_taxonomy(), false );
					return ! is_wp_error( $result );

				case 'set_cog_percent':
					delete_post_meta( $object_id, $this->get_cog_meta_key() );
					return true;

				default:
					return false;

			}

			$product->save();
			return true;

		} catch ( WC_Data_Exception $e ) {
			return false;
		}

	}

	/**
	 * Repairs unambiguous encoding damage in a string. Returns the input
	 * unchanged when a safe repair is not possible.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   string $text
	 * @return  string
	 */
	function repair_encoding( $text ) {

		$fixed = str_replace( array( '&amp;amp;', '&amp;#039;', '&amp;quot;' ), array( '&amp;', '&#039;', '&quot;' ), $text );

		// Classic mojibake: UTF-8 bytes read back as single-byte text, then
		// re-saved as UTF-8. Peeling a layer means encoding the string down to
		// that single-byte page again.
		//
		// The page is Windows-1252, not ISO-8859-1. Curly quotes and dashes —
		// by far the most common casualties — live in 0x80-0x9F, which is
		// unassigned in ISO-8859-1, so a Latin-1 round trip turns them into
		// "?" and the repair silently does nothing. That is why this check
		// used to flag titles its own fixer could not touch.
		//
		// Exports are regularly double- or triple-encoded, so peel repeatedly,
		// keeping a pass only when it yields valid UTF-8 that is genuinely
		// shorter (every peeled layer removes bytes).
		if ( function_exists( 'mb_convert_encoding' ) ) {

			$encodings = array( 'Windows-1252', 'ISO-8859-1' );

			for ( $layer = 0; $layer < 3; $layer++ ) {

				if ( ! preg_match( '/â€|Ã¢|Ã©|Ã¨|Ã¼|Ã¶|Ã¤|Ã±|Ã‚|Â /u', $fixed ) ) {
					break;
				}

				$improved = false;

				foreach ( $encodings as $encoding ) {
					$candidate = @mb_convert_encoding( $fixed, $encoding, 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unsupported encodings warn on some builds; the result is validated below.
					if (
						is_string( $candidate ) &&
						'' !== $candidate &&
						strlen( $candidate ) < strlen( $fixed ) &&
						$candidate === wp_check_invalid_utf8( $candidate ) &&
						// A lossy round trip turns unmappable characters into
						// "?"; that is corruption, not a repair.
						substr_count( $candidate, '?' ) <= substr_count( $fixed, '?' )
					) {
						$fixed    = $candidate;
						$improved = true;
						break;
					}
				}

				if ( ! $improved ) {
					break;
				}
			}
		}

		// Replacement characters carry no information; drop them.
		$fixed = trim( str_replace( "\xEF\xBF\xBD", '', $fixed ) );

		return ( '' !== $fixed ? $fixed : $text );

	}

	/**
	 * Generates a unique SKU for a product or variation.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   WC_Product $product
	 * @return  string
	 */
	function generate_sku( $product ) {

		$parent_id = $product->get_parent_id();
		if ( $parent_id ) {
			$parent = wc_get_product( $parent_id );
			$base   = ( $parent && '' !== $parent->get_sku( 'edit' ) ? $parent->get_sku( 'edit' ) : get_post_field( 'post_name', $parent_id ) );
		} else {
			$base = $product->get_slug();
		}

		$base = strtoupper( substr( preg_replace( '/[^a-zA-Z0-9]+/', '-', (string) $base ), 0, 24 ) );
		$sku  = trim( $base, '-' ) . '-' . $product->get_id();

		return apply_filters( 'wpfchs_generated_sku', $sku, $product );

	}

	/**
	 * get_brand_taxonomy.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function get_brand_taxonomy() {
		foreach ( apply_filters( 'wpfchs_brand_taxonomies', array( 'product_brand', 'pwb-brand', 'yith_product_brand' ) ) as $candidate ) {
			if ( taxonomy_exists( $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * get_cog_meta_key.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  string
	 */
	function get_cog_meta_key() {
		$keys = apply_filters( 'wpfchs_cog_meta_keys', array( '_alg_wc_cog_cost', '_wc_cog_cost' ) );
		return reset( $keys );
	}

}

endif;

return new WPFCHS_Fixes();
