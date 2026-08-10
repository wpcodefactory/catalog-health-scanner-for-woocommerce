<?php
/**
 * Catalog Health Scanner for WooCommerce - Check Definitions - Downloads
 *
 * Auto-detected: off when no downloadable products exist.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

return array(

	array(
		'id'          => 'download_no_files',
		'label'       => __( 'Downloadable product with no files attached', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Customers pay and receive nothing. This produces refunds and disputes, not just complaints.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'critical',
		'applies'     => function ( $product ) {
			return $product->is_downloadable();
		},
		'check'       => function ( $product ) {
			return ( empty( $product->get_downloads() ) ? __( 'No files attached', 'catalog-health-scanner-for-woocommerce' ) : false );
		},
	),

	array(
		'id'          => 'download_file_missing',
		'label'       => __( 'Download file missing from the server', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Customers paid and got a broken download link.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'critical',
		'applies'     => function ( $product ) {
			return $product->is_downloadable();
		},
		'check'       => function ( $product ) {
			$uploads = wp_get_upload_dir();
			foreach ( $product->get_downloads() as $download ) {
				$file = $download->get_file();
				$path = '';
				if ( 0 === strpos( $file, $uploads['baseurl'] ) ) {
					$path = str_replace( $uploads['baseurl'], $uploads['basedir'], $file );
				} elseif ( 0 === strpos( $file, 'shortcode' ) ) {
					continue;
				} elseif ( ! preg_match( '#^https?://#i', $file ) ) {
					$path = ( path_is_absolute( $file ) ? $file : trailingslashit( ABSPATH ) . ltrim( $file, '/' ) );
				} else {
					// Remote/off-site URL: not verifiable without an HTTP call; skipped by design.
					continue;
				}
				if ( '' !== $path && ! file_exists( $path ) ) {
					return basename( $path );
				}
			}
			return false;
		},
	),

	array(
		'id'          => 'download_public_location',
		'label'       => __( 'Download files stored in a publicly guessable location', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'Anyone who guesses the URL gets your paid file for free.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'high',
		'applies'     => function ( $product ) {
			return $product->is_downloadable();
		},
		'check'       => function ( $product ) {
			$uploads = wp_get_upload_dir();
			foreach ( $product->get_downloads() as $download ) {
				$file = $download->get_file();
				if (
					0 === strpos( $file, $uploads['baseurl'] ) &&
					false === strpos( $file, '/woocommerce_uploads/' )
				) {
					return basename( $file );
				}
			}
			return false;
		},
	),

	array(
		'id'          => 'download_url_error',
		'label'       => __( 'Download URL returning an error', 'catalog-health-scanner-for-woocommerce' ),
		'explanation' => __( 'The remote file behind this download answers with an error, so customers get nothing after paying.', 'catalog-health-scanner-for-woocommerce' ),
		'severity'    => 'critical',
		'applies'     => function ( $product ) {
			return $product->is_downloadable();
		},
		'check'       => function ( $product ) {
			$uploads = wp_get_upload_dir();
			foreach ( $product->get_downloads() as $download ) {
				$file = $download->get_file();
				// Local files are covered by `download_file_missing`.
				if ( ! preg_match( '#^https?://#i', $file ) || 0 === strpos( $file, $uploads['baseurl'] ) || 0 === strpos( $file, home_url() ) ) {
					continue;
				}
				$cache_key = 'wpfchs_urlchk_' . md5( $file );
				$status    = get_transient( $cache_key );
				if ( false === $status ) {
					$response = wp_remote_head(
						$file,
						array(
							'timeout'     => 5,
							'redirection' => 3,
						)
					);
					$status = ( is_wp_error( $response ) ? 'error' : (string) wp_remote_retrieve_response_code( $response ) );
					set_transient( $cache_key, $status, 12 * HOUR_IN_SECONDS );
				}
				if ( 'error' === $status || (int) $status >= 400 ) {
					return sprintf(
						/* translators: %1$s: file name, %2$s: HTTP status. */
						__( '%1$s (HTTP %2$s)', 'catalog-health-scanner-for-woocommerce' ),
						basename( wp_parse_url( $file, PHP_URL_PATH ) ),
						( 'error' === $status ? __( 'unreachable', 'catalog-health-scanner-for-woocommerce' ) : $status )
					);
				}
			}
			return false;
		},
	),

);
