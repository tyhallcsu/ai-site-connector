<?php
/**
 * Unit tests for AI_Site_Connector_Application_Passwords wrapper.
 *
 * @package AI_Site_Connector_Tests
 */

namespace AISiteConnector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Mock;

class ApplicationPasswordsTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		require_once dirname( __DIR__, 2 ) . '/includes/class-application-passwords.php';
		$GLOBALS['__did_actions'] = array();
		\WP_Application_Passwords::$deleted_with = null;
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_suggested_name_includes_host_and_date(): void {
		$name = \AI_Site_Connector_Application_Passwords::suggested_name();
		$this->assertStringContainsString( 'example.com', $name );
		$this->assertMatchesRegularExpression( '/\d{4}-\d{2}-\d{2}$/', $name );
	}

	public function test_revoke_fires_do_action_signal(): void {
		$res = \AI_Site_Connector_Application_Passwords::revoke( 42, 'uuid-abc' );
		$this->assertTrue( $res );

		$this->assertArrayHasKey( 'ai_site_connector_application_password_revoked', $GLOBALS['__did_actions'] );
		$this->assertSame( array( 42, 'uuid-abc' ), $GLOBALS['__did_actions']['ai_site_connector_application_password_revoked'][0] );
		$this->assertSame( array( 42, 'uuid-abc' ), \WP_Application_Passwords::$deleted_with );
	}
}
