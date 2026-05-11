<?php
/**
 * Unit tests for AI_Site_Connector_Url_Guard::is_blocked_ip.
 *
 * Pure-function coverage of the SSRF blocklist — the IP categorization layer
 * underneath check_outbound_safe(). The DNS-resolving paths need a network
 * mock and are exercised at runtime-smoke level instead.
 *
 * @package AI_Site_Connector_Tests
 */

namespace AISiteConnector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Mock;

class UrlGuardTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		require_once dirname( __DIR__, 2 ) . '/includes/class-url-guard.php';
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_public_ipv4_addresses_are_allowed(): void {
		$this->assertFalse( \AI_Site_Connector_Url_Guard::is_blocked_ip( '8.8.8.8' ) );
		$this->assertFalse( \AI_Site_Connector_Url_Guard::is_blocked_ip( '1.1.1.1' ) );
		$this->assertFalse( \AI_Site_Connector_Url_Guard::is_blocked_ip( '93.184.216.34' ) );
	}

	public function test_public_ipv6_addresses_are_allowed(): void {
		$this->assertFalse( \AI_Site_Connector_Url_Guard::is_blocked_ip( '2001:4860:4860::8888' ) ); // Google DNS
		$this->assertFalse( \AI_Site_Connector_Url_Guard::is_blocked_ip( '2606:4700:4700::1111' ) ); // Cloudflare DNS
	}

	public function test_loopback_addresses_are_blocked(): void {
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( '127.0.0.1' ) );
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( '127.255.255.254' ) );
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( '::1' ) );
	}

	public function test_rfc1918_private_addresses_are_blocked(): void {
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( '10.0.0.1' ) );
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( '10.255.255.255' ) );
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( '172.16.0.1' ) );
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( '172.31.255.255' ) );
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( '192.168.1.1' ) );
	}

	public function test_link_local_and_metadata_addresses_are_blocked(): void {
		// 169.254.0.0/16 — link-local. 169.254.169.254 is the AWS/GCP metadata endpoint.
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( '169.254.169.254' ) );
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( '169.254.0.1' ) );
		// IPv6 fe80::/10 link-local.
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( 'fe80::1' ) );
	}

	public function test_ipv6_unique_local_addresses_are_blocked(): void {
		// fc00::/7
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( 'fc00::1' ) );
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( 'fd12:3456:789a::1' ) );
	}

	public function test_garbage_inputs_are_blocked_conservatively(): void {
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( '' ) );
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( 'not-an-ip' ) );
		$this->assertTrue( \AI_Site_Connector_Url_Guard::is_blocked_ip( '999.999.999.999' ) );
	}

	public function test_resolve_all_short_circuits_on_ip_literal(): void {
		$this->assertSame( array( '93.184.216.34' ), \AI_Site_Connector_Url_Guard::resolve_all( '93.184.216.34' ) );
		$this->assertSame( array( '2001:4860:4860::8888' ), \AI_Site_Connector_Url_Guard::resolve_all( '[2001:4860:4860::8888]' ) );
	}

	public function test_check_outbound_safe_rejects_bad_scheme(): void {
		WP_Mock::userFunction( 'apply_filters', array( 'return' => false ) );
		$result = \AI_Site_Connector_Url_Guard::check_outbound_safe( 'file:///etc/passwd' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'asc_url_bad_scheme', $result->get_error_code() );
	}

	public function test_check_outbound_safe_rejects_malformed_url(): void {
		$result = \AI_Site_Connector_Url_Guard::check_outbound_safe( 'not a url' );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_check_outbound_safe_rejects_loopback_literal(): void {
		WP_Mock::userFunction( 'apply_filters', array( 'return' => false ) );
		$result = \AI_Site_Connector_Url_Guard::check_outbound_safe( 'http://127.0.0.1:80/' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'asc_url_internal', $result->get_error_code() );
	}

	public function test_check_outbound_safe_rejects_metadata_endpoint(): void {
		WP_Mock::userFunction( 'apply_filters', array( 'return' => false ) );
		$result = \AI_Site_Connector_Url_Guard::check_outbound_safe( 'http://169.254.169.254/latest/meta-data/' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'asc_url_internal', $result->get_error_code() );
	}

	public function test_check_outbound_safe_filter_can_allow_a_host(): void {
		WP_Mock::userFunction( 'apply_filters', array( 'return' => true ) );
		$result = \AI_Site_Connector_Url_Guard::check_outbound_safe( 'http://10.0.0.5/internal' );
		$this->assertTrue( $result );
	}
}
