<?php
/**
 * Per-Application-Password sidecar metadata.
 *
 * WordPress core stores Application Password records in user-meta key
 * `_application_passwords` with fields: uuid, app_id, name, password,
 * created, last_used, last_ip, ip. That structure is owned by core and
 * we shouldn't extend it directly — it could change between WP versions
 * and our extras would get clobbered.
 *
 * Instead, this class owns a parallel user-meta key
 * `ai_site_connector_app_password_extras` holding a map keyed by the
 * WP-issued password UUID:
 *
 *   [
 *     '<uuid>' => [
 *       'scopes'         => [ ['method' => 'GET', 'route' => '/wp/v2/posts'], ... ],
 *       'ip_allowlist'   => [ '192.0.2.0/24', '2001:db8::/32' ],
 *       'expires_at'     => 1735689600,                 // unix ts, nullable
 *       'created_by'     => 7,                          // admin user id who minted
 *       'reminder_sent'  => false,                      // expiry reminder flag
 *       'usage_counters' => [ 'YYYY-MM-DD' => [ 'requests' => N, 'errors' => N, 'by_route' => [...] ] ],
 *     ],
 *   ]
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_App_Password_Meta {

	const META_KEY = 'ai_site_connector_app_password_extras';

	/* ---------------------------------------------------------------------
	 * Public API.
	 * ------------------------------------------------------------------ */

	public static function register_hooks() {
		// Wipe a password's extras when its parent password is revoked. The
		// Application_Passwords wrapper fires this action right after the
		// successful delete in WP core, so we never orphan extras.
		add_action( 'ai_site_connector_application_password_revoked', array( __CLASS__, 'on_revoked' ), 10, 2 );
	}

	/**
	 * Read the full extras blob for one password.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 * @return array Always returns an array (empty if none set).
	 */
	public static function get_extras( $user_id, $uuid ) {
		$user_id = (int) $user_id;
		$uuid    = (string) $uuid;
		if ( ! $user_id || '' === $uuid ) {
			return array();
		}
		$all = self::all_for_user( $user_id );
		return isset( $all[ $uuid ] ) && is_array( $all[ $uuid ] ) ? $all[ $uuid ] : array();
	}

	/**
	 * Replace the full extras blob for one password.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 * @param array  $extras
	 * @return bool True on update, false on no-op.
	 */
	public static function set_extras( $user_id, $uuid, array $extras ) {
		$user_id = (int) $user_id;
		$uuid    = (string) $uuid;
		if ( ! $user_id || '' === $uuid ) {
			return false;
		}
		$all          = self::all_for_user( $user_id );
		$all[ $uuid ] = self::normalize_extras( $extras );
		return (bool) update_user_meta( $user_id, self::META_KEY, $all );
	}

	public static function get_scopes( $user_id, $uuid ) {
		$extras = self::get_extras( $user_id, $uuid );
		return isset( $extras['scopes'] ) && is_array( $extras['scopes'] ) ? $extras['scopes'] : array();
	}

	public static function set_scopes( $user_id, $uuid, array $scopes ) {
		$extras           = self::get_extras( $user_id, $uuid );
		$extras['scopes'] = self::normalize_scopes( $scopes );
		return self::set_extras( $user_id, $uuid, $extras );
	}

	public static function get_ip_allowlist( $user_id, $uuid ) {
		$extras = self::get_extras( $user_id, $uuid );
		return isset( $extras['ip_allowlist'] ) && is_array( $extras['ip_allowlist'] ) ? $extras['ip_allowlist'] : array();
	}

	public static function set_ip_allowlist( $user_id, $uuid, array $cidrs ) {
		$extras                = self::get_extras( $user_id, $uuid );
		$extras['ip_allowlist'] = self::normalize_cidrs( $cidrs );
		return self::set_extras( $user_id, $uuid, $extras );
	}

	/**
	 * Get the expiry timestamp (unix). Null if no expiry.
	 *
	 * @return int|null
	 */
	public static function get_expires_at( $user_id, $uuid ) {
		$extras = self::get_extras( $user_id, $uuid );
		return isset( $extras['expires_at'] ) && (int) $extras['expires_at'] > 0 ? (int) $extras['expires_at'] : null;
	}

	public static function set_expires_at( $user_id, $uuid, $expires_at ) {
		$extras                  = self::get_extras( $user_id, $uuid );
		$extras['expires_at']    = $expires_at ? (int) $expires_at : null;
		$extras['reminder_sent'] = false; // Reset reminder so the new expiry gets its own warning email.
		return self::set_extras( $user_id, $uuid, $extras );
	}

	public static function is_expired( $user_id, $uuid ) {
		$expires_at = self::get_expires_at( $user_id, $uuid );
		return $expires_at && $expires_at <= time();
	}

	public static function mark_reminder_sent( $user_id, $uuid ) {
		$extras                  = self::get_extras( $user_id, $uuid );
		$extras['reminder_sent'] = true;
		return self::set_extras( $user_id, $uuid, $extras );
	}

	/**
	 * Copy a password's extras to another UUID (used by atomic rotation).
	 */
	public static function copy_extras( $user_id, $from_uuid, $to_uuid ) {
		$extras = self::get_extras( $user_id, $from_uuid );
		if ( empty( $extras ) ) {
			return;
		}
		// Reset reminder flag on the new password so the user gets a fresh warning if the expiry stays the same.
		$extras['reminder_sent'] = false;
		self::set_extras( $user_id, $to_uuid, $extras );
	}

	public static function delete_extras( $user_id, $uuid ) {
		$user_id = (int) $user_id;
		$uuid    = (string) $uuid;
		if ( ! $user_id || '' === $uuid ) {
			return;
		}
		$all = self::all_for_user( $user_id );
		if ( isset( $all[ $uuid ] ) ) {
			unset( $all[ $uuid ] );
			if ( empty( $all ) ) {
				delete_user_meta( $user_id, self::META_KEY );
			} else {
				update_user_meta( $user_id, self::META_KEY, $all );
			}
		}
	}

	/**
	 * Bump usage counter for today on a password. Used by the per-request
	 * tracker (PR β feature #19) — defined here so the schema lives in one
	 * place.
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 * @param string $route   The REST route the request hit.
	 * @param bool   $is_error Whether the status code was 4xx/5xx.
	 */
	public static function increment_usage( $user_id, $uuid, $route, $is_error = false ) {
		$user_id = (int) $user_id;
		$uuid    = (string) $uuid;
		if ( ! $user_id || '' === $uuid ) {
			return;
		}
		$extras = self::get_extras( $user_id, $uuid );
		$day    = gmdate( 'Y-m-d' );
		if ( ! isset( $extras['usage_counters'] ) || ! is_array( $extras['usage_counters'] ) ) {
			$extras['usage_counters'] = array();
		}
		if ( ! isset( $extras['usage_counters'][ $day ] ) ) {
			$extras['usage_counters'][ $day ] = array( 'requests' => 0, 'errors' => 0, 'by_route' => array() );
		}
		$extras['usage_counters'][ $day ]['requests']++;
		if ( $is_error ) {
			$extras['usage_counters'][ $day ]['errors']++;
		}
		$route_key = self::canonicalize_route( $route );
		if ( '' !== $route_key ) {
			if ( ! isset( $extras['usage_counters'][ $day ]['by_route'][ $route_key ] ) ) {
				$extras['usage_counters'][ $day ]['by_route'][ $route_key ] = 0;
			}
			$extras['usage_counters'][ $day ]['by_route'][ $route_key ]++;
		}
		self::set_extras( $user_id, $uuid, $extras );
	}

	/**
	 * Drop usage_counters entries older than $retention_days. Returns the
	 * number of (user, day) entries pruned. Called from the daily sweep
	 * cron so we don't grow user_meta unboundedly.
	 */
	public static function prune_old_usage_counters( $retention_days ) {
		$retention_days = max( 1, (int) $retention_days );
		$cutoff_day     = gmdate( 'Y-m-d', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$pruned         = 0;
		foreach ( self::all_users_with_extras() as $user_id ) {
			$all     = self::all_for_user( $user_id );
			$changed = false;
			foreach ( $all as $uuid => $extras ) {
				if ( empty( $extras['usage_counters'] ) || ! is_array( $extras['usage_counters'] ) ) {
					continue;
				}
				foreach ( array_keys( $extras['usage_counters'] ) as $day ) {
					if ( $day < $cutoff_day ) {
						unset( $all[ $uuid ]['usage_counters'][ $day ] );
						$pruned++;
						$changed = true;
					}
				}
			}
			if ( $changed ) {
				if ( empty( $all ) ) {
					delete_user_meta( $user_id, self::META_KEY );
				} else {
					update_user_meta( $user_id, self::META_KEY, $all );
				}
			}
		}
		return $pruned;
	}

	/**
	 * Return user IDs that have at least one password with extras stored.
	 *
	 * @return array<int>
	 */
	public static function all_users_with_extras() {
		global $wpdb;
		$key = self::META_KEY;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cron-only sweep; small result set.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", $key ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * IP-against-CIDR matcher. Handles IPv4 and IPv6.
	 *
	 * @param string $ip
	 * @param array  $cidrs
	 * @return bool
	 */
	public static function ip_matches_cidr( $ip, array $cidrs ) {
		if ( '' === (string) $ip || empty( $cidrs ) ) {
			return false;
		}
		$ip_bin = @inet_pton( $ip );
		if ( false === $ip_bin ) {
			return false;
		}
		foreach ( $cidrs as $cidr ) {
			$cidr = trim( (string) $cidr );
			if ( '' === $cidr ) {
				continue;
			}
			// Bare IP match.
			if ( false === strpos( $cidr, '/' ) ) {
				if ( @inet_pton( $cidr ) === $ip_bin ) {
					return true;
				}
				continue;
			}
			list( $subnet, $mask ) = explode( '/', $cidr, 2 );
			$subnet_bin            = @inet_pton( $subnet );
			$mask                  = (int) $mask;
			if ( false === $subnet_bin || strlen( $subnet_bin ) !== strlen( $ip_bin ) ) {
				continue; // Different address families.
			}
			$max_bits = strlen( $subnet_bin ) * 8;
			if ( $mask < 0 || $mask > $max_bits ) {
				continue;
			}
			$full_bytes = (int) ( $mask / 8 );
			$rem_bits   = $mask % 8;
			// Compare full bytes.
			if ( $full_bytes > 0 && strncmp( $ip_bin, $subnet_bin, $full_bytes ) !== 0 ) {
				continue;
			}
			// Compare remaining bits if any.
			if ( $rem_bits > 0 ) {
				$byte_mask = ~( ( 1 << ( 8 - $rem_bits ) ) - 1 ) & 0xff;
				if ( ( ord( $ip_bin[ $full_bytes ] ) & $byte_mask ) !== ( ord( $subnet_bin[ $full_bytes ] ) & $byte_mask ) ) {
					continue;
				}
			}
			return true;
		}
		return false;
	}

	/**
	 * Route allow-list matcher. Each scope entry is { method, route }.
	 * Method '*' matches any HTTP method. Route may end in '*' for prefix
	 * match (e.g. '/wp/v2/posts/*'); otherwise it's an exact match.
	 *
	 * @param string $method  HTTP method of the current request.
	 * @param string $route   REST route of the current request (e.g. '/wp/v2/posts').
	 * @param array  $scopes  Array of { method, route } entries.
	 * @return bool
	 */
	public static function route_matches_scopes( $method, $route, array $scopes ) {
		if ( empty( $scopes ) ) {
			// Empty scopes array = no restriction (caller decides).
			return true;
		}
		$method = strtoupper( (string) $method );
		$route  = '/' . ltrim( (string) $route, '/' );
		foreach ( $scopes as $scope ) {
			if ( ! is_array( $scope ) || empty( $scope['route'] ) ) {
				continue;
			}
			$scope_method = isset( $scope['method'] ) ? strtoupper( (string) $scope['method'] ) : '*';
			$scope_route  = '/' . ltrim( (string) $scope['route'], '/' );
			if ( '*' !== $scope_method && $scope_method !== $method ) {
				continue;
			}
			if ( '*' === substr( $scope_route, -1 ) ) {
				$prefix = rtrim( substr( $scope_route, 0, -1 ), '/' );
				if ( '' === $prefix || 0 === strpos( $route, $prefix ) ) {
					return true;
				}
			} elseif ( $scope_route === $route ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Hook callbacks.
	 * ------------------------------------------------------------------ */

	public static function on_revoked( $user_id, $uuid ) {
		self::delete_extras( $user_id, $uuid );
	}

	/* ---------------------------------------------------------------------
	 * Internals.
	 * ------------------------------------------------------------------ */

	private static function all_for_user( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META_KEY, true );
		return is_array( $raw ) ? $raw : array();
	}

	private static function normalize_extras( array $extras ) {
		$out = array();
		if ( isset( $extras['scopes'] ) && is_array( $extras['scopes'] ) ) {
			$out['scopes'] = self::normalize_scopes( $extras['scopes'] );
		}
		if ( isset( $extras['ip_allowlist'] ) && is_array( $extras['ip_allowlist'] ) ) {
			$out['ip_allowlist'] = self::normalize_cidrs( $extras['ip_allowlist'] );
		}
		if ( array_key_exists( 'expires_at', $extras ) ) {
			$out['expires_at'] = $extras['expires_at'] ? (int) $extras['expires_at'] : null;
		}
		if ( isset( $extras['created_by'] ) ) {
			$out['created_by'] = (int) $extras['created_by'];
		}
		if ( isset( $extras['reminder_sent'] ) ) {
			$out['reminder_sent'] = (bool) $extras['reminder_sent'];
		}
		if ( isset( $extras['usage_counters'] ) && is_array( $extras['usage_counters'] ) ) {
			$out['usage_counters'] = $extras['usage_counters'];
		}
		return $out;
	}

	private static function normalize_scopes( array $scopes ) {
		$out = array();
		foreach ( $scopes as $scope ) {
			if ( ! is_array( $scope ) || empty( $scope['route'] ) ) {
				continue;
			}
			$method = isset( $scope['method'] ) ? strtoupper( sanitize_text_field( $scope['method'] ) ) : '*';
			$route  = '/' . ltrim( sanitize_text_field( $scope['route'] ), '/' );
			if ( ! in_array( $method, array( '*', 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS' ), true ) ) {
				$method = '*';
			}
			$out[] = array( 'method' => $method, 'route' => $route );
		}
		return $out;
	}

	private static function normalize_cidrs( array $cidrs ) {
		$out = array();
		foreach ( $cidrs as $cidr ) {
			$cidr = trim( sanitize_text_field( (string) $cidr ) );
			if ( '' === $cidr ) {
				continue;
			}
			// Cheap shape check; the IP-match function handles malformed entries by skipping.
			if ( false === strpos( $cidr, '/' ) ) {
				if ( @inet_pton( $cidr ) !== false ) {
					$out[] = $cidr;
				}
				continue;
			}
			list( $subnet, $mask ) = explode( '/', $cidr, 2 );
			if ( @inet_pton( $subnet ) !== false && ctype_digit( $mask ) ) {
				$out[] = $cidr;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function canonicalize_route( $route ) {
		$route = (string) $route;
		if ( '' === $route ) {
			return '';
		}
		// Normalize: replace numeric IDs with {id} so aggregates don't explode.
		$route = preg_replace( '#/\d+(?=/|$)#', '/{id}', $route );
		return $route ? '/' . ltrim( $route, '/' ) : '';
	}
}
