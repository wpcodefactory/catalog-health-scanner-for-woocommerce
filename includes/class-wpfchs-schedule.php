<?php
/**
 * Catalog Health Scanner for WooCommerce - Schedule & Digest Class
 *
 * Scheduled scans via WP-Cron and the email digest. The digest is sent
 * only when there is something new; silence when the catalog is clean.
 *
 * @version 1.0.0
 * @since   1.0.0
 *
 * @author  WPFactory
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WPFCHS_Schedule' ) ) :

class WPFCHS_Schedule {

	/**
	 * Constructor.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function __construct() {
		add_action( 'wpfchs_scheduled_scan_event', array( $this, 'run_scheduled_scan' ) );
		// Auto-remediation runs before the digest, so the digest reflects the
		// post-fix state.
		add_action( 'wpfchs_scan_completed', array( $this, 'maybe_auto_remediate' ), 1 );
		add_action( 'wpfchs_scan_completed', array( $this, 'maybe_send_digest' ) );
		add_action( 'wpfchs_scan_completed', array( $this, 'maybe_send_critical_alert' ), 5 );
		add_action( 'update_option_wpfchs_schedule', array( $this, 'reschedule' ), 10, 0 );
		add_action( 'add_option_wpfchs_schedule', array( $this, 'reschedule' ), 10, 0 );
	}

	/**
	 * Opt-in: after a scheduled scan, automatically apply the unambiguous
	 * auto-fixable checks. Every change is logged and remains undoable — the
	 * same safety infrastructure as a manual fix, just unattended.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $scan_id
	 */
	function maybe_auto_remediate( $scan_id ) {

		$settings = $this->get_settings();
		if ( 'yes' !== $settings['auto_fix_enabled'] ) {
			return;
		}

		$scan = wpfchs()->core->scanner->get_scan( $scan_id );
		if ( ! $scan ) {
			return;
		}
		$data = json_decode( (string) $scan->score_data, true );
		if ( 'scheduled' !== ( $data['trigger'] ?? '' ) ) {
			return;
		}

		$result = wpfchs()->core->fixes->fix_all_quick_wins();

		if ( $result['products_fixed'] > 0 ) {
			// Recompute the score so the digest and dashboard reflect the fixes.
			wpfchs()->core->issues->sync_product_counts();
			do_action( 'wpfchs_auto_remediated', $scan_id, $result );
		}

	}

	/**
	 * get_settings.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @return  array {enabled, frequency, profile, digest_enabled, digest_recipients, alerts_enabled}
	 */
	function get_settings() {
		$settings = wp_parse_args(
			(array) get_option( 'wpfchs_schedule', array() ),
			array(
				'enabled'           => 'no',
				'frequency'         => 'weekly',
				'profile'           => 'revenue_blockers',
				'digest_enabled'    => 'yes',
				'digest_recipients' => get_option( 'admin_email' ),
				'alerts_enabled'    => 'no',
				'alert_recipients'  => get_option( 'admin_email' ),
				'auto_fix_enabled'  => 'no',
			)
		);

		// Scheduling, digests, alerts and auto-remediation are Pro. Forcing
		// them off here — the single source every consumer reads — gates the
		// cron runner, both emails and the auto-fixer at once, and makes a
		// Pro-to-free switch self-healing: the next `reschedule()` clears the
		// event because `enabled` reads as no, whatever the option row says.
		if ( ! wpfchs()->is_pro() ) {
			$settings['enabled']          = 'no';
			$settings['digest_enabled']   = 'no';
			$settings['alerts_enabled']   = 'no';
			$settings['auto_fix_enabled'] = 'no';
		}

		return $settings;
	}

	/**
	 * Immediate alert: fires after ANY completed scan (the moment new
	 * critical issues are detected), independent of the digest.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $scan_id
	 */
	function maybe_send_critical_alert( $scan_id ) {

		$settings = $this->get_settings();
		if ( 'yes' !== $settings['alerts_enabled'] ) {
			return;
		}

		$new_critical = wpfchs()->core->issues->query(
			array(
				'status'          => 'open',
				'severity'        => 'critical',
				'first_seen_scan' => (int) $scan_id,
				'limit'           => 100,
			)
		);

		if ( empty( $new_critical ) ) {
			return;
		}

		$recipients = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $settings['alert_recipients'] ) ) );
		if ( empty( $recipients ) ) {
			return;
		}

		$checks = wpfchs()->core->checks;

		$subject = sprintf(
			/* translators: %d: number of new critical issues. */
			_n(
				'Critical: %d product just became unsellable',
				'Critical: %d products just became unsellable',
				count( $new_critical ),
				'catalog-health-scanner-for-woocommerce'
			),
			count( $new_critical )
		);

		ob_start();
		echo '<div style="font-family:sans-serif;max-width:600px">';
		echo '<p>' . esc_html__( 'The latest catalog scan found new critical issues:', 'catalog-health-scanner-for-woocommerce' ) . '</p><ul>';
		foreach ( array_slice( $new_critical, 0, 20 ) as $issue ) {
			$check = $checks->get( $issue->check_id );
			echo '<li><a href="' . esc_url( (string) get_edit_post_link( $issue->product_id ) ) . '">' . esc_html( get_the_title( $issue->product_id ) ) . '</a> &mdash; ' . esc_html( $check ? $check->get_label() : $issue->check_id ) . '</li>';
		}
		echo '</ul>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpfchs' ) ) . '">' . esc_html__( 'Open the Catalog Health dashboard', 'catalog-health-scanner-for-woocommerce' ) . '</a></p>';
		echo '</div>';
		$body = (string) ob_get_clean();

		wp_mail(
			$recipients,
			'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . $subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

	}

	/**
	 * (Re)creates the cron event to match settings.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function reschedule() {

		wp_clear_scheduled_hook( 'wpfchs_scheduled_scan_event' );

		$settings = $this->get_settings();
		if ( 'yes' !== $settings['enabled'] ) {
			return;
		}

		$frequency = ( in_array( $settings['frequency'], array( 'daily', 'weekly', 'monthly' ), true ) ? $settings['frequency'] : 'weekly' );
		$schedules = array(
			'daily'   => 'daily',
			'weekly'  => 'weekly',
			'monthly' => ( function_exists( 'wp_get_schedules' ) && isset( wp_get_schedules()['monthly'] ) ? 'monthly' : 'weekly' ),
		);

		wp_schedule_event( time() + HOUR_IN_SECONDS, $schedules[ $frequency ], 'wpfchs_scheduled_scan_event' );

	}

	/**
	 * Cron callback: starts the scheduled scan and hands stepping over to
	 * the scanner's own single events.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 */
	function run_scheduled_scan() {

		$settings = $this->get_settings();
		if ( 'yes' !== $settings['enabled'] ) {
			return;
		}

		$scanner = wpfchs()->core->scanner;
		if ( $scanner->get_running() ) {
			return;
		}

		$scan_id = $scanner->start( $settings['profile'], 'scheduled' );
		if ( ! is_wp_error( $scan_id ) ) {
			wp_schedule_single_event( time() + 10, 'wpfchs_scan_step_event', array( (int) $scan_id ) );
		}

	}

	/**
	 * Sends the digest after a scheduled scan, only when there are new issues.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $scan_id
	 */
	function maybe_send_digest( $scan_id ) {

		$settings = $this->get_settings();
		if ( 'yes' !== $settings['digest_enabled'] ) {
			return;
		}

		$scan = wpfchs()->core->scanner->get_scan( $scan_id );
		if ( ! $scan ) {
			return;
		}

		$data = json_decode( (string) $scan->score_data, true );
		if ( 'scheduled' !== ( $data['trigger'] ?? '' ) ) {
			return;
		}

		$issues = wpfchs()->core->issues;

		$new_issues = $issues->query(
			array(
				'status'          => 'open',
				'first_seen_scan' => $scan_id,
				'limit'           => 500,
			)
		);

		// Silence when the catalog is clean.
		if ( empty( $new_issues ) ) {
			return;
		}

		$critical_count = count(
			array_filter(
				$new_issues,
				function ( $issue ) {
					return ( 'critical' === $issue->severity );
				}
			)
		);

		if ( $critical_count > 0 ) {
			$subject = sprintf(
				/* translators: %d: number of products with critical issues. */
				_n(
					'%d product cannot be sold correctly',
					'%d products cannot be sold correctly',
					$critical_count,
					'catalog-health-scanner-for-woocommerce'
				),
				$critical_count
			);
		} else {
			$subject = sprintf(
				/* translators: %d: number of new catalog issues. */
				_n(
					'%d new catalog issue found',
					'%d new catalog issues found',
					count( $new_issues ),
					'catalog-health-scanner-for-woocommerce'
				),
				count( $new_issues )
			);
		}

		$recipients = array_filter( array_map( 'sanitize_email', preg_split( '/[\s,;]+/', (string) $settings['digest_recipients'] ) ) );
		if ( empty( $recipients ) ) {
			return;
		}

		$body = $this->build_digest_body( $scan, $new_issues );

		wp_mail(
			$recipients,
			'[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . $subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

	}

	/**
	 * Builds the digest email body.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   object $scan
	 * @param   array  $new_issues
	 * @return  string
	 */
	function build_digest_body( $scan, $new_issues ) {

		$checks     = wpfchs()->core->checks;
		$categories = $checks->get_categories();

		$grouped = array();
		foreach ( $new_issues as $issue ) {
			$grouped[ $issue->category ][ $issue->check_id ][] = $issue;
		}

		$previous = $this->get_previous_score( (int) $scan->id );
		$score    = (float) $scan->score;

		if ( null !== $previous ) {
			$direction = (
				$score > $previous ?
				/* translators: %s: previous score percentage. */
				sprintf( __( 'up from %s%%', 'catalog-health-scanner-for-woocommerce' ), wc_format_decimal( $previous, 0 ) ) :
				/* translators: %s: previous score percentage. */
				sprintf( __( 'down from %s%%', 'catalog-health-scanner-for-woocommerce' ), wc_format_decimal( $previous, 0 ) )
			);
		} else {
			$direction = '';
		}

		ob_start();
		?>
		<div style="font-family:sans-serif;max-width:600px">
			<h2 style="font-weight:600">
				<?php
				printf(
					/* translators: %s: health score percentage. */
					esc_html__( 'Catalog health: %s%%', 'catalog-health-scanner-for-woocommerce' ),
					esc_html( wc_format_decimal( $score, 0 ) )
				);
				echo ( '' !== $direction ? ' <span style="font-weight:400;font-size:14px">(' . esc_html( $direction ) . ')</span>' : '' );
				?>
			</h2>
			<p><?php esc_html_e( 'New issues since the last scan:', 'catalog-health-scanner-for-woocommerce' ); ?></p>
			<?php foreach ( $grouped as $category => $by_check ) : ?>
				<h3 style="font-size:15px;margin-bottom:4px"><?php echo esc_html( $categories[ $category ] ?? $category ); ?></h3>
				<ul style="margin-top:4px">
					<?php foreach ( $by_check as $check_id => $issues ) : ?>
						<?php $check = $checks->get( $check_id ); ?>
						<li>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpfchs&tab=' . $category . '&check=' . $check_id ) ); ?>">
								<?php echo esc_html( $check ? $check->get_label() : $check_id ); ?>
							</a>
							&mdash;
							<?php
							printf(
								/* translators: %d: number of affected products. */
								esc_html( _n( '%d product', '%d products', count( $issues ), 'catalog-health-scanner-for-woocommerce' ) ),
								count( $issues )
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpfchs' ) ); ?>">
					<?php esc_html_e( 'Open the Catalog Health dashboard', 'catalog-health-scanner-for-woocommerce' ); ?>
				</a>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();

	}

	/**
	 * Score of the completed scan before the given one.
	 *
	 * @version 1.0.0
	 * @since   1.0.0
	 *
	 * @param   int $scan_id
	 * @return  float|null
	 */
	function get_previous_score( $scan_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom scans table; no WP API exists.
		$score = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT score FROM {$wpdb->prefix}wpfchs_scans WHERE status = 'complete' AND id < %d ORDER BY id DESC LIMIT 1",
				$scan_id
			)
		);
		return ( null !== $score ? (float) $score : null );
	}

}

endif;

return new WPFCHS_Schedule();
