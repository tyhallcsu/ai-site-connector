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
		require_once dirname( __DIR__, 2 ) . '/includes/class-roles.php';
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_default_caps_includes_least_privilege_set(): void {
		$caps = \AI_Site_Connector_Roles::default_caps();
		$this->assertIsArray( $caps );
		$this->assertTrue( $caps['read'] ?? false );
		$this->assertTrue( $caps['edit_posts'] ?? false );
		$this->assertTrue( $caps['upload_files'] ?? false );
	}

	public function test_default_caps_denies_dangerous_actions(): void {
		$caps   = \AI_Site_Connector_Roles::default_caps();
		$denied = array(
			'install_plugins', 'edit_plugins', 'install_themes', 'edit_themes',
			'edit_files', 'manage_options', 'unfiltered_html',
			'create_users', 'edit_users', 'delete_users', 'list_users',
		);
		foreach ( $denied as $cap ) {
			$this->assertFalse( $caps[ $cap ] ?? false, "Cap '{$cap}' must be denied for AI Site Operator." );
		}
	}
}
