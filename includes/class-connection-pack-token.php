<?php
/**
 * One-time-token connection-pack delivery.
 *
 * Generating an Application Password produces a flash notice with the
 * plaintext password inside the wp-admin screen. Useful when the admin
 * is also the user of the credential, awkward when they're handing it
 * off to someone else: "copy this JSON and paste it in Slack" leaks the
 * password into chat history.
 *
 * Instead, mint a single-use signed URL the admin can DM:
 *
 *   GET /wp-json/ai-site-connector/v1/connection-pack/{token}
 *
 * The token validates a 5-minute site-transient holding the user_id and
 * App Password UUID. On first read, the transient is deleted and the
 * pack returns as a downloadable JSON file; subsequent reads return 410.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Connection_Pack_Token {

	const TTL_SECONDS    = 300; // 5 minutes
	const TRANSIENT_PRE  = 'ai_site_connector_pack_token_';

	/**
	 * Mint a new token for the given pack. The pack itself is stored in the
	 * transient (not just a reference) so we don't have to re-derive it
	 * (which would require the user to know the plaintext password — which
	 * is by design only available at creation time).
	 *
	 * @param int    $user_id
	 * @param string $uuid
	 * @param array  $pack   Full connection pack JSON-able array.
	 * @return string Plain token (caller assembles into the URL).
	 */
	public static function mint( $user_id, $uuid, array $pack ) {
		$token = wp_generate_password( 48, false, false );
		$key   = self::TRANSIENT_PRE . hash( 'sha256', $token );
		$value = array(
			'user_id'   => (int) $user_id,
			'uuid'      => (string) $uuid,
			'pack'      => $pack,
			'minted_at' => time(),
		);
		set_site_transient( $key, $value, self::TTL_SECONDS );

		AI_Site_Connector_Audit_Log::record(
			'pack_download_token_minted',
			array(
				'target_user_id' => (int) $user_id,
				'message'        => sprintf( 'One-time-token download minted for uuid=%s (user id %d).', $uuid, $user_id ),
				'meta'           => array( 'uuid' => $uuid, 'ttl_seconds' => self::TTL_SECONDS ),
			)
		);
		return $token;
	}

	/**
	 * Build the download URL for a freshly-minted token.
	 */
	public static function build_url( $token ) {
		return rest_url( AI_SITE_CONNECTOR_REST_NAMESPACE . '/connection-pack/' . rawurlencode( $token ) );
	}

	/**
	 * Resolve a token, consume the transient, and return the pack. Returns
	 * WP_Error on missing/expired/already-used.
	 *
	 * @param string $token
	 * @return array|WP_Error The pack array on success.
	 */
	public static function consume( $token ) {
		$token = (string) $token;
		if ( '' === $token ) {
			return new WP_Error( 'pack_token_missing', __( 'No token supplied.', 'ai-site-connector' ), array( 'status' => 400 ) );
		}
		$key  = self::TRANSIENT_PRE . hash( 'sha256', $token );
		$data = get_site_transient( $key );
		if ( ! is_array( $data ) || empty( $data['pack'] ) ) {
			return new WP_Error( 'pack_token_invalid', __( 'This download link has expired or already been used.', 'ai-site-connector' ), array( 'status' => 410 ) );
		}
		// Single-use: delete BEFORE returning so a network retry can't reread.
		delete_site_transient( $key );

		AI_Site_Connector_Audit_Log::record(
			'connection_pack_downloaded',
			array(
				'target_user_id' => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
				'message'        => sprintf( 'Connection pack downloaded via one-time-token for uuid=%s.', isset( $data['uuid'] ) ? $data['uuid'] : '?' ),
				'meta'           => array( 'uuid' => isset( $data['uuid'] ) ? $data['uuid'] : '' ),
			)
		);
		return $data['pack'];
	}
}
