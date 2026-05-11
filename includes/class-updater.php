<?php
/**
 * GitHub-driven self-updater.
 *
 * Hooks WordPress's native plugin-update flow so that Dashboard → Updates,
 * the Plugins screen, WP-CLI `wp plugin update`, and the in-plugin status
 * card all converge on the latest release published at
 * github.com/tyhallcsu/ai-site-connector/releases.
 *
 * No third-party libraries. Uses only core WordPress APIs.
 *
 * Two opt-in constants (define in wp-config.php):
 *   AI_SITE_CONNECTOR_UPDATE_PRERELEASE  (bool) — include pre-release tags.
 *   AI_SITE_CONNECTOR_UPDATE_DISABLE     (bool) — kill switch; no hooks fire.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Updater {

	const TRANSIENT_KEY    = 'ai_site_connector_remote_release';
	const TRANSIENT_TTL    = 6 * HOUR_IN_SECONDS;
	const ERROR_TTL        = 30 * MINUTE_IN_SECONDS;
	const CRON_HOOK        = 'ai_site_connector_update_check';
	const PLUGIN_SLUG      = 'ai-site-connector';
	const GITHUB_OWNER     = 'tyhallcsu';
	const GITHUB_REPO      = 'ai-site-connector';
	const ASSET_PATTERN    = '/^ai-site-connector-v\d.+\.zip$/';
	const VERSION_PATTERN  = '/^\d+\.\d+(\.\d+)?(-[A-Za-z0-9\.\-]+)?$/';
	const RAW_BRAND_BASE   = 'https://raw.githubusercontent.com/tyhallcsu/ai-site-connector/main/assets/brand/';

	/**
	 * Memoized readme.txt parse result. NULL = not parsed yet, false = unreadable.
	 *
	 * @var array|false|null
	 */
	private static $readme_cache = null;

	public static function register_hooks() {
		if ( self::is_disabled() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugins_api_filter' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'source_selection' ), 10, 4 );
		add_action( 'in_plugin_update_message-' . AI_SITE_CONNECTOR_BASENAME, array( __CLASS__, 'update_message' ), 10, 2 );

		add_action( 'admin_post_ai_site_connector_check_updates', array( __CLASS__, 'handle_check_updates' ) );
		add_action( 'admin_post_ai_site_connector_run_update', array( __CLASS__, 'handle_run_update' ) );

		// Daily background check so the Updates card always has fresh data
		// without depending on WP's update_plugins schedule (which can lag
		// 12+ hours on quiet sites).
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_schedule_cron' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_cron' ) );
	}

	public static function maybe_schedule_cron() {
		if ( self::is_disabled() ) {
			self::unschedule_cron();
			return;
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function run_cron() {
		// Force a fresh fetch by clearing the cache first; get_remote_release()
		// will repopulate the transient as a side effect.
		delete_site_transient( self::TRANSIENT_KEY );
		self::get_remote_release();
	}

	/**
	 * Public on-demand check. Called from the admin page Updates card when
	 * the cache is empty so the operator sees real status on first visit
	 * rather than "Not checked yet" until WP's own update_plugins schedule
	 * gets around to firing pre_set_site_transient_update_plugins.
	 *
	 * Returns the cached release (or null on failure). Caller can use the
	 * return value to render immediately.
	 *
	 * @return array|null Parsed release on success, null on failure / disabled.
	 */
	public static function ensure_check() {
		if ( self::is_disabled() ) {
			return null;
		}
		$cached = get_site_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) && ! empty( $cached['version'] ) ) {
			return $cached;
		}
		if ( is_array( $cached ) && ! empty( $cached['error'] ) ) {
			// Recent failure — don't re-hammer GitHub. Caller can show error state.
			return null;
		}
		// No cache at all — synchronous fetch. Caps at the 10s timeout in fetch_from_github().
		$remote = self::get_remote_release();
		return is_array( $remote ) ? $remote : null;
	}

	/* ---------------------------------------------------------------------
	 * Public status accessors — used by the admin page status card.
	 * ------------------------------------------------------------------ */

	/**
	 * Return cached release info if present, else null. Does NOT trigger a
	 * network fetch — safe to call from page renders.
	 *
	 * @return array|null { version, zip_url, asset_name, body, published_at, is_prerelease, html_url }
	 */
	public static function cached_release() {
		$cached = get_site_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) && empty( $cached['error'] ) && ! empty( $cached['version'] ) ) {
			return $cached;
		}
		return null;
	}

	/**
	 * Return last error sentinel if a recent check failed, else null.
	 *
	 * @return array|null { error, cached_at }
	 */
	public static function cached_error() {
		$cached = get_site_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) && ! empty( $cached['error'] ) ) {
			return $cached;
		}
		return null;
	}

	public static function is_disabled() {
		return defined( 'AI_SITE_CONNECTOR_UPDATE_DISABLE' ) && AI_SITE_CONNECTOR_UPDATE_DISABLE;
	}

	public static function include_prerelease() {
		return defined( 'AI_SITE_CONNECTOR_UPDATE_PRERELEASE' ) && AI_SITE_CONNECTOR_UPDATE_PRERELEASE;
	}

	public static function update_available() {
		$remote = self::cached_release();
		if ( ! $remote ) {
			return false;
		}
		return version_compare( $remote['version'], AI_SITE_CONNECTOR_VERSION, '>' );
	}

	/* ---------------------------------------------------------------------
	 * Filter callbacks.
	 * ------------------------------------------------------------------ */

	/**
	 * Inject our plugin into the update-plugins site transient when a newer
	 * version is available on GitHub.
	 *
	 * @param mixed $transient The transient value (usually stdClass with ->response array).
	 * @return mixed Modified transient.
	 */
	public static function inject_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			// WP sometimes passes false during the very first install of a site;
			// hand it back untouched and let core fill it in.
			return $transient;
		}

		$remote = self::get_remote_release();
		if ( ! $remote ) {
			return $transient;
		}

		if ( ! version_compare( $remote['version'], AI_SITE_CONNECTOR_VERSION, '>' ) ) {
			// No update — but make sure stale entries don't linger.
			if ( isset( $transient->response[ AI_SITE_CONNECTOR_BASENAME ] ) ) {
				unset( $transient->response[ AI_SITE_CONNECTOR_BASENAME ] );
			}
			return $transient;
		}

		$plugin_info = (object) array(
			'id'             => 'github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
			'slug'           => self::PLUGIN_SLUG,
			'plugin'         => AI_SITE_CONNECTOR_BASENAME,
			'new_version'    => $remote['version'],
			'url'            => 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO,
			'package'        => $remote['zip_url'],
			'icons'          => self::asset_icons(),
			'banners'        => self::asset_banners(),
			'banners_rtl'    => array(),
			'tested'         => self::readme_field( 'tested', '' ),
			'requires_php'   => self::readme_field( 'requires_php', '' ),
			'compatibility'  => new stdClass(),
		);

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ AI_SITE_CONNECTOR_BASENAME ] = $plugin_info;

		return $transient;
	}

	/**
	 * Provide plugin info for the "View details" modal.
	 *
	 * @param false|object|array $result Default value (false).
	 * @param string             $action API action.
	 * @param object             $args   API args.
	 * @return false|object|array
	 */
	public static function plugins_api_filter( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
			return $result;
		}

		$remote = self::get_remote_release();
		if ( ! $remote ) {
			return $result;
		}

		return self::build_plugin_info( $remote );
	}

	/**
	 * Inline upgrade notice on the Plugins screen, under our row.
	 *
	 * @param array  $plugin_data Plugin header data.
	 * @param object $response    Update info object.
	 */
	public static function update_message( $plugin_data, $response ) {
		unset( $plugin_data );
		$remote = self::get_remote_release();
		if ( ! $remote || empty( $remote['body'] ) ) {
			return;
		}
		// Show first 280 chars of the release body as a teaser.
		$body = wp_strip_all_tags( $remote['body'] );
		$body = preg_replace( '/\s+/', ' ', $body );
		if ( strlen( $body ) > 280 ) {
			$body = substr( $body, 0, 280 ) . '…';
		}
		echo '<br /><strong>' . esc_html__( 'Release notes:', 'ai-site-connector' ) . '</strong> ' . esc_html( $body );
		unset( $response );
	}

	/**
	 * Defensive folder-rename hook. The release.zip extracts to `ai-site-connector/`
	 * already, so this only matters when we fall back to GitHub's auto-zipball
	 * (which extracts to `tyhallcsu-ai-site-connector-<sha>/`).
	 *
	 * @param string      $source        Source dir.
	 * @param string      $remote_source Parent dir.
	 * @param WP_Upgrader $upgrader      Upgrader instance.
	 * @param array       $hook_extra    Extra context.
	 * @return string|WP_Error
	 */
	public static function source_selection( $source, $remote_source, $upgrader, $hook_extra ) {
		unset( $upgrader );
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) ) {
			return $source;
		}
		if ( AI_SITE_CONNECTOR_BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}

		$desired_basename = self::PLUGIN_SLUG;
		$actual_basename  = basename( untrailingslashit( $source ) );

		if ( $desired_basename === $actual_basename ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		$new_source = trailingslashit( $remote_source ) . $desired_basename;
		if ( $wp_filesystem->exists( $new_source ) ) {
			$wp_filesystem->delete( $new_source, true );
		}
		if ( ! $wp_filesystem->move( $source, $new_source, true ) ) {
			return new WP_Error( 'ai_site_connector_rename_failed', __( 'Could not rename plugin folder to ai-site-connector after download.', 'ai-site-connector' ) );
		}
		return trailingslashit( $new_source );
	}

	/* ---------------------------------------------------------------------
	 * admin-post handlers.
	 * ------------------------------------------------------------------ */

	public static function handle_check_updates() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Insufficient permissions to check for plugin updates.', 'ai-site-connector' ) );
		}
		check_admin_referer( 'ai_site_connector_check_updates' );

		delete_site_transient( self::TRANSIENT_KEY );
		delete_site_transient( 'update_plugins' );

		$remote = self::get_remote_release();
		$err    = self::cached_error();

		if ( $remote ) {
			$is_newer = version_compare( $remote['version'], AI_SITE_CONNECTOR_VERSION, '>' );
			AI_Site_Connector_Audit_Log::record(
				'update_check_success',
				array(
					'message' => sprintf(
						/* translators: 1: remote version, 2: local version, 3: stable/prerelease channel. */
						__( 'Update check OK. Remote: %1$s, local: %2$s, channel: %3$s.', 'ai-site-connector' ),
						$remote['version'],
						AI_SITE_CONNECTOR_VERSION,
						self::include_prerelease() ? 'prerelease' : 'stable'
					),
				)
			);
			self::flash(
				$is_newer
					? sprintf(
						/* translators: %s: new version. */
						__( 'Update available: %s. Click "Update now" to install.', 'ai-site-connector' ),
						$remote['version']
					)
					: sprintf(
						/* translators: %s: current version. */
						__( 'You are running the latest version (%s).', 'ai-site-connector' ),
						AI_SITE_CONNECTOR_VERSION
					),
				'success'
			);
		} elseif ( $err ) {
			AI_Site_Connector_Audit_Log::record(
				'update_check_failed',
				array(
					'message' => sprintf(
						/* translators: %s: error code. */
						__( 'Update check failed. Code: %s.', 'ai-site-connector' ),
						$err['error']
					),
				)
			);
			self::flash(
				sprintf(
					/* translators: %s: error code. */
					__( 'Could not reach GitHub to check for updates (code: %s). Try again in ~30 minutes.', 'ai-site-connector' ),
					$err['error']
				),
				'error'
			);
		} else {
			self::flash( __( 'Update check completed but no release info was returned.', 'ai-site-connector' ), 'error' );
		}

		self::redirect_back();
	}

	public static function handle_run_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Insufficient permissions to update plugins.', 'ai-site-connector' ) );
		}
		check_admin_referer( 'ai_site_connector_run_update' );

		$remote = self::get_remote_release();
		if ( ! $remote || ! version_compare( $remote['version'], AI_SITE_CONNECTOR_VERSION, '>' ) ) {
			self::flash( __( 'Nothing to update — you are already on the latest version.', 'ai-site-connector' ), 'success' );
			self::redirect_back();
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Force a fresh update transient so Plugin_Upgrader sees our zip URL.
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$from_version = AI_SITE_CONNECTOR_VERSION;
		$to_version   = $remote['version'];
		$source       = ! empty( $remote['asset_name'] ) ? 'release_asset' : 'zipball';

		// Remember whether the plugin was active before the upgrade.
		// Plugin_Upgrader::upgrade() deactivates via upgrader_pre_install but
		// never re-activates — fixing that gap is the whole point of this
		// handler (see issue #44). If the plugin wasn't active to begin with,
		// we leave it deactivated.
		$was_active = is_plugin_active( AI_SITE_CONNECTOR_BASENAME );

		AI_Site_Connector_Audit_Log::record(
			'update_started',
			array(
				'message' => sprintf(
					/* translators: 1: from version, 2: to version, 3: source. */
					__( 'Self-update started: %1$s → %2$s (source: %3$s).', 'ai-site-connector' ),
					$from_version,
					$to_version,
					$source
				),
			)
		);

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( AI_SITE_CONNECTOR_BASENAME );

		if ( is_wp_error( $result ) ) {
			AI_Site_Connector_Audit_Log::record(
				'update_failed',
				array(
					'message' => sprintf(
						/* translators: 1: to version, 2: error message. */
						__( 'Self-update to %1$s failed: %2$s', 'ai-site-connector' ),
						$to_version,
						$result->get_error_message()
					),
				)
			);
			self::flash(
				sprintf(
					/* translators: %s: error message. */
					__( 'Update failed: %s', 'ai-site-connector' ),
					$result->get_error_message()
				),
				'error'
			);
		} elseif ( false === $result || null === $result ) {
			$skin_errors = method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null;
			$msg         = ( $skin_errors && is_wp_error( $skin_errors ) && $skin_errors->has_errors() )
				? $skin_errors->get_error_message()
				: __( 'Update reported no result; check error log.', 'ai-site-connector' );
			AI_Site_Connector_Audit_Log::record(
				'update_failed',
				array(
					'message' => sprintf(
						/* translators: 1: to version, 2: skin error. */
						__( 'Self-update to %1$s did not complete: %2$s', 'ai-site-connector' ),
						$to_version,
						$msg
					),
				)
			);
			self::flash( __( 'Update did not complete. Check the server error log.', 'ai-site-connector' ), 'error' );
		} else {
			AI_Site_Connector_Audit_Log::record(
				'update_completed',
				array(
					'message' => sprintf(
						/* translators: 1: from version, 2: to version. */
						__( 'Self-update completed: %1$s → %2$s.', 'ai-site-connector' ),
						$from_version,
						$to_version
					),
				)
			);

			// Re-activate post-swap (fixes #44). Plugin_Upgrader::upgrade()
			// deactivates via upgrader_pre_install but never re-activates on
			// the single-plugin code path — only the bulk-upgrade path does.
			// If the plugin wasn't active before the upgrade we leave it as-is.
			$reactivation_failed = false;
			if ( $was_active && ! is_plugin_active( AI_SITE_CONNECTOR_BASENAME ) ) {
				// activate_plugin( $plugin, $redirect, $network_wide, $silent )
				// Silent=false so the plugin's activation hook runs (re-registers
				// crons + roles + onboarding option).
				$activate = activate_plugin( AI_SITE_CONNECTOR_BASENAME, '', is_network_admin(), false );
				if ( is_wp_error( $activate ) ) {
					$reactivation_failed = true;
					AI_Site_Connector_Audit_Log::record(
						'update_reactivation_failed',
						array(
							'message' => sprintf(
								/* translators: %s: error message. */
								__( 'Re-activation after self-update to %1$s failed: %2$s', 'ai-site-connector' ),
								$to_version,
								$activate->get_error_message()
							),
						)
					);
				} else {
					AI_Site_Connector_Audit_Log::record(
						'update_reactivated',
						array(
							'message' => sprintf(
								/* translators: %s: new version. */
								__( 'Plugin re-activated after self-update to %s.', 'ai-site-connector' ),
								$to_version
							),
						)
					);
				}
			}

			self::flash(
				sprintf(
					/* translators: 1: from version, 2: to version. */
					__( 'Updated AI Site Connector from %1$s to %2$s.', 'ai-site-connector' ),
					$from_version,
					$to_version
				),
				$reactivation_failed ? 'error' : 'success'
			);
			// Clear our own cached release so the card refreshes.
			delete_site_transient( self::TRANSIENT_KEY );

			// If re-activation failed, the plugin's admin page isn't
			// registered — redirecting there would 403. Bounce to the core
			// Plugins screen instead so the operator sees the activation
			// error inline and can recover.
			if ( $reactivation_failed ) {
				wp_safe_redirect( admin_url( 'plugins.php?plugin_status=inactive&s=ai-site-connector' ) );
				exit;
			}
		}

		self::redirect_back();
	}

	/* ---------------------------------------------------------------------
	 * GitHub fetch + parse.
	 * ------------------------------------------------------------------ */

	/**
	 * Cached GitHub release fetch.
	 *
	 * @return array|false Parsed release on success, false on cached error or fresh failure.
	 */
	private static function get_remote_release() {
		if ( self::is_disabled() ) {
			return false;
		}

		$cached = get_site_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) ) {
			if ( ! empty( $cached['error'] ) ) {
				return false; // Recent failure — don't hammer.
			}
			if ( ! empty( $cached['version'] ) ) {
				return $cached;
			}
		}

		$release = self::fetch_from_github( self::include_prerelease() );
		if ( is_wp_error( $release ) ) {
			set_site_transient(
				self::TRANSIENT_KEY,
				array(
					'error'     => $release->get_error_code(),
					'cached_at' => time(),
				),
				self::ERROR_TTL
			);
			return false;
		}

		$parsed = self::parse_release( $release );
		if ( ! $parsed ) {
			set_site_transient(
				self::TRANSIENT_KEY,
				array(
					'error'     => 'invalid_release',
					'cached_at' => time(),
				),
				self::ERROR_TTL
			);
			return false;
		}

		set_site_transient( self::TRANSIENT_KEY, $parsed, self::TRANSIENT_TTL );
		return $parsed;
	}

	/**
	 * Hit the GitHub API.
	 *
	 * @param bool $include_prerelease Whether to consider pre-release tags.
	 * @return array|WP_Error The first valid release on success, WP_Error on failure.
	 */
	private static function fetch_from_github( $include_prerelease ) {
		$base = 'https://api.github.com/repos/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO;
		$url  = $include_prerelease ? $base . '/releases?per_page=10' : $base . '/releases/latest';

		$args = array(
			'timeout'    => 10,
			'sslverify'  => true,
			'user-agent' => 'ai-site-connector/' . AI_SITE_CONNECTOR_VERSION . '; ' . home_url( '/' ),
			'headers'    => array(
				'Accept' => 'application/vnd.github+json',
			),
		);

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'http_error', $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 403 === $code ) {
			$remaining = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
			if ( '0' === (string) $remaining ) {
				AI_Site_Connector_Audit_Log::record(
					'update_check_rate_limited',
					array(
						'message' => __( 'GitHub API rate limit reached during update check.', 'ai-site-connector' ),
					)
				);
				return new WP_Error( 'rate_limited', 'rate_limited' );
			}
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'http_' . $code, 'github_status_' . $code );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'json_decode', 'invalid_json' );
		}

		if ( $include_prerelease ) {
			foreach ( $data as $release ) {
				if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
					continue;
				}
				$tag = ltrim( $release['tag_name'], 'vV' );
				if ( self::is_valid_version( $tag ) ) {
					return $release;
				}
			}
			return new WP_Error( 'no_valid_release', 'no_valid_release' );
		}

		// /releases/latest — single object.
		if ( empty( $data['tag_name'] ) ) {
			return new WP_Error( 'no_release', 'no_release' );
		}
		return $data;
	}

	/**
	 * Validate & normalize a release into the cached shape.
	 *
	 * @param array $release Raw GitHub release object.
	 * @return array|false { version, zip_url, asset_name, body, published_at, is_prerelease, html_url }
	 */
	private static function parse_release( array $release ) {
		if ( empty( $release['tag_name'] ) ) {
			return false;
		}
		$version = ltrim( $release['tag_name'], 'vV' );
		if ( ! self::is_valid_version( $version ) ) {
			return false;
		}

		$asset_url  = '';
		$asset_name = '';
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['name'] ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				if ( preg_match( self::ASSET_PATTERN, $asset['name'] ) ) {
					$asset_url  = $asset['browser_download_url'];
					$asset_name = $asset['name'];
					break;
				}
			}
		}

		if ( ! $asset_url && ! empty( $release['zipball_url'] ) ) {
			$asset_url = $release['zipball_url'];
		}

		if ( ! $asset_url || 0 !== strpos( $asset_url, 'https://' ) ) {
			return false;
		}

		return array(
			'version'       => $version,
			'zip_url'       => $asset_url,
			'asset_name'    => $asset_name,
			'body'          => isset( $release['body'] ) ? (string) $release['body'] : '',
			'published_at'  => isset( $release['published_at'] ) ? (string) $release['published_at'] : '',
			'is_prerelease' => ! empty( $release['prerelease'] ),
			'html_url'      => isset( $release['html_url'] ) ? (string) $release['html_url'] : '',
		);
	}

	private static function is_valid_version( $version ) {
		return (bool) preg_match( self::VERSION_PATTERN, (string) $version );
	}

	/* ---------------------------------------------------------------------
	 * plugins_api response builder.
	 * ------------------------------------------------------------------ */

	private static function build_plugin_info( array $remote ) {
		$header = function_exists( 'get_plugin_data' ) ? get_plugin_data( AI_SITE_CONNECTOR_FILE, false, false ) : array();

		$changelog_md = trim( (string) $remote['body'] );
		$changelog    = $changelog_md ? wpautop( wp_kses_post( $changelog_md ) ) : __( 'See the GitHub release page for full release notes.', 'ai-site-connector' );

		$info                    = new stdClass();
		$info->name              = isset( $header['Name'] ) ? $header['Name'] : 'AI Site Connector';
		$info->slug              = self::PLUGIN_SLUG;
		$info->version           = $remote['version'];
		$info->author            = isset( $header['AuthorName'] ) ? $header['AuthorName'] : 'sharmanhall';
		$info->author_profile    = isset( $header['AuthorURI'] ) ? $header['AuthorURI'] : 'https://github.com/tyhallcsu';
		$info->homepage          = isset( $header['PluginURI'] ) ? $header['PluginURI'] : ( 'https://github.com/' . self::GITHUB_OWNER . '/' . self::GITHUB_REPO );
		$info->download_link     = $remote['zip_url'];
		$info->trunk             = $remote['zip_url'];
		$info->requires          = self::readme_field( 'requires_at_least', '5.6' );
		$info->tested            = self::readme_field( 'tested', '6.5' );
		$info->requires_php      = self::readme_field( 'requires_php', '7.4' );
		$info->last_updated      = $remote['published_at'];
		$info->added             = '';
		$info->banners           = self::asset_banners();
		$info->icons             = self::asset_icons();

		$readme = self::read_local_readme();
		$info->sections = array(
			'description'  => isset( $readme['sections']['description'] )
				? wpautop( wp_kses_post( $readme['sections']['description'] ) )
				: esc_html( isset( $header['Description'] ) ? $header['Description'] : '' ),
			'installation' => isset( $readme['sections']['installation'] )
				? wpautop( wp_kses_post( $readme['sections']['installation'] ) )
				: '',
			'faq'          => isset( $readme['sections']['frequently_asked_questions'] )
				? wpautop( wp_kses_post( $readme['sections']['frequently_asked_questions'] ) )
				: '',
			'changelog'    => $changelog,
		);

		return $info;
	}

	private static function asset_icons() {
		return array(
			'1x'      => self::RAW_BRAND_BASE . 'ai-site-connector-logo-256.png',
			'2x'      => self::RAW_BRAND_BASE . 'ai-site-connector-logo-512.png',
			'svg'     => self::RAW_BRAND_BASE . 'ai-site-connector-mark.svg',
			'default' => self::RAW_BRAND_BASE . 'ai-site-connector-logo-256.png',
		);
	}

	private static function asset_banners() {
		return array(
			'low'  => self::RAW_BRAND_BASE . 'ai-site-connector-banner.png',
			'high' => self::RAW_BRAND_BASE . 'ai-site-connector-banner.png',
		);
	}

	/* ---------------------------------------------------------------------
	 * readme.txt parser — minimal, just enough for the modal.
	 * ------------------------------------------------------------------ */

	private static function read_local_readme() {
		if ( null !== self::$readme_cache ) {
			return is_array( self::$readme_cache ) ? self::$readme_cache : array();
		}

		$path = AI_SITE_CONNECTOR_DIR . 'readme.txt';
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			self::$readme_cache = false;
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin file read; WP_Filesystem is overkill here.
		$raw = file_get_contents( $path );
		if ( false === $raw ) {
			self::$readme_cache = false;
			return array();
		}

		$parsed = array(
			'headers'  => array(),
			'sections' => array(),
		);

		// Header block: lines like `Stable tag: 0.1.0` before the first `==` section heading.
		$parts = preg_split( '/^==\s*(.+?)\s*==\s*$/m', $raw, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( is_array( $parts ) && count( $parts ) >= 1 ) {
			$header_block = $parts[0];
			if ( preg_match_all( '/^([A-Za-z][A-Za-z0-9 _]+):\s*(.+)$/m', $header_block, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $m ) {
					$key                       = strtolower( str_replace( ' ', '_', trim( $m[1] ) ) );
					$parsed['headers'][ $key ] = trim( $m[2] );
				}
			}

			// Remaining elements alternate: section title, section body.
			for ( $i = 1; $i + 1 < count( $parts ); $i += 2 ) {
				$title = strtolower( str_replace( ' ', '_', trim( $parts[ $i ] ) ) );
				$body  = trim( $parts[ $i + 1 ] );
				$parsed['sections'][ $title ] = $body;
			}
		}

		self::$readme_cache = $parsed;
		return $parsed;
	}

	private static function readme_field( $key, $default ) {
		$readme = self::read_local_readme();
		if ( isset( $readme['headers'][ $key ] ) ) {
			return $readme['headers'][ $key ];
		}
		return $default;
	}

	/* ---------------------------------------------------------------------
	 * Flash + redirect — uses the admin page's existing pattern so the same
	 * notice rendering logic handles updater messages.
	 * ------------------------------------------------------------------ */

	private static function flash( $msg, $type = 'success' ) {
		set_transient(
			AI_Site_Connector_Admin_Page::FLASH_OPTION . '_' . get_current_user_id(),
			array(
				'msg'   => (string) $msg,
				'type'  => (string) $type,
				'extra' => array(),
			),
			60
		);
	}

	private static function redirect_back() {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => AI_Site_Connector_Admin_Page::PAGE_SLUG,
					'tab'  => 'overview',
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}
}
