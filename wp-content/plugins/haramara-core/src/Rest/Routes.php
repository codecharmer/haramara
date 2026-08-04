<?php
/**
 * Public read-only REST endpoints for pickup availability.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Rest;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Ordering\PickupScheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Namespace `haramara/v1`. These routes expose only non-secret scheduling data
 * (valid dates + open slots) so the storefront can populate pickup selectors.
 * Read-only, no writes, no secrets — public read is acceptable.
 */
final class Routes implements Bootable {

	private const NS = 'haramara/v1';

	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/availability',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_availability' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'date' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Fecha de recolección en formato YYYY-MM-DD.', 'haramara-core' ),
						'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
						'validate_callback' => static fn( $value ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pickup-dates',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_pickup_dates' ),
				'permission_callback' => '__return_true',
				'args'                => array(),
			)
		);
	}

	/**
	 * GET /haramara/v1/availability?date=YYYY-MM-DD
	 *
	 * @param \WP_REST_Request $request
	 */
	public function get_availability( \WP_REST_Request $request ): \WP_REST_Response {
		$date = sanitize_text_field( (string) $request->get_param( 'date' ) );

		$response = rest_ensure_response(
			array(
				'date'  => $date,
				'slots' => PickupScheduler::available_slots( $date ),
			)
		);

		// Read-only and cache-friendly to blunt repeated polling.
		$response->header( 'Cache-Control', 'public, max-age=60' );

		return $response;
	}

	/**
	 * GET /haramara/v1/pickup-dates
	 */
	public function get_pickup_dates(): \WP_REST_Response {
		$response = rest_ensure_response(
			array(
				'dates' => PickupScheduler::valid_dates(),
			)
		);

		$response->header( 'Cache-Control', 'public, max-age=60' );

		return $response;
	}
}
