<?php
/**
 * Unit tests for AI_Site_Connector_Permissions catalog + permission gate.
 *
 * Covers:
 *  - catalog() shape and conservative-default contract
 *  - get_all() defaults + override merge
 *  - require_permission() deny paths: unknown tool, read-only mode, wp_cap miss,
 *    whitelist off, filter override
 *  - require_permission() allow path returns true
 *
 * @package AI_Site_Connector_Tests
 */

namespace AISiteConnector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Mock;

class PermissionsTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		require_once dirname( __DIR__, 2 ) . '/includes/class-permissions.php';
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	private function stub_options( array $map ): void {
		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => function ( $key, $default = false ) use ( $map ) {
					return array_key_exists( $key, $map ) ? $map[ $key ] : $default;
				},
			)
		);
	}

	public function test_catalog_includes_read_only_and_write_tools(): void {
		$catalog = \AI_Site_Connector_Permissions::catalog();
		$this->assertArrayHasKey( 'read_content', $catalog );
		$this->assertArrayHasKey( 'purge_cache', $catalog );
		$this->assertArrayHasKey( 'upload_media', $catalog );
	}

	public function test_catalog_defaults_are_conservative(): void {
		$catalog = \AI_Site_Connector_Permissions::catalog();
		// Read tools default on.
		$this->assertTrue( $catalog['read_content']['default'] );
		$this->assertTrue( $catalog['view_diagnostics']['default'] );
		$this->assertTrue( $catalog['export_manifest']['default'] );
		// Every write/admin tool defaults off — operators opt in explicitly.
		$write_keys = array( 'write_content', 'upload_media', 'update_seo', 'purge_cache', 'update_options', 'destructive_operations' );
		foreach ( $write_keys as $k ) {
			$this->assertFalse( $catalog[ $k ]['default'], "Tool '{$k}' must default to OFF." );
		}
	}

	public function test_get_all_falls_back_to_catalog_defaults_when_no_option(): void {
		$this->stub_options( array() );
		$all = \AI_Site_Connector_Permissions::get_all();
		$this->assertTrue( $all['read_content']['enabled'] );
		$this->assertFalse( $all['purge_cache']['enabled'] );
	}

	public function test_get_all_honors_saved_overrides(): void {
		$this->stub_options(
			array(
				'ai_site_connector_tool_permissions' => array(
					'purge_cache'  => true,
					'read_content' => false,
				),
			)
		);
		$all = \AI_Site_Connector_Permissions::get_all();
		$this->assertTrue( $all['purge_cache']['enabled'] );
		$this->assertFalse( $all['read_content']['enabled'] );
	}

	public function test_require_permission_rejects_unknown_tool(): void {
		$this->stub_options( array() );
		$result = \AI_Site_Connector_Permissions::require_permission( 'nope_not_a_tool' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_tool', $result->get_error_code() );
	}

	public function test_require_permission_blocks_write_in_read_only_mode(): void {
		$this->stub_options(
			array(
				'ai_site_connector_read_only_mode'   => 1,
				'ai_site_connector_tool_permissions' => array( 'purge_cache' => true ),
			)
		);
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );

		$result = \AI_Site_Connector_Permissions::require_permission( 'purge_cache' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_tool', $result->get_error_code() );
	}

	public function test_require_permission_allows_read_in_read_only_mode(): void {
		$this->stub_options(
			array(
				'ai_site_connector_read_only_mode'   => 1,
				'ai_site_connector_tool_permissions' => array( 'read_content' => true ),
			)
		);
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction( 'apply_filters', array( 'return' => true ) );

		$result = \AI_Site_Connector_Permissions::require_permission( 'read_content' );
		$this->assertTrue( $result );
	}

	public function test_require_permission_blocks_when_whitelist_off(): void {
		$this->stub_options(
			array(
				'ai_site_connector_read_only_mode'   => 0,
				'ai_site_connector_tool_permissions' => array( 'purge_cache' => false ),
			)
		);
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );

		$result = \AI_Site_Connector_Permissions::require_permission( 'purge_cache' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_require_permission_blocks_when_wp_cap_missing(): void {
		$this->stub_options(
			array(
				'ai_site_connector_read_only_mode'   => 0,
				'ai_site_connector_tool_permissions' => array( 'purge_cache' => true ),
			)
		);
		WP_Mock::userFunction( 'current_user_can', array( 'return' => false ) );

		$result = \AI_Site_Connector_Permissions::require_permission( 'purge_cache' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_require_permission_allows_when_cap_present_and_whitelisted(): void {
		$this->stub_options(
			array(
				'ai_site_connector_read_only_mode'   => 0,
				'ai_site_connector_tool_permissions' => array( 'purge_cache' => true ),
			)
		);
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction( 'apply_filters', array( 'return' => true ) );

		$result = \AI_Site_Connector_Permissions::require_permission( 'purge_cache' );
		$this->assertTrue( $result );
	}
}
