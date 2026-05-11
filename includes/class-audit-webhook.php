<?php
/**
 * Audit-log webhook forwarder.
 *
 * Optional outbound delivery of every audit_log row to a configured
 * webhook URL (Slack / Discord / Datadog / generic JSON POST). Delivery
 * is always non-blocking — scheduled via wp_schedule_single_event so it
 * fires after the originating request has returned. A misconfigured
 * receiver never blocks audit writes or REST traffic.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Audit_Webhook {

	const URL_OPTION    = 'ai_site_connector_webhook_url';
	const SECRET_OPTION = 'ai_site_connector_webhook_secret';
	const FORMAT_OPTION = 'ai_site_connector_webhook_format';
	const FILTER_OPTION = 'ai_site_connector_webhook_event_filter';
	const DELIVERY_HOOK = 'ai_site_connector_webhook_deliver';

	/**
	 * Default event filter — "critical" events forwarded out of the box.
	 * Admins can override on the Audit tab.
	 */
	private static $default_filter = array(
		'connection_pack_generated',
		'connection_pack_downloaded',
		'pack_download_token_minted',
		'application_password_revoked',
		'application_password_rotated',
		'application_password_expired',
		'ai_user_admin_refused',
		'update_started',
		'update_completed',
		'update_failed',
		'route_scope_denied',
		'ip_allowlist_denied',
		'application_password_expired_use',
	);

	public static function register_hooks() {
		add_action( 'ai_site_connector_audit_recorded', array( __CLASS__, 'maybe_enqueue' ), 10, 2 );
		add_action( self::DELIVERY_HOOK, array( __CLASS__, 'deliver' ), 10, 1 );
		add_action( 'admin_post_ai_site_connector_save_webhook', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ai_site_connector_test_webhook', array( __CLASS__, 'handle_test' ) );
	}

	public static function configured_url() {
		return (string) get_option( self::URL_OPTION, '' );
	}
	public static function configured_format() {
		$f = (string) get_option( self::FORMAT_OPTION, 'auto' );
		return in_array( $f, array( 'auto', 'generic', 'slack', 'discord', 'datadog' ), true ) ? $f : 'auto';
	}
	public static function configured_secret() {
		return (string) get_option( self::SECRET_OPTION, '' );
	}
	public static function configured_filter() {
		$raw = get_option( self::FILTER_OPTION, null );
		if ( null === $raw ) {
			return self::$default_filter;
		}
		if ( is_array( $raw ) ) {
			return array_filter( array_map( 'sanitize_key', $raw ) );
		}
		return self::$default_filter;
	}
	public static function default_filter_list() {
		return self::$default_filter;
	}

	public static function maybe_enqueue( $row_id, $action ) {
		$url = self::configured_url();
		if ( '' === $url ) {
			return;
		}
		$filter = self::configured_filter();
		if ( ! empty( $filter ) && ! in_array( $action, $filter, true ) ) {
			return;
		}
		// Schedule single-event so delivery runs out-of-band from the
		// originating request. A failed receiver never blocks the audit
		// write itself.
		wp_schedule_single_event( time(), self::DELIVERY_HOOK, array( (int) $row_id ) );
	}

	public static function deliver( $row_id ) {
		$url = self::configured_url();
		if ( '' === $url ) {
			return;
		}
		$row = AI_Site_Connector_Audit_Log::get_row( (int) $row_id );
		if ( ! $row ) {
			return;
		}
		$format  = self::configured_format();
		$payload = self::format_payload( $row, self::resolve_format( $format, $url ) );
		$body    = wp_json_encode( $payload );
		$secret  = self::configured_secret();

		$headers = array( 'Content-Type' => 'application/json' );
		if ( '' !== $secret && is_string( $body ) ) {
			$headers['X-AISC-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 5,
				'headers' => $headers,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log_delivery_failure( $url, 'wp_error', $response->get_error_message() );
			return;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 400 ) {
			return; // Success — no audit row (would create infinite loop on every event).
		}
		self::log_delivery_failure( $url, 'http_' . $status, '' );
	}

	private static function log_delivery_failure( $url, $code, $detail ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		AI_Site_Connector_Audit_Log::record(
			'webhook_delivery_failed',
			array(
				'tool'    => 'webhook',
				'status'  => 'failure',
				'message' => sprintf( 'Webhook delivery to %s failed (%s).', $host ? $host : '?', $code ),
				'meta'    => array( 'host' => $host, 'code' => $code, 'detail' => substr( (string) $detail, 0, 200 ) ),
			)
		);
	}

	private static function resolve_format( $format, $url ) {
		if ( 'auto' !== $format ) {
			return $format;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( false !== strpos( $host, 'slack.com' ) || false !== strpos( $host, 'hooks.slack.com' ) ) {
			return 'slack';
		}
		if ( false !== strpos( $host, 'discord.com' ) || false !== strpos( $host, 'discordapp.com' ) ) {
			return 'discord';
		}
		if ( false !== strpos( $host, 'datadoghq.com' ) ) {
			return 'datadog';
		}
		return 'generic';
	}

	private static function format_payload( $row, $format ) {
		$base = array(
			'site_url'   => home_url(),
			'site_name'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'plugin'     => 'ai-site-connector',
			'version'    => defined( 'AI_SITE_CONNECTOR_VERSION' ) ? AI_SITE_CONNECTOR_VERSION : '',
			'row'        => array(
				'id'            => (int) $row->id,
				'created_at'    => (string) $row->created_at,
				'action'        => (string) $row->action,
				'tool'          => (string) $row->tool,
				'status'        => (string) $row->status,
				'actor_user_id' => (int) $row->actor_user_id,
				'message'       => (string) $row->message,
				'summary'       => (string) $row->summary,
			),
		);
		$text = sprintf(
			'[%s] %s — %s: %s',
			$base['site_name'],
			$row->action,
			$row->status ? $row->status : 'info',
			$row->summary ? $row->summary : $row->message
		);
		switch ( $format ) {
			case 'slack':
				return array( 'text' => $text, 'attachments' => array( array( 'fallback' => $text, 'fields' => array(
					array( 'title' => 'Action',  'value' => $row->action,  'short' => true ),
					array( 'title' => 'Status',  'value' => $row->status,  'short' => true ),
					array( 'title' => 'Tool',    'value' => $row->tool,    'short' => true ),
					array( 'title' => 'Site',    'value' => home_url(),    'short' => true ),
					array( 'title' => 'Message', 'value' => $row->message ),
				) ) ) );
			case 'discord':
				return array( 'content' => $text );
			case 'datadog':
				return array(
					'ddsource'  => 'ai-site-connector',
					'ddtags'    => 'env:wordpress,action:' . $row->action . ',status:' . $row->status,
					'hostname'  => wp_parse_url( home_url(), PHP_URL_HOST ),
					'service'   => 'ai-site-connector',
					'message'   => $row->message,
					'meta'      => $base,
				);
			case 'generic':
			default:
				return $base;
		}
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( AI_Site_Connector_Admin_Page::NONCE_ACTION, AI_Site_Connector_Admin_Page::NONCE_FIELD );

		$url    = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['webhook_url'] ) ) : '';
		$secret = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['webhook_secret'] ) ) : '';
		$format = isset( $_POST['webhook_format'] ) ? sanitize_key( wp_unslash( (string) $_POST['webhook_format'] ) ) : 'auto';
		$events = isset( $_POST['webhook_events'] ) && is_array( $_POST['webhook_events'] )
			? array_filter( array_map( 'sanitize_key', wp_unslash( $_POST['webhook_events'] ) ) )
			: array();
		update_option( self::URL_OPTION, $url, false );
		update_option( self::SECRET_OPTION, $secret, false );
		update_option( self::FORMAT_OPTION, $format );
		update_option( self::FILTER_OPTION, $events );

		AI_Site_Connector_Audit_Log::record(
			'webhook_settings_saved',
			array(
				'tool'    => 'webhook',
				'message' => sprintf( 'Webhook settings saved. URL set: %s, format: %s, %d events.', ( '' !== $url ? 'yes' : 'no' ), $format, count( $events ) ),
				'meta'    => array( 'has_url' => '' !== $url, 'format' => $format, 'events_count' => count( $events ) ),
			)
		);

		set_transient(
			AI_Site_Connector_Admin_Page::FLASH_OPTION . '_' . get_current_user_id(),
			array( 'msg' => __( 'Webhook settings saved.', 'ai-site-connector' ), 'type' => 'success', 'extra' => array() ),
			60
		);
		wp_safe_redirect( add_query_arg( array( 'page' => AI_Site_Connector_Admin_Page::PAGE_SLUG, 'tab' => 'audit' ), admin_url( 'tools.php' ) ) );
		exit;
	}

	public static function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( AI_Site_Connector_Admin_Page::NONCE_ACTION, AI_Site_Connector_Admin_Page::NONCE_FIELD );

		AI_Site_Connector_Audit_Log::record(
			'webhook_test_event',
			array(
				'tool'    => 'webhook',
				'status'  => 'info',
				'message' => sprintf( 'Webhook test fired by user id %d.', get_current_user_id() ),
				'summary' => 'Test payload',
				'meta'    => array( 'test' => true ),
			)
		);

		set_transient(
			AI_Site_Connector_Admin_Page::FLASH_OPTION . '_' . get_current_user_id(),
			array(
				'msg'   => __( 'Test event recorded. The webhook is delivered asynchronously by WP-Cron — check your receiver in 30–60 seconds.', 'ai-site-connector' ),
				'type'  => 'success',
				'extra' => array(),
			),
			60
		);
		wp_safe_redirect( add_query_arg( array( 'page' => AI_Site_Connector_Admin_Page::PAGE_SLUG, 'tab' => 'audit' ), admin_url( 'tools.php' ) ) );
		exit;
	}
}
