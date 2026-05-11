<?php
/**
 * Cache purge tool — detects supported cache layers and flushes safely.
 *
 * Designed to be a no-op for everything that isn't actively installed:
 * each layer is feature-detected before invocation so a site with WP
 * Rocket but no LiteSpeed runs only Rocket's flush.
 *
 * Returns a structured report ({ success, purged[], skipped[], warnings[] })
 * so callers can present the result without guessing.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Cache {

	public static function register_hooks() {
		add_action( 'admin_post_ai_site_connector_purge_cache', array( __CLASS__, 'handle_admin_purge' ) );
	}

	/**
	 * Run the multi-layer purge. Safe to call from REST, CLI, admin-post.
	 *
	 * @param array $opts {
	 *     @type bool $object  Flush WP object cache (default true).
	 *     @type bool $rocket  Try WP Rocket (default true).
	 *     @type bool $litespeed Try LiteSpeed (default true).
	 *     @type bool $w3tc    Try W3 Total Cache (default true).
	 *     @type bool $elementor Try Elementor file cache (default true).
	 *     @type bool $cloudflare Try Cloudflare (default true — but only fires when zone/token configured).
	 * }
	 * @return array { success, purged, skipped, warnings }
	 */
	public static function purge( $opts = array() ) {
		$opts = wp_parse_args(
			$opts,
			array(
				'object'     => true,
				'rocket'     => true,
				'litespeed'  => true,
				'w3tc'       => true,
				'elementor'  => true,
				'cloudflare' => true,
			)
		);

		$report = array(
			'success'  => true,
			'purged'   => array(),
			'skipped'  => array(),
			'warnings' => array(),
		);

		if ( $opts['object'] ) {
			$ok = false;
			if ( function_exists( 'wp_cache_flush' ) ) {
				$ok = (bool) wp_cache_flush();
			}
			if ( $ok ) {
				$report['purged'][] = 'object_cache';
			} else {
				// Built-in WP object cache flush always returns true on a
				// healthy install; failures here usually mean a persistent
				// object cache (Redis/Memcached) wasn't reachable.
				$report['skipped'][] = 'object_cache';
				$report['warnings'][] = 'wp_cache_flush() returned false — persistent object cache may not be reachable.';
			}
		} else {
			$report['skipped'][] = 'object_cache';
		}

		if ( $opts['rocket'] ) {
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
				$report['purged'][] = 'wp_rocket';
				if ( function_exists( 'rocket_clean_minify' ) ) {
					rocket_clean_minify();
				}
			} else {
				$report['skipped'][] = 'wp_rocket';
			}
		}

		if ( $opts['litespeed'] ) {
			$done = false;
			if ( class_exists( 'LiteSpeed_Cache_API' ) && method_exists( 'LiteSpeed_Cache_API', 'purge_all' ) ) {
				LiteSpeed_Cache_API::purge_all();
				$done = true;
			} elseif ( class_exists( '\LiteSpeed\Purge' ) && method_exists( '\LiteSpeed\Purge', 'purge_all' ) ) {
				\LiteSpeed\Purge::purge_all();
				$done = true;
			} elseif ( has_action( 'litespeed_purge_all' ) ) {
				do_action( 'litespeed_purge_all' );
				$done = true;
			}
			$report[ $done ? 'purged' : 'skipped' ][] = 'litespeed';
		}

		if ( $opts['w3tc'] ) {
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
				$report['purged'][] = 'w3_total_cache';
			} else {
				$report['skipped'][] = 'w3_total_cache';
			}
		}

		if ( $opts['elementor'] ) {
			$done = false;
			if ( class_exists( '\Elementor\Plugin' ) ) {
				try {
					/** @noinspection PhpUndefinedMethodInspection */
					$inst = \Elementor\Plugin::$instance ?? null;
					if ( $inst && isset( $inst->files_manager ) && method_exists( $inst->files_manager, 'clear_cache' ) ) {
						$inst->files_manager->clear_cache();
						$done = true;
					}
				} catch ( \Throwable $e ) {
					$report['warnings'][] = 'Elementor file cache clear threw: ' . $e->getMessage();
				}
			}
			$report[ $done ? 'purged' : 'skipped' ][] = 'elementor';
		}

		if ( $opts['cloudflare'] ) {
			$cf = self::purge_cloudflare();
			if ( null === $cf ) {
				$report['skipped'][] = 'cloudflare';
			} elseif ( true === $cf ) {
				$report['purged'][] = 'cloudflare';
			} else {
				$report['skipped'][] = 'cloudflare';
				$report['warnings'][] = $cf;
			}
		}

		AI_Site_Connector_Audit_Log::record(
			'cache_purged',
			array(
				'tool'    => AI_Site_Connector_Permissions::TOOL_PURGE_CACHE,
				'status'  => empty( $report['warnings'] ) ? AI_Site_Connector_Audit_Log::STATUS_SUCCESS : AI_Site_Connector_Audit_Log::STATUS_INFO,
				'summary' => sprintf(
					'Cache purge: %d layer(s) purged, %d skipped, %d warning(s).',
					count( $report['purged'] ),
					count( $report['skipped'] ),
					count( $report['warnings'] )
				),
				'meta'    => $report,
			)
		);

		return $report;
	}

	/**
	 * Best-effort Cloudflare zone purge. Returns:
	 *  - null  → no token/zone configured; caller should skip
	 *  - true  → purge accepted by CF API
	 *  - string warning → token/zone present but API said no
	 *
	 * Both the API token and the zone ID can be supplied via wp-config.php
	 * constants (`AI_SITE_CONNECTOR_CLOUDFLARE_TOKEN`,
	 * `AI_SITE_CONNECTOR_CLOUDFLARE_ZONE_ID`) which override the plugin
	 * options. Constant-first keeps the secret out of wp_options, which is
	 * the preferred posture for backups, dumps, and SQLi blast radius.
	 */
	private static function purge_cloudflare() {
		$token = defined( 'AI_SITE_CONNECTOR_CLOUDFLARE_TOKEN' ) && '' !== (string) AI_SITE_CONNECTOR_CLOUDFLARE_TOKEN
			? (string) AI_SITE_CONNECTOR_CLOUDFLARE_TOKEN
			: trim( (string) get_option( 'ai_site_connector_cloudflare_api_token', '' ) );
		$zone  = defined( 'AI_SITE_CONNECTOR_CLOUDFLARE_ZONE_ID' ) && '' !== (string) AI_SITE_CONNECTOR_CLOUDFLARE_ZONE_ID
			? (string) AI_SITE_CONNECTOR_CLOUDFLARE_ZONE_ID
			: trim( (string) get_option( 'ai_site_connector_cloudflare_zone_id', '' ) );
		if ( '' === $token || '' === $zone ) {
			return null;
		}

		$res = wp_remote_post(
			'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ) . '/purge_cache',
			array(
				'timeout' => 12,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'purge_everything' => true ) ),
			)
		);

		if ( is_wp_error( $res ) ) {
			return 'Cloudflare purge HTTP error: ' . $res->get_error_message();
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		$body = wp_remote_retrieve_body( $res );
		$decoded = json_decode( (string) $body, true );
		$msg = is_array( $decoded ) && ! empty( $decoded['errors'] )
			? wp_json_encode( $decoded['errors'] )
			: substr( (string) $body, 0, 200 );
		return sprintf( 'Cloudflare purge rejected (HTTP %d): %s', (int) $code, $msg );
	}

	public static function handle_admin_purge() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'ai-site-connector' ) );
		}
		check_admin_referer( AI_Site_Connector_Admin_Page::NONCE_ACTION, AI_Site_Connector_Admin_Page::NONCE_FIELD );

		$check = AI_Site_Connector_Permissions::require_permission( AI_Site_Connector_Permissions::TOOL_PURGE_CACHE );
		if ( is_wp_error( $check ) ) {
			AI_Site_Connector_Admin_Page::flash_public( $check->get_error_message(), 'error' );
			AI_Site_Connector_Admin_Page::redirect_back_public( 'diagnostics' );
		}

		$report = self::purge();
		AI_Site_Connector_Admin_Page::flash_public(
			sprintf(
				/* translators: 1: count purged, 2: count skipped, 3: count warnings */
				__( 'Cache purge complete: %1$d purged, %2$d skipped, %3$d warning(s).', 'ai-site-connector' ),
				count( $report['purged'] ),
				count( $report['skipped'] ),
				count( $report['warnings'] )
			),
			empty( $report['warnings'] ) ? 'success' : 'error',
			array( 'cache_report' => $report )
		);
		AI_Site_Connector_Admin_Page::redirect_back_public( 'diagnostics' );
	}
}
