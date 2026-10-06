<?php
/**
 * Admin page markup regressions found by the dev-site headless audit
 * (#102, #104, #105). Renders tabs in-process as the admin user; tabs that
 * make loopback or GitHub requests while rendering (onboarding, overview,
 * connection, diagnostics) are skipped to keep the suite offline.
 *
 * @package AI_Site_Connector_Tests
 */

/**
 * Render one admin tab and return its HTML.
 *
 * @param string $tab Tab key.
 * @return string
 */
function asc_it_render_admin_tab( $tab ) {
	$previous    = isset( $_GET['tab'] ) ? $_GET['tab'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- Test helper saves and restores the raw value.
	$_GET['tab'] = $tab;
	ob_start();
	try {
		AI_Site_Connector_Admin_Page::render_page();
	} finally {
		$html = (string) ob_get_clean();
		if ( null === $previous ) {
			unset( $_GET['tab'] );
		} else {
			$_GET['tab'] = $previous;
		}
	}
	return $html;
}

asc_it(
	'admin ui: permission checkboxes each have a label (#102)',
	function () {
		$html = asc_it_render_admin_tab( 'permissions' );
		preg_match_all( '/<input type="checkbox" id="([^"]+)" name="ai_site_connector_perms\[([a-z_]+)\]"/', $html, $boxes );
		asc_assert( count( $boxes[1] ) >= 9, 'expected the per-tool checkboxes, found ' . count( $boxes[1] ) );
		asc_assert_same( count( AI_Site_Connector_Permissions::get_all() ), count( $boxes[1] ), 'one checkbox per permission' );
		foreach ( $boxes[1] as $i => $id ) {
			asc_assert_same( 'asc-perm-' . $boxes[2][ $i ], $id, 'checkbox id' );
			asc_assert( false !== strpos( $html, '<label for="' . $id . '">' ), 'no label for ' . $id );
		}
	}
);

asc_it(
	'admin ui: offline tabs have no duplicate ids and a valid nonce (#102)',
	function () {
		foreach ( array( 'wizard', 'credentials', 'permissions', 'audit', 'api', 'export', 'docs' ) as $tab ) {
			$html = asc_it_render_admin_tab( $tab );
			preg_match_all( '/\sid="([^"]+)"/', $html, $ids );
			$dupes = array_keys( array_filter( array_count_values( $ids[1] ), static function ( $n ) {
				return $n > 1;
			} ) );
			asc_assert_same( array(), $dupes, "duplicate ids on tab {$tab}" );
			asc_assert( false !== strpos( $html, '<hr class="wp-header-end">' ), "no header-end marker on tab {$tab}" );
			if ( preg_match( '/name="ai_site_connector_nonce" value="([^"]+)"/', $html, $nonce ) ) {
				asc_assert( false !== wp_verify_nonce( $nonce[1], AI_Site_Connector_Admin_Page::NONCE_ACTION ), "nonce on tab {$tab} does not verify" );
			}
		}
	}
);

asc_it(
	'admin ui: REST self-test return tab is allow-listed (#104)',
	function () {
		$allowed = array( 'overview', 'connection' );
		asc_assert_same( 'connection', AI_Site_Connector_Admin_Page::allowed_return_tab( 'connection', $allowed, 'overview' ), 'connection' );
		asc_assert_same( 'overview', AI_Site_Connector_Admin_Page::allowed_return_tab( 'overview', $allowed, 'overview' ), 'overview' );
		asc_assert_same( 'overview', AI_Site_Connector_Admin_Page::allowed_return_tab( 'credentials', $allowed, 'overview' ), 'other tab' );
		asc_assert_same( 'overview', AI_Site_Connector_Admin_Page::allowed_return_tab( '', $allowed, 'overview' ), 'empty' );
		$html = asc_it_render_admin_tab( 'permissions' );
		asc_assert( false === strpos( $html, 'name="return_tab"' ), 'return_tab only belongs to the Connection Test form' );
	}
);

asc_it(
	'admin ui: header logo is the small, versioned mark (#125, #131)',
	function () {
		$html = asc_it_render_admin_tab( 'docs' );
		asc_assert( false !== strpos( $html, 'assets/ai-site-connector-mark-128.png?ver=' . AI_SITE_CONNECTOR_VERSION ), 'header logo is not the versioned 128px mark' );
		$file = AI_SITE_CONNECTOR_DIR . 'assets/ai-site-connector-mark-128.png';
		asc_assert( is_readable( $file ) && filesize( $file ) <= 20480, 'header mark missing or over 20 KiB' );
		$size = getimagesize( $file );
		asc_assert( is_array( $size ) && 128 === $size[0] && 128 === $size[1], 'header mark is not 128x128' );
	}
);
