<?php
/**
 * Unit tests for the /.well-known/ai-site-connector.json payload shape.
 *
 * The payload is the public contract documented in docs/DISCOVERY.md.
 * Renaming or removing a field requires a spec_version bump — these tests
 * pin the current shape so accidental drift fails CI.
 *
 * @package AI_Site_Connector_Tests
 */

namespace AISiteConnector\Tests;

use PHPUnit\Framework\TestCase;
use WP_Mock;

class DiscoveryTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		require_once dirname( __DIR__, 2 ) . '/includes/class-discovery.php';

		WP_Mock::userFunction(
			'rest_url',
			array(
				'return' => function ( $path = '' ) {
					return 'https://example.com/wp-json/' . ltrim( (string) $path, '/' );
				},
			)
		);
		WP_Mock::userFunction(
			'trailingslashit',
			array(
				'return' => function ( $s ) {
					return rtrim( (string) $s, '/' ) . '/';
				},
			)
		);
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_payload_pins_top_level_keys(): void {
		$payload = \AI_Site_Connector_Discovery::build_payload();
		$expected = array(
			'spec_version',
			'plugin',
			'version',
			'homepage',
			'rest_namespace',
			'rest_base',
			'openapi_url',
			'tools_catalog_url',
			'mcp',
			'auth_methods',
			'status',
		);
		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, $payload, "Discovery payload is missing required key '{$key}'." );
		}
	}

	public function test_payload_identifies_the_plugin(): void {
		$payload = \AI_Site_Connector_Discovery::build_payload();
		$this->assertSame( '1', $payload['spec_version'] );
		$this->assertSame( 'ai-site-connector', $payload['plugin'] );
		$this->assertSame( AI_SITE_CONNECTOR_REST_NAMESPACE, $payload['rest_namespace'] );
	}

	public function test_payload_resolves_full_urls(): void {
		$payload = \AI_Site_Connector_Discovery::build_payload();
		$this->assertSame( 'https://example.com/wp-json/ai-site-connector/v1', $payload['rest_base'] );
		$this->assertSame( 'https://example.com/wp-json/ai-site-connector/v1/openapi.json', $payload['openapi_url'] );
		$this->assertSame( 'https://example.com/wp-json/ai-site-connector/v1/tools', $payload['tools_catalog_url'] );
		$this->assertIsArray( $payload['mcp'] );
		$this->assertSame( 'https://example.com/wp-json/ai-site-connector/v1/mcp', $payload['mcp']['http'] );
	}

	public function test_payload_advertises_basic_auth_application_password(): void {
		$payload = \AI_Site_Connector_Discovery::build_payload();
		$this->assertIsArray( $payload['auth_methods'] );
		$this->assertContains( 'basic_auth_application_password', $payload['auth_methods'] );
	}

	public function test_payload_json_encodes_cleanly(): void {
		$encoded = json_encode( \AI_Site_Connector_Discovery::build_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$this->assertIsString( $encoded );
		$decoded = json_decode( $encoded, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( 'ai-site-connector', $decoded['plugin'] );
	}
}
