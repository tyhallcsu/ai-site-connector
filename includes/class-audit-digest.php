<?php
/**
 * Audit-log digest email — optional periodic summary of recent events.
 *
 * Lighter-weight alternative to a real-time webhook forwarder. Bundles
 * audit events from the last 24 hours or 7 days into a single email at
 * a configurable cadence (off / daily / weekly), grouped by event type.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Audit_Digest {

	const CRON_HOOK         = 'ai_site_connector_audit_digest';
	const CADENCE_OPTION    = 'ai_site_connector_digest_cadence';
	const RECIPIENTS_OPTION = 'ai_site_connector_digest_recipients';

	public static function register_hooks() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_schedule_cron' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_digest' ) );
		add_action( 'admin_post_ai_site_connector_save_digest_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_ai_site_connector_send_test_digest', array( __CLASS__, 'handle_send_test' ) );
	}

	public static function cadence() {
		$c = (string) get_option( self::CADENCE_OPTION, 'off' );
		return in_array( $c, array( 'off', 'daily', 'weekly' ), true ) ? $c : 'off';
	}

	/**
	 * Comma-separated list of recipient emails. Defaults to admin email.
	 *
	 * @return array<int, string>
	 */
	public static function recipients() {
		$raw = (string) get_option( self::RECIPIENTS_OPTION, '' );
		if ( '' === trim( $raw ) ) {
			$admin = get_option( 'admin_email' );
			return $admin ? array( $admin ) : array();
		}
		$out = array();
		foreach ( explode( ',', $raw ) as $email ) {
			$email = trim( $email );
			if ( '' !== $email && is_email( $email ) ) {
				$out[] = $email;
			}
		}
		return $out;
	}

	public static function maybe_schedule_cron() {
		$cadence = self::cadence();
		if ( 'off' === $cadence ) {
			self::unschedule_cron();
			return;
		}
		$recurrence = 'weekly' === $cadence ? 'weekly' : 'daily';
		// Ensure WP knows the "weekly" interval (it doesn't by default).
		if ( 'weekly' === $recurrence && ! wp_get_schedules() ) {
			add_filter( 'cron_schedules', array( __CLASS__, 'register_weekly_schedule' ) );
		}
		$next = wp_next_scheduled( self::CRON_HOOK );
		if ( ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, self::CRON_HOOK );
		}
	}

	public static function register_weekly_schedule( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => 7 * DAY_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'ai-site-connector' ),
			);
		}
		return $schedules;
	}

	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function run_digest() {
		$cadence = self::cadence();
		if ( 'off' === $cadence ) {
			return;
		}
		$window = 'weekly' === $cadence ? 7 * DAY_IN_SECONDS : DAY_IN_SECONDS;
		self::send_digest( $window, false );
	}

	public static function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( AI_Site_Connector_Admin_Page::NONCE_ACTION, AI_Site_Connector_Admin_Page::NONCE_FIELD );

		$cadence_raw = isset( $_POST['digest_cadence'] ) ? sanitize_key( wp_unslash( $_POST['digest_cadence'] ) ) : 'off';
		$cadence     = in_array( $cadence_raw, array( 'off', 'daily', 'weekly' ), true ) ? $cadence_raw : 'off';
		update_option( self::CADENCE_OPTION, $cadence );

		$recipients_raw = isset( $_POST['digest_recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['digest_recipients'] ) ) : '';
		// Re-sanitize each email defensively.
		$cleaned = array();
		foreach ( explode( ',', $recipients_raw ) as $email ) {
			$email = sanitize_email( trim( $email ) );
			if ( '' !== $email && is_email( $email ) ) {
				$cleaned[] = $email;
			}
		}
		update_option( self::RECIPIENTS_OPTION, implode( ', ', $cleaned ) );

		// Re-schedule based on new cadence.
		self::unschedule_cron();
		self::maybe_schedule_cron();

		AI_Site_Connector_Audit_Log::record(
			'digest_settings_saved',
			array(
				'message' => sprintf(
					/* translators: 1: cadence, 2: recipient count. */
					__( 'Audit digest settings saved. Cadence: %1$s, recipients: %2$d.', 'ai-site-connector' ),
					$cadence,
					count( $cleaned )
				),
			)
		);

		set_transient(
			AI_Site_Connector_Admin_Page::FLASH_OPTION . '_' . get_current_user_id(),
			array(
				'msg'   => __( 'Audit digest settings saved.', 'ai-site-connector' ),
				'type'  => 'success',
				'extra' => array(),
			),
			60
		);
		wp_safe_redirect( add_query_arg( array( 'page' => AI_Site_Connector_Admin_Page::PAGE_SLUG, 'tab' => 'audit' ), admin_url( 'tools.php' ) ) );
		exit;
	}

	public static function handle_send_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( AI_Site_Connector_Admin_Page::NONCE_ACTION, AI_Site_Connector_Admin_Page::NONCE_FIELD );

		$result = self::send_digest( 7 * DAY_IN_SECONDS, true );
		$msg    = $result['sent']
			? sprintf(
				/* translators: 1: recipient count, 2: event count. */
				__( 'Test digest sent to %1$d recipient(s) covering %2$d event(s).', 'ai-site-connector' ),
				$result['recipients'],
				$result['events']
			)
			: ( 'no_recipients' === $result['skipped']
				? __( 'No recipients configured.', 'ai-site-connector' )
				: __( 'Test digest could not be sent. Check the server mail config.', 'ai-site-connector' ) );

		set_transient(
			AI_Site_Connector_Admin_Page::FLASH_OPTION . '_' . get_current_user_id(),
			array(
				'msg'   => $msg,
				'type'  => $result['sent'] ? 'success' : 'error',
				'extra' => array(),
			),
			60
		);
		wp_safe_redirect( add_query_arg( array( 'page' => AI_Site_Connector_Admin_Page::PAGE_SLUG, 'tab' => 'audit' ), admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Gather + format + send. Returns a small status array.
	 *
	 * @param int  $window_seconds Look-back window in seconds.
	 * @param bool $is_test        True when called from "Send test digest now".
	 * @return array{sent:bool, recipients:int, events:int, skipped?:string}
	 */
	private static function send_digest( $window_seconds, $is_test ) {
		$recipients = self::recipients();
		if ( empty( $recipients ) ) {
			return array( 'sent' => false, 'recipients' => 0, 'events' => 0, 'skipped' => 'no_recipients' );
		}

		$cutoff = time() - (int) $window_seconds;
		$events = AI_Site_Connector_Audit_Log::since( $cutoff );

		// Skip empty digests on auto-runs (still send on tests for verification).
		if ( ! $is_test && empty( $events ) ) {
			return array( 'sent' => false, 'recipients' => count( $recipients ), 'events' => 0, 'skipped' => 'empty_window' );
		}

		$subject = sprintf(
			/* translators: 1: site name, 2: date. */
			__( 'AI Site Connector audit digest — %1$s (%2$s)', 'ai-site-connector' ),
			wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			gmdate( 'Y-m-d', time() )
		);
		if ( $is_test ) {
			$subject = '[TEST] ' . $subject;
		}

		list( $html, $plain ) = self::compose_email( $events, $window_seconds, $is_test );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: AI Site Connector <' . get_option( 'admin_email' ) . '>',
		);

		$sent_count = 0;
		foreach ( $recipients as $to ) {
			$ok = wp_mail( $to, $subject, $html, $headers );
			if ( $ok ) {
				$sent_count++;
			}
		}

		if ( $sent_count > 0 ) {
			AI_Site_Connector_Audit_Log::record(
				'digest_sent',
				array(
					'message' => sprintf(
						/* translators: 1: recipients, 2: event count, 3: is_test. */
						__( 'Digest sent to %1$d/%2$d recipients (%3$d events, test=%4$s).', 'ai-site-connector' ),
						$sent_count,
						count( $recipients ),
						count( $events ),
						$is_test ? 'true' : 'false'
					),
				)
			);
		}

		unset( $plain ); // Reserved for a future multipart implementation.

		return array(
			'sent'       => $sent_count > 0,
			'recipients' => $sent_count,
			'events'     => count( $events ),
		);
	}

	/**
	 * Compose HTML + plain-text bodies for the digest.
	 *
	 * @param array $events  Rows from AI_Site_Connector_Audit_Log::since().
	 * @param int   $window  Window in seconds.
	 * @param bool  $is_test Whether this is a test send.
	 * @return array{0:string,1:string} [html, plain]
	 */
	private static function compose_email( $events, $window, $is_test ) {
		$grouped = self::group_events( $events );
		$days    = max( 1, (int) round( $window / DAY_IN_SECONDS ) );
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$html  = '<div style="font-family: -apple-system, BlinkMacSystemFont, Segoe UI, sans-serif; max-width: 640px; color: #1d2327;">';
		$html .= '<h2 style="margin: 0 0 12px;">' . esc_html( $site ) . ' — AI Site Connector digest</h2>';
		if ( $is_test ) {
			$html .= '<p style="background: #fef7e0; padding: 8px 12px; border-radius: 4px; margin: 0 0 16px; font-size: 13px;">Test send — content reflects the last 7 days of audit events. The scheduled digest will use your configured cadence window.</p>';
		}
		$html .= '<p style="color: #50575e; font-size: 13px; margin: 0 0 16px;">';
		$html .= esc_html( sprintf(
			/* translators: 1: event count, 2: window in days. */
			_n( '%1$d event in the last %2$d day.', '%1$d events in the last %2$d days.', count( $events ), 'ai-site-connector' ),
			count( $events ),
			$days
		) );
		$html .= '</p>';

		$plain  = $site . " — AI Site Connector digest\n";
		$plain .= str_repeat( '=', 60 ) . "\n\n";
		$plain .= sprintf( "%d events in the last %d day(s)\n\n", count( $events ), $days );

		if ( empty( $events ) ) {
			$html  .= '<p>No audit events in this window. All quiet.</p>';
			$plain .= "No audit events in this window. All quiet.\n";
		} else {
			foreach ( $grouped as $group_label => $group ) {
				$html  .= '<h3 style="border-top: 1px solid #ddd; padding-top: 12px; margin: 16px 0 8px;">' . esc_html( $group_label ) . ' (' . count( $group ) . ')</h3>';
				$plain .= "\n## " . $group_label . ' (' . count( $group ) . ")\n";
				foreach ( array_slice( $group, 0, 20 ) as $row ) {
					$row    = (array) $row;
					$when   = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
					$action = isset( $row['action'] ) ? (string) $row['action'] : '';
					$msg    = isset( $row['message'] ) ? wp_strip_all_tags( (string) $row['message'] ) : '';
					$html  .= '<div style="font-size: 13px; margin: 6px 0; color: #1d2327;"><code style="color: #50575e;">' . esc_html( $when ) . '</code> <strong>' . esc_html( $action ) . '</strong> — ' . esc_html( $msg ) . '</div>';
					$plain .= sprintf( "- [%s] %s — %s\n", $when, $action, $msg );
				}
				if ( count( $group ) > 20 ) {
					$extra  = count( $group ) - 20;
					$html  .= '<p style="font-size: 12px; color: #757575;">… +' . (int) $extra . ' more</p>';
					$plain .= sprintf( "  ... +%d more\n", $extra );
				}
			}
		}

		$html .= '<p style="font-size: 12px; color: #757575; margin-top: 24px;">View full log: ' . esc_url( admin_url( 'tools.php?page=' . AI_Site_Connector_Admin_Page::PAGE_SLUG . '&tab=audit' ) ) . '</p>';
		$html .= '</div>';

		return array( $html, $plain );
	}

	private static function group_events( $events ) {
		$buckets = array(
			'Credentials' => array(),
			'Updates'     => array(),
			'REST'        => array(),
			'Other'       => array(),
		);
		foreach ( $events as $row ) {
			$row    = (array) $row;
			$action = isset( $row['action'] ) ? (string) $row['action'] : '';
			if ( false !== strpos( $action, 'password' ) || false !== strpos( $action, 'connection_pack' ) || false !== strpos( $action, 'pre_flight' ) ) {
				$buckets['Credentials'][] = $row;
			} elseif ( 0 === strpos( $action, 'update_' ) ) {
				$buckets['Updates'][] = $row;
			} elseif ( false !== strpos( $action, 'rest' ) || false !== strpos( $action, 'scope' ) ) {
				$buckets['REST'][] = $row;
			} else {
				$buckets['Other'][] = $row;
			}
		}
		// Drop empty groups.
		return array_filter( $buckets, static function ( $g ) { return ! empty( $g ); } );
	}
}
