<?php
/**
 * SSRF guard for outbound URL fetches.
 *
 * Called by every plugin code path that fetches a URL whose value can be
 * influenced by an authenticated user — currently the media sideload REST
 * route (`POST /media/sideload`) and the audit-log webhook delivery. Without
 * this guard, those paths would let an authenticated caller probe internal
 * services or hit the cloud metadata endpoint (169.254.169.254 on AWS/GCP).
 *
 * The guard rejects URLs whose host resolves to a private, loopback, or
 * link-local IP. PHP's FILTER_FLAG_NO_PRIV_RANGE + FILTER_FLAG_NO_RES_RANGE
 * are the workhorse — they cover RFC1918, 127/8, 169.254/16 (incl. metadata),
 * IPv6 ::1, fc00::/7, fe80::/10, multicast, broadcast, and reserved space.
 * An explicit 169.254 prefix check is kept as belt-and-suspenders.
 *
 * Operators with legitimate internal-host needs (intranet upload sources,
 * private webhook receivers) can override per-host via the
 * `ai_site_connector_url_guard_allow_host` filter.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Url_Guard {

	/**
	 * Verify that a URL is safe to fetch from this server.
	 *
	 * @param string $url
	 * @param string $context Short label for audit logs / filter callers.
	 * @return true|WP_Error true when safe; WP_Error with status 400 when not.
	 */
	public static function check_outbound_safe( $url, $context = '' ) {
		$url    = (string) $url;
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return new WP_Error(
				'asc_url_invalid',
				__( 'URL is malformed.', 'ai-site-connector' ),
				array( 'status' => 400, 'context' => $context )
			);
		}

		$scheme = strtolower( $parsed['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'asc_url_bad_scheme',
				__( 'URL scheme must be http or https.', 'ai-site-connector' ),
				array( 'status' => 400, 'context' => $context, 'scheme' => $scheme )
			);
		}

		$host = (string) $parsed['host'];

		/**
		 * Allow operators to opt a specific host out of the SSRF blocklist.
		 * Useful for legitimate internal/intranet upload sources.
		 *
		 * @param bool   $allowed Default false.
		 * @param string $host    The URL host (no brackets, no port).
		 * @param string $url     The full URL being checked.
		 * @param string $context Short caller label.
		 */
		if ( (bool) apply_filters( 'ai_site_connector_url_guard_allow_host', false, $host, $url, $context ) ) {
			return true;
		}

		$ips = self::resolve_all( $host );
		if ( empty( $ips ) ) {
			return new WP_Error(
				'asc_url_unresolvable',
				__( 'URL host did not resolve to any IP address.', 'ai-site-connector' ),
				array( 'status' => 400, 'context' => $context, 'host' => $host )
			);
		}
		foreach ( $ips as $ip ) {
			if ( self::is_blocked_ip( $ip ) ) {
				return new WP_Error(
					'asc_url_internal',
					__( 'URL host resolves to a private, loopback, link-local, or reserved IP and is not allowed.', 'ai-site-connector' ),
					array(
						'status'  => 400,
						'context' => $context,
						'host'    => $host,
						'reason'  => 'private_or_reserved_ip',
					)
				);
			}
		}
		return true;
	}

	/**
	 * Resolve every IPv4 and IPv6 address a host points at.
	 *
	 * If $host is already an IP literal (with or without brackets), returns it directly.
	 *
	 * @param string $host
	 * @return string[]
	 */
	public static function resolve_all( $host ) {
		$host = (string) $host;

		// IP literal short-circuit. Strip optional brackets for IPv6 in URLs.
		$clean = trim( $host, '[]' );
		if ( filter_var( $clean, FILTER_VALIDATE_IP ) ) {
			return array( $clean );
		}

		$ips = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$records_a = @dns_get_record( $host, DNS_A );
			if ( is_array( $records_a ) ) {
				foreach ( $records_a as $r ) {
					if ( ! empty( $r['ip'] ) ) {
						$ips[] = (string) $r['ip'];
					}
				}
			}
			$records_aaaa = @dns_get_record( $host, DNS_AAAA );
			if ( is_array( $records_aaaa ) ) {
				foreach ( $records_aaaa as $r ) {
					if ( ! empty( $r['ipv6'] ) ) {
						$ips[] = (string) $r['ipv6'];
					}
				}
			}
		}
		if ( empty( $ips ) && function_exists( 'gethostbyname' ) ) {
			$resolved = @gethostbyname( $host );
			if ( is_string( $resolved ) && $resolved !== $host && filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
				$ips[] = $resolved;
			}
		}
		return $ips;
	}

	/**
	 * Is this IP one we refuse to fetch from?
	 *
	 * @param string $ip
	 * @return bool true when the IP is private/loopback/reserved (block);
	 *              false when public-routable (allow).
	 */
	public static function is_blocked_ip( $ip ) {
		$ip = (string) $ip;
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}
		// FILTER_FLAG_NO_PRIV_RANGE = RFC1918 + fc00::/7
		// FILTER_FLAG_NO_RES_RANGE  = 0/8, 127/8, 169.254/16, multicast, broadcast,
		//                             ::1, fe80::/10, etc.
		$is_public = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		if ( false === $is_public ) {
			return true;
		}
		// Belt-and-suspenders for the cloud metadata endpoint — NO_RES_RANGE
		// already covers 169.254/16 but this makes the intent explicit.
		if ( 0 === strpos( $ip, '169.254.' ) ) {
			return true;
		}
		return false;
	}
}
