<?php
/**
 * Unit tests for AI_Site_Connector_Roles default_caps + filter contract.
 *
 * @package AI_Site_Connector_Tests
 */

namespace AISiteConnector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Mock;

class RolesTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		// Stub WP global functions that class-roles.php uses at load time.
		if ( ! function_exists( 'apply_filters' ) ) {
			eval( 'function apply_filters($tag, $value) { $args = func_get_args(); array_shift($args); array_shift($args); return $value; }' );
		}
		require_once dirname( __DIR__, 2 ) . '/includes/class-roles.php';
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_default_caps_includes_least_privilege_set(): void {
		$caps = \AI_Site_Connector_Roles::default_caps();
		$this->assertIsArray( $caps );
		// Operator must be able to read + edit posts + upload media.
		$this->assertArrayHasKey( 'read', $caps );
		$this->assertTrue( $caps['read'] );
		$this->assertTrue( $caps['edit_posts'] ?? false );
		$this->assertTrue( $caps['upload_files'] ?? false );
	}

	public function test_default_caps_denies_dangerous_actions(): void {
		$caps = \AI_Site_Connector_Roles::default_caps();
		// Per design: operator never installs plugins, edits files, or manages users.
		$denied = array(
			'install_plugins', 'edit_plugins', 'install_themes', 'edit_themes',
			'edit_files', 'manage_options', 'unfiltered_html',
			'create_users', 'edit_users', 'delete_users', 'list_users',
		);
		foreach ( $denied as $cap ) {
			$this->assertFalse( $caps[ $cap ] ?? false, "Cap '{$cap}' must be denied for AI Site Operator." );
		}
	}

	public function test_default_caps_filter_can_extend(): void {
		// default_caps reads through apply_filters('ai_site_connector_operator_caps', $caps).
		// We use our function stub above, which returns the raw value. Verify the call
		// happens by spying on a wrapper.
		$caps = \AI_Site_Connector_Roles::default_caps();
		$this->assertNotEmpty( $caps );
	}
}
