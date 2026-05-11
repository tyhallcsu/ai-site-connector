<?php
/**
 * PHPUnit bootstrap — loads Composer autoload + wp-mock + WP function stubs.
 *
 * Stubs are defined here once so tests can require_once the production
 * class file without WordPress installed. Each stub is function_exists-
 * guarded so wp-mock or the real WP function (in integration mode) wins
 * when present.
 *
 * @package AI_Site_Connector_Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
if ( ! defined( 'AI_SITE_CONNECTOR_VERSION' ) ) {
	define( 'AI_SITE_CONNECTOR_VERSION', '0.8.0' );
}
if ( ! defined( 'AI_SITE_CONNECTOR_REST_NAMESPACE' ) ) {
	define( 'AI_SITE_CONNECTOR_REST_NAMESPACE', 'ai-site-connector/v1' );
}
if ( ! defined( 'AI_SITE_CONNECTOR_OPERATOR_ROLE' ) ) {
	define( 'AI_SITE_CONNECTOR_OPERATOR_ROLE', 'ai_site_operator' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

$autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';
if ( ! is_file( $autoload ) ) {
	fwrite( STDERR, "Run `composer install` first.\n" );
	exit( 1 );
}
require_once $autoload;

WP_Mock::bootstrap();

// --- Minimal WP function stubs used by the production classes under test ---

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return is_string( $s ) ? trim( $s ) : '';
	}
}
if ( ! function_exists( 'sanitize_user' ) ) {
	function sanitize_user( $s, $strict = false ) {
		return preg_replace( '/[^a-z0-9_.\-@]/i', '', (string) $s );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $s ) {
		return strtolower( preg_replace( '/[^a-z0-9_]/i', '', (string) $s ) );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $s, $domain = '' ) {
		return $s;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://example.com' . $path;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		$parsed = parse_url( $url );
		if ( -1 === $component ) {
			return $parsed;
		}
		$map = array( PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PORT => 'port', PHP_URL_USER => 'user', PHP_URL_PASS => 'pass', PHP_URL_PATH => 'path', PHP_URL_QUERY => 'query', PHP_URL_FRAGMENT => 'fragment' );
		$key = $map[ $component ] ?? null;
		return $key && isset( $parsed[ $key ] ) ? $parsed[ $key ] : null;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) {
		$args = func_get_args();
		array_shift( $args );
		if ( ! isset( $GLOBALS['__did_actions'] ) ) {
			$GLOBALS['__did_actions'] = array();
		}
		$GLOBALS['__did_actions'][ $tag ][] = $args;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		return $value;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb = null, $priority = 10, $accepted_args = 1 ) {}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $ts, $recurrence, $hook ) {}
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $ts, $hook ) {}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook ) {}
}
if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		return $single ? '' : array();
	}
}
if ( ! function_exists( 'update_user_meta' ) ) {
	function update_user_meta( $user_id, $key, $value ) {
		return true;
	}
}
if ( ! function_exists( 'delete_user_meta' ) ) {
	function delete_user_meta( $user_id, $key ) {
		return true;
	}
}

// Plugin stubs reused by Application_Passwords tests.
if ( ! class_exists( 'AI_Site_Connector_Plugin' ) ) {
	class AI_Site_Connector_Plugin {
		public static function app_passwords_available() { return true; }
	}
}
if ( ! class_exists( 'AI_Site_Connector_Audit_Log' ) ) {
	class AI_Site_Connector_Audit_Log {
		public static function record( $action, $args = array() ) {}
		public static function hash_ip( $ip ) { return ''; }
	}
}
if ( ! class_exists( 'WP_Application_Passwords' ) ) {
	class WP_Application_Passwords {
		public static $deleted_with = null;
		public static $items = array();
		public static function delete_application_password( $user_id, $uuid ) {
			self::$deleted_with = array( $user_id, $uuid );
			return true;
		}
		public static function get_user_application_passwords( $user_id ) {
			return self::$items;
		}
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code, $message;
		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $v ) { return $v instanceof WP_Error; }
}

// Reset between tests via the testCase tearDown wherever needed.
$GLOBALS['__did_actions'] = array();
