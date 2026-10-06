<?php
/**
 * Installed Plugins row (#127): update/check actions and the status line
 * follow the updater's cached release; only for users who may update.
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'installed plugins: update or check action and status line follow the cached release (#127)',
	function () {
		$key     = AI_Site_Connector_Updater::TRANSIENT_KEY;
		$prev    = get_site_transient( $key );
		$release = static function ( $version ) {
			return array(
				'version'       => $version,
				'zip_url'       => 'https://example.invalid/ai-site-connector.zip',
				'asset_name'    => 'ai-site-connector.zip',
				'body'          => '',
				'published_at'  => '2026-10-06T00:00:00Z',
				'is_prerelease' => false,
				'html_url'      => 'https://github.com/tyhallcsu/ai-site-connector/releases/tag/v' . $version,
			);
		};
		$sub = asc_it_user( 'subscriber' );
		try {
			set_site_transient( $key, $release( '99.0.0' ), HOUR_IN_SECONDS );
			$links = AI_Site_Connector_Updater::plugin_action_links( array() );
			asc_assert( isset( $links['asc_update'] ) && false !== strpos( $links['asc_update'], 'Update to v99.0.0' ), 'update action missing: ' . wp_json_encode( $links ) );
			asc_assert( false !== strpos( $links['asc_update'], 'action=upgrade-plugin' ) && false !== strpos( $links['asc_update'], '_wpnonce=' ), 'update action is not the core upgrader with a nonce' );
			$meta = implode( ' | ', AI_Site_Connector_Updater::plugin_row_meta( array(), AI_SITE_CONNECTOR_BASENAME ) );
			foreach ( array( 'Update available: v99.0.0', 'section=changelog', 'releases/tag/v99.0.0' ) as $needle ) {
				asc_assert( false !== strpos( $meta, $needle ), "row meta lacks {$needle}: {$meta}" );
			}

			set_site_transient( $key, $release( AI_SITE_CONNECTOR_VERSION ), HOUR_IN_SECONDS );
			$links = AI_Site_Connector_Updater::plugin_action_links( array() );
			asc_assert( isset( $links['asc_check'] ) && ! isset( $links['asc_update'] ), 'check action missing when up to date' );
			asc_assert( false !== strpos( $links['asc_check'], 'action=ai_site_connector_check_updates' ) && false !== strpos( $links['asc_check'], 'return=plugins' ), 'check action target' );
			$meta = implode( ' | ', AI_Site_Connector_Updater::plugin_row_meta( array(), AI_SITE_CONNECTOR_BASENAME ) );
			asc_assert( false !== strpos( $meta, 'Up to date (v' . AI_SITE_CONNECTOR_VERSION . ')' ), 'up-to-date status: ' . $meta );

			set_site_transient(
				$key,
				array(
					'error'     => 'rate_limited',
					'cached_at' => time(),
				),
				HOUR_IN_SECONDS
			);
			$meta = implode( ' | ', AI_Site_Connector_Updater::plugin_row_meta( array(), AI_SITE_CONNECTOR_BASENAME ) );
			asc_assert( false !== strpos( $meta, 'Update check failed (rate_limited)' ), 'failed-check status: ' . $meta );

			asc_assert_same( array( 'kept' ), AI_Site_Connector_Updater::plugin_row_meta( array( 'kept' ), 'hello.php' ), 'another plugin row was changed' );
			$as_sub = asc_it_as_user(
				$sub,
				function () {
					return array(
						AI_Site_Connector_Updater::plugin_action_links( array( 'kept' ) ),
						AI_Site_Connector_Updater::plugin_row_meta( array( 'kept' ), AI_SITE_CONNECTOR_BASENAME ),
					);
				}
			);
			asc_assert_same( array( array( 'kept' ), array( 'kept' ) ), $as_sub, 'a subscriber saw update controls' );
		} finally {
			asc_it_delete_user( $sub );
			if ( false === $prev ) {
				delete_site_transient( $key );
			} else {
				set_site_transient( $key, $prev, HOUR_IN_SECONDS );
			}
		}
	}
);
