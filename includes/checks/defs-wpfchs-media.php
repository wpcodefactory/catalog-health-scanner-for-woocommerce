<?php
/**
 * WPFactory Catalog Health Scanner for WooCommerce - Check Definitions - Media
 *
 * Always applicable.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

return array(

	array(
		'id'          => 'image_missing',
		'label'       => __( 'No featured image', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Products without images convert dramatically worse, and most feed platforms reject them outright.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'check'       => function ( $product ) {
			return ( ! $product->get_image_id( 'edit' ) ? __( 'No featured image', 'wpfactory-catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'image_file_missing',
		'label'       => __( 'Image record exists but file is missing from the server', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'The product shows a broken image placeholder to every visitor.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'check'       => function ( $product ) {
			$image_id = $product->get_image_id( 'edit' );
			if ( ! $image_id ) {
				return false;
			}
			$file = get_attached_file( $image_id );
			if ( ! $file || ! file_exists( $file ) ) {
				return basename( (string) $file );
			}
			return false;
		},
	),

	array(
		'id'          => 'gallery_deleted_refs',
		'label'       => __( 'Gallery images referencing deleted attachments', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'The product gallery contains slots pointing at images that no longer exist.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'fix'         => 'clean_gallery',
		'fix_type'    => 'auto',
		'check'       => function ( $product ) {
			$deleted = 0;
			foreach ( $product->get_gallery_image_ids( 'edit' ) as $attachment_id ) {
				if ( 'attachment' !== get_post_type( $attachment_id ) ) {
					$deleted++;
				}
			}
			if ( $deleted > 0 ) {
				return sprintf(
					/* translators: %d: number of deleted gallery attachments. */
					__( '%d deleted attachment(s) referenced', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					$deleted
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'image_alt_missing',
		'label'       => __( 'Featured image has no alt text', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Alt text is what screen readers, image search, and AI vision models read. Without it the image is invisible to all three.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'check'       => function ( $product ) {
			$image_id = $product->get_image_id( 'edit' );
			if ( ! $image_id ) {
				return false; // Covered by `image_missing`.
			}
			$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
			return ( '' === trim( (string) $alt ) ? __( 'No alt text', 'wpfactory-catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'placeholder_image_only',
		'label'       => __( 'Only the WooCommerce placeholder image', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Customers see the generic grey placeholder instead of the product.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'check'       => function ( $product ) {
			$image_id       = $product->get_image_id( 'edit' );
			$placeholder_id = (int) get_option( 'woocommerce_placeholder_image', 0 );
			if ( $image_id && $placeholder_id && (int) $image_id === $placeholder_id ) {
				return __( 'Placeholder set as product image', 'wpfactory-catalog-health-scanner-for-woocommerce' );
			}
			return false;
		},
	),

	array(
		'id'          => 'image_reused_across_products',
		'label'       => __( 'Same featured image reused across many different products', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Identical thumbnails make products indistinguishable in the catalog and in feeds.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'low',
		'pass'        => 'catalog',
		'check'       => function () {
			global $wpdb;
			$threshold      = max( 2, (int) wpfchs()->core->get_threshold( 'image_reuse_count' ) );
			$placeholder_id = (int) get_option( 'woocommerce_placeholder_image', 0 );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Catalog-wide thumbnail aggregation has no WP API; runs once per scan.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT pm.post_id, pm.meta_value AS thumb
					FROM {$wpdb->postmeta} pm
					INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE pm.meta_key = '_thumbnail_id'
						AND pm.meta_value NOT IN ( '', '0' )
						AND p.post_type = 'product'
						AND p.post_status = 'publish'
						AND pm.meta_value IN (
							SELECT thumb FROM (
								SELECT pm2.meta_value AS thumb
								FROM {$wpdb->postmeta} pm2
								INNER JOIN {$wpdb->posts} p2 ON p2.ID = pm2.post_id
								WHERE pm2.meta_key = '_thumbnail_id'
									AND pm2.meta_value NOT IN ( '', '0' )
									AND p2.post_type = 'product'
									AND p2.post_status = 'publish'
								GROUP BY pm2.meta_value
								HAVING COUNT(*) >= %d
							) reused
						)",
					$threshold
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$results = array();
			foreach ( (array) $rows as $row ) {
				if ( $placeholder_id && (int) $row->thumb === $placeholder_id ) {
					continue; // Covered by `placeholder_image_only`.
				}
				$results[ (int) $row->post_id ] = array(
					'product_id' => (int) $row->post_id,
					'value'      => sprintf(
						/* translators: %d: attachment id. */
						__( 'Image #%d shared with other products', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
						(int) $row->thumb
					),
				);
			}
			return $results;
		},
	),

	array(
		'id'          => 'variation_images_inconsistent',
		'label'       => __( 'Variations with no image on a product where other variations have one', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Switching variations makes the image flicker between real photos and the parent fallback.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'low',
		'applies'     => function ( $product ) {
			return $product->is_type( 'variable' );
		},
		'check'       => function ( $product ) {
			$with    = 0;
			$without = 0;
			foreach ( $product->get_children() as $child_id ) {
				if ( '' !== (string) get_post_meta( $child_id, '_thumbnail_id', true ) ) {
					$with++;
				} else {
					$without++;
				}
			}
			if ( $with > 0 && $without > 0 ) {
				return sprintf(
					/* translators: %d: number of variations without an image. */
					__( '%d variation(s) without an image', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
					$without
				);
			}
			return false;
		},
	),

	array(
		'id'          => 'image_low_res',
		'label'       => __( 'Featured image below minimum usable resolution', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'The image pixelates on zoom and fails the minimum size requirements of most feed platforms.', 'wpfactory-catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'medium',
		'check'       => function ( $product ) {
			$image_id = $product->get_image_id( 'edit' );
			if ( ! $image_id ) {
				return false;
			}
			$meta = wp_get_attachment_metadata( $image_id );
			if ( empty( $meta['width'] ) || empty( $meta['height'] ) ) {
				return false;
			}
			$min = (int) wpfchs()->core->get_threshold( 'min_image_width' );
			if ( $meta['width'] < $min || $meta['height'] < $min ) {
				return $meta['width'] . '×' . $meta['height'] . 'px';
			}
			return false;
		},
	),

);
