<?php
/**
 * Unit tests for AI_Site_Connector_App_Password_Meta matcher helpers.
 *
 * @package AI_Site_Connector_Tests
 */

namespace AISiteConnector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Mock;

class AppPasswordMetaTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		require_once dirname( __DIR__, 2 ) . '/includes/class-app-password-meta.php';
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_ip_matches_cidr_ipv4_match(): void {
		$this->assertTrue( \AI_Site_Connector_App_Password_Meta::ip_matches_cidr( '192.0.2.42', array( '192.0.2.0/24' ) ) );
	}

	public function test_ip_matches_cidr_ipv4_miss(): void {
		$this->assertFalse( \AI_Site_Connector_App_Password_Meta::ip_matches_cidr( '198.51.100.7', array( '192.0.2.0/24' ) ) );
	}

	public function test_ip_matches_cidr_ipv6_match(): void {
		$this->assertTrue( \AI_Site_Connector_App_Password_Meta::ip_matches_cidr( '2001:db8::abcd', array( '2001:db8::/32' ) ) );
	}

	public function test_ip_matches_cidr_bare_ip_match(): void {
		$this->assertTrue( \AI_Site_Connector_App_Password_Meta::ip_matches_cidr( '203.0.113.5', array( '203.0.113.5' ) ) );
	}

	public function test_ip_matches_cidr_empty_list_returns_false(): void {
		$this->assertFalse( \AI_Site_Connector_App_Password_Meta::ip_matches_cidr( '192.0.2.1', array() ) );
	}

	public function test_route_matches_scopes_empty_allows(): void {
		$this->assertTrue( \AI_Site_Connector_App_Password_Meta::route_matches_scopes( 'GET', '/wp/v2/posts', array() ) );
	}

	public function test_route_matches_scopes_exact_match(): void {
		$scopes = array( array( 'method' => 'GET', 'route' => '/wp/v2/posts' ) );
		$this->assertTrue( \AI_Site_Connector_App_Password_Meta::route_matches_scopes( 'GET', '/wp/v2/posts', $scopes ) );
		$this->assertFalse( \AI_Site_Connector_App_Password_Meta::route_matches_scopes( 'POST', '/wp/v2/posts', $scopes ) );
	}

	public function test_route_matches_scopes_wildcard(): void {
		$scopes = array( array( 'method' => '*', 'route' => '/wp/v2/posts/*' ) );
		$this->assertTrue( \AI_Site_Connector_App_Password_Meta::route_matches_scopes( 'GET', '/wp/v2/posts/123', $scopes ) );
		$this->assertTrue( \AI_Site_Connector_App_Password_Meta::route_matches_scopes( 'DELETE', '/wp/v2/posts/123', $scopes ) );
		$this->assertFalse( \AI_Site_Connector_App_Password_Meta::route_matches_scopes( 'GET', '/wp/v2/pages', $scopes ) );
	}
}
