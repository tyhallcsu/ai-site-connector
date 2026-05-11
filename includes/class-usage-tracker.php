<?php
/**
 * Per-Application-Password usage roll-ups.
 *
 * Hooks rest_request_after_callbacks to increment per-day counters in
 * App_Password_Meta::usage_counters for the in-use App Password UUID.
 * Sampling rate is controlled via the AI_SITE_CONNECTOR_USAGE_SAMPLE_RATE
 * constant (default 1.0). On high-traffic sites, set it to e.g. 0.1 to
 * reduce DB write pressure; the display layer multiplies up by 1/rate
 * and labels the data as sampled.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Usage_Tracker {

	/**
	 * In-memory accumulator flushed on shutdown OR every N increments.
	 *
	 * @var array<string, array{user_id:int, route:string, requests:int, errors:int}>
	 */
	private static $buffer = array();
	private static $flushed = false;
	const FLUSH_EVERY = 50;

	public static function register_hooks() {
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'observe_request' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'flush_buffer' ) );
	}

	/**
	 * Get the sampling rate (0.0–1.0). Default 1.0 = track every request.
	 */
	public static function sample_rate() {
		$rate = defined( 'AI_SITE_CONNECTOR_USAGE_SAMPLE_RATE' )
			? (float) AI_SITE_CONNECTOR_USAGE_SAMPLE_RATE
			: 1.0;
		return max( 0.0, min( 1.0, $rate ) );
	}

	public static function is_sampled() {
		$rate = self::sample_rate();
		if ( $rate >= 1.0 ) {
			return true;
		}
		if ( $rate <= 0.0 ) {
			return false;
		}
		// mt_rand floor-divides nicely; PHP_INT_MAX as denominator gives high resolution.
		return ( mt_rand() / mt_getrandmax() ) < $rate;
	}

	/**
	 * Filter callback. Returns $response untouched after observing.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response
	 * @param array            $handler
	 * @param WP_REST_Request  $request
	 * @return mixed
	 */
	public static function observe_request( $response, $handler, $request ) {
		unset( $handler );
		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return $response;
		}
		if ( ! class_exists( 'AI_Site_Connector_App_Password_Resolver' ) ) {
			return $response;
		}
		$uuid = AI_Site_Connector_App_Password_Resolver::current_uuid();
		if ( ! $uuid ) {
			return $response;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $response;
		}
		if ( ! self::is_sampled() ) {
			return $response;
		}

		$status = self::extract_status( $response );
		$is_err = $status >= 400;

		$key = $user_id . '|' . $uuid . '|' . self::canonical_route( (string) $request->get_route() );
		if ( ! isset( self::$buffer[ $key ] ) ) {
			self::$buffer[ $key ] = array(
				'user_id'  => $user_id,
				'uuid'     => $uuid,
				'route'    => self::canonical_route( (string) $request->get_route() ),
				'requests' => 0,
				'errors'   => 0,
			);
		}
		self::$buffer[ $key ]['requests']++;
		if ( $is_err ) {
			self::$buffer[ $key ]['errors']++;
		}

		if ( count( self::$buffer ) >= self::FLUSH_EVERY ) {
			self::flush_buffer();
		}

		return $response;
	}

	/**
	 * Drain the in-memory buffer to user_meta. Idempotent — safe to call
	 * from shutdown AND from the threshold trigger.
	 */
	public static function flush_buffer() {
		if ( empty( self::$buffer ) || ! class_exists( 'AI_Site_Connector_App_Password_Meta' ) ) {
			self::$buffer = array();
			self::$flushed = true;
			return;
		}
		foreach ( self::$buffer as $row ) {
			// increment_usage() handles the heavy lifting; we call once per
			// (user, uuid, route) but the helper accepts one request at a
			// time so we loop for the request count.
			$is_err_for_one = $row['errors'] > 0;
			for ( $i = 0; $i < $row['requests']; $i++ ) {
				AI_Site_Connector_App_Password_Meta::increment_usage(
					$row['user_id'],
					$row['uuid'],
					$row['route'],
					$is_err_for_one && $i < $row['errors']
				);
			}
		}
		self::$buffer  = array();
		self::$flushed = true;
	}

	/**
	 * Compute a 7-day rollup for a single password from the stored counters.
	 * Returns aggregate totals + top-N routes + sampling-aware multipliers.
	 *
	 * @return array{requests:int, errors:int, by_route:array, days:array, sampled:bool, rate:float}
	 */
	public static function rollup_for( $user_id, $uuid, $days = 7 ) {
		if ( ! class_exists( 'AI_Site_Connector_App_Password_Meta' ) ) {
			return array( 'requests' => 0, 'errors' => 0, 'by_route' => array(), 'days' => array(), 'sampled' => false, 'rate' => 1.0 );
		}
		$extras = AI_Site_Connector_App_Password_Meta::get_extras( $user_id, $uuid );
		$counters = isset( $extras['usage_counters'] ) && is_array( $extras['usage_counters'] ) ? $extras['usage_counters'] : array();
		$cutoff = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

		$rate     = self::sample_rate();
		$multiply = $rate > 0.0 ? ( 1.0 / $rate ) : 1.0;
		$requests = 0;
		$errors   = 0;
		$by_route = array();
		$by_day   = array();
		foreach ( $counters as $day => $row ) {
			if ( $day < $cutoff ) {
				continue;
			}
			$d_req  = isset( $row['requests'] ) ? (int) $row['requests'] : 0;
			$d_err  = isset( $row['errors'] ) ? (int) $row['errors'] : 0;
			$requests += $d_req;
			$errors   += $d_err;
			$by_day[ $day ] = array(
				'requests' => (int) round( $d_req * $multiply ),
				'errors'   => (int) round( $d_err * $multiply ),
			);
			if ( ! empty( $row['by_route'] ) && is_array( $row['by_route'] ) ) {
				foreach ( $row['by_route'] as $route => $hits ) {
					if ( ! isset( $by_route[ $route ] ) ) {
						$by_route[ $route ] = 0;
					}
					$by_route[ $route ] += (int) $hits;
				}
			}
		}
		// Apply sampling multiplier for totals + top routes.
		$requests = (int) round( $requests * $multiply );
		$errors   = (int) round( $errors * $multiply );
		foreach ( $by_route as $r => $hits ) {
			$by_route[ $r ] = (int) round( $hits * $multiply );
		}
		arsort( $by_route );
		return array(
			'requests' => $requests,
			'errors'   => $errors,
			'by_route' => array_slice( $by_route, 0, 5, true ),
			'days'     => $by_day,
			'sampled'  => $rate < 1.0,
			'rate'     => $rate,
		);
	}

	private static function extract_status( $response ) {
		if ( $response instanceof WP_Error ) {
			$data = $response->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				return (int) $data['status'];
			}
			return 500;
		}
		if ( $response instanceof WP_HTTP_Response ) {
			return (int) $response->get_status();
		}
		return 200;
	}

	private static function canonical_route( $route ) {
		if ( '' === $route ) {
			return '';
		}
		return preg_replace( '#/\d+(?=/|$)#', '/{id}', '/' . ltrim( $route, '/' ) );
	}
}
