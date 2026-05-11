<?php
/**
 * Unit tests for AI_Site_Connector_Application_Passwords.
 *
 * The class is a thin wrapper around WP core's WP_Application_Passwords;
 * here we verify the wrapper's contract: return shape on success, error
 * envelopes on failure, and that revoke() fires the
 * ai_site_connector_application_password_revoked do_action signal.
 *
 * @package AI_Site_Connector_Tests
 */

namespace AISiteConnector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Mock;

class ApplicationPasswordsTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();

		// Function stubs the wrapper expects at load time.
		if ( ! function_exists( 'sanitize_text_field' ) ) {
			eval( 'function sanitize_text_field($s) { return is_string($s) ? trim($s) : ""; }' );
		}
		if ( ! function_exists( 'sanitize_user' ) ) {
			eval( 'function sanitize_user($s, $strict = false) { return preg_replace("/[^a-z0-9_]/i", "", (string) $s); }' );
		}
		if ( ! function_exists( '__' ) ) {
			eval( 'function __($s, $domain = "") { return $s; }' );
		}
		if ( ! function_exists( 'home_url' ) ) {
			eval( 'function home_url($path = "") { return "https://example.com" . $path; }' );
		}
		if ( ! function_exists( 'wp_parse_url' ) ) {
			eval( 'function wp_parse_url($u, $c = -1) { $p = parse_url($u); return $c === -1 ? $p : ($p[array_keys(array_flip(["scheme"=>0,"host"=>1,"port"=>2,"user"=>3,"pass"=>4,"path"=>5,"query"=>6,"fragment"=>7]))[$c] ?? null] ?? null); }' );
		}
		if ( ! function_exists( 'do_action' ) ) {
			eval( 'function do_action($tag, ...$args) { $GLOBALS["__did_actions"][$tag][] = $args; }' );
		}
		if ( ! function_exists( 'apply_filters' ) ) {
			eval( 'function apply_filters($tag, $value) { return $value; }' );
		}
		$GLOBALS['__did_actions'] = array();

		// Real class under test.
		require_once dirname( __DIR__, 2 ) . '/includes/class-application-passwords.php';
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
		// Mock the core class so the wrapper can call into it without WP.
		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			eval( 'class WP_Application_Passwords { public static $deleted_with = null; public static function delete_application_password($u, $uuid) { self::$deleted_with = [$u, $uuid]; return true; } public static function get_user_application_passwords($u) { return []; } }' );
		}
		// Stub the plugin singleton's app_passwords_available() check.
		if ( ! class_exists( 'AI_Site_Connector_Plugin' ) ) {
			eval( 'class AI_Site_Connector_Plugin { public static function app_passwords_available() { return true; } }' );
		}
		// Stub the Audit_Log record — we don't assert on its content here.
		if ( ! class_exists( 'AI_Site_Connector_Audit_Log' ) ) {
			eval( 'class AI_Site_Connector_Audit_Log { public static function record($a, $args = []) {} }' );
		}

		$res = \AI_Site_Connector_Application_Passwords::revoke( 42, 'uuid-abc' );
		$this->assertTrue( $res );

		$this->assertArrayHasKey( 'ai_site_connector_application_password_revoked', $GLOBALS['__did_actions'] );
		$this->assertSame( array( 42, 'uuid-abc' ), $GLOBALS['__did_actions']['ai_site_connector_application_password_revoked'][0] );
	}
}
