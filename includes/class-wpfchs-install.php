<?php
/**
 * Catalog Health Scanner for WooCommerce - Install Class
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Install' ) ) :

class WPFCHS_Install {

	/**
	 * Creates/updates the plugin database tables.
	 *
	 * Runs on activation and on version update (`wpfchs_version_updated`),
	 * so schema changes in future releases only need a `dbDelta()` diff here.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();

		$scans = "CREATE TABLE {$wpdb->prefix}wpfchs_scans (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			profile VARCHAR(64) NOT NULL DEFAULT 'revenue_blockers',
			status VARCHAR(20) NOT NULL DEFAULT 'running',
			started_at DATETIME NOT NULL,
			completed_at DATETIME NULL DEFAULT NULL,
			products_total INT UNSIGNED NOT NULL DEFAULT 0,
			products_scanned INT UNSIGNED NOT NULL DEFAULT 0,
			last_product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			issues_found INT UNSIGNED NOT NULL DEFAULT 0,
			score DECIMAL(5,2) NULL DEFAULT NULL,
			score_data LONGTEXT NULL,
			check_ids TEXT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
		) $collate;";

		$issues = "CREATE TABLE {$wpdb->prefix}wpfchs_issues (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			object_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL,
			check_id VARCHAR(64) NOT NULL,
			category VARCHAR(32) NOT NULL,
			severity VARCHAR(16) NOT NULL,
			status VARCHAR(16) NOT NULL DEFAULT 'open',
			issue_value TEXT NULL,
			first_seen_scan BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_seen_scan BIGINT UNSIGNED NOT NULL DEFAULT 0,
			last_reopened_scan BIGINT UNSIGNED NOT NULL DEFAULT 0,
			first_seen DATETIME NOT NULL,
			resolved_at DATETIME NULL DEFAULT NULL,
			ignored_by BIGINT UNSIGNED NULL DEFAULT NULL,
			ignored_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY object_check (object_id,check_id),
			KEY check_status (check_id,status),
			KEY product_status (product_id,status),
			KEY category_status (category,status)
		) $collate;";

		$fixlog = "CREATE TABLE {$wpdb->prefix}wpfchs_fixlog (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			check_id VARCHAR(64) NOT NULL,
			fixer VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL,
			items LONGTEXT NULL,
			items_count INT UNSIGNED NOT NULL DEFAULT 0,
			undone TINYINT(1) NOT NULL DEFAULT 0,
			undone_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) $collate;";

		dbDelta( $scans );
		dbDelta( $issues );
		dbDelta( $fixlog );

	}

}

endif;
