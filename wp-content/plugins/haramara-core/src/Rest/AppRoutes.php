<?php
/**
 * Customer-app REST endpoints.
 *
 * Public surface for the Expo mobile app under `haramara/v1/app`. Catalog and
 * cart/checkout ride the WooCommerce Store API; these routes cover what the
 * Store API can't: app bootstrap config, guest order-status reads, and push
 * token registration. Order reads are authorized inline by the WooCommerce
 * order key (the same model core uses for guest order confirmation), never by
 * a session.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Rest;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Ordering\PickupScheduler;
use Haramara\Core\Setup\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public REST endpoints for the mobile app.
 */
final class AppRoutes implements Bootable {

	private const NS = 'haramara/v1';

	/** Expo push token for order status notifications (order meta). */
	public const META_PUSH_TOKEN = '_haramara_expo_push_token';

	/**
	 * Register the REST routes.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Route definitions for the customer-app surface.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/app/config',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_config' ),
				'permission_callback' => '__return_true',
			)
		);

		$key_arg = array(
			'required'          => true,
			'type'              => 'string',
			'description'       => __( 'Clave del pedido (order key).', 'haramara-core' ),
			'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
		);

		register_rest_route(
			self::NS,
			'/app/orders/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_order' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'key' => $key_arg ),
			)
		);

		register_rest_route(
			self::NS,
			'/app/orders/(?P<id>\d+)/push-token',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_push_token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'key'   => $key_arg,
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Token de notificaciones de Expo.', 'haramara-core' ),
						'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
						'validate_callback' => static fn( $value ): bool => (bool) preg_match( '/^Expo(nent)?PushToken\[[\w-]+\]$/', (string) $value ),
					),
				),
			)
		);
	}

	/**
	 * GET /haramara/v1/app/config — app bootstrap: business facts, pickup
	 * instructions, and which payment methods checkout currently offers (lets
	 * gateways be flipped server-side without an app release).
	 */
	public function get_config(): \WP_REST_Response {
		$business = Options::business();
		$pickup   = Options::pickup();

		$response = rest_ensure_response(
			array(
				'business'        => array(
					'name'          => (string) ( $business['name'] ?? '' ),
					'phone'         => (string) ( $business['phone'] ?? '' ),
					'whatsapp'      => (string) ( $business['whatsapp'] ?? '' ),
					'address'       => (string) ( $business['address'] ?? '' ),
					'hours_summary' => (string) ( $business['hours_summary'] ?? '' ),
					'hours_closed'  => (string) ( $business['hours_closed'] ?? '' ),
					'instagram'     => (string) ( $business['instagram'] ?? '' ),
					'maps_url'      => (string) ( $business['maps_url'] ?? '' ),
					'latitude'      => (string) ( $business['latitude'] ?? '' ),
					'longitude'     => (string) ( $business['longitude'] ?? '' ),
				),
				'pickup'          => array(
					'instructions'    => (string) ( $pickup['instructions'] ?? '' ),
					'lead_time_hours' => (int) ( $pickup['lead_time_hours'] ?? 24 ),
					'open_days'       => array_map( 'intval', (array) ( $pickup['open_days'] ?? array() ) ),
				),
				'payments'        => $this->payment_flags(),
				/**
				 * Filter the minimum app version the API still supports. Bumping
				 * it lets the app force an upgrade prompt without a server error.
				 *
				 * @param string $version Semver string.
				 */
				'min_app_version' => (string) apply_filters( 'haramara_app_min_version', '1.0.0' ),
			)
		);

		$response->header( 'Cache-Control', 'public, max-age=300' );

		return $response;
	}

	/**
	 * GET /haramara/v1/app/orders/{id}?key= — customer-facing order status.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_order( \WP_REST_Request $request ) {
		$order = $this->authorized_order( $request );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
				'total'    => (float) $order->get_line_total( $item, true ),
			);
		}

		$pickup   = Options::pickup();
		$response = rest_ensure_response(
			array(
				'id'                   => $order->get_id(),
				'number'               => (string) $order->get_order_number(),
				'status'               => $order->get_status(),
				'status_label'         => function_exists( 'wc_get_order_status_name' )
					? (string) wc_get_order_status_name( $order->get_status() )
					: $order->get_status(),
				'items'                => $items,
				'total'                => (float) $order->get_total(),
				'currency'             => $order->get_currency(),
				'payment_method'       => $order->get_payment_method(),
				'payment_method_title' => $order->get_payment_method_title(),
				'pickup'               => array(
					'date'         => (string) $order->get_meta( PickupScheduler::META_DATE ),
					'slot'         => (string) $order->get_meta( PickupScheduler::META_SLOT ),
					'label'        => (string) $order->get_meta( PickupScheduler::META_LABEL ),
					'instructions' => (string) ( $pickup['instructions'] ?? '' ),
				),
			)
		);

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * POST /haramara/v1/app/orders/{id}/push-token — attach the device's Expo
	 * push token to the order so status changes can notify it.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_push_token( \WP_REST_Request $request ) {
		$order = $this->authorized_order( $request );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$order->update_meta_data( self::META_PUSH_TOKEN, (string) $request->get_param( 'token' ) );
		$order->save();

		return rest_ensure_response( array( 'registered' => true ) );
	}

	/**
	 * Resolve the order and verify the caller holds its order key. A missing
	 * order and a bad key return the same generic 404 so order ids can't be
	 * probed.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return \WC_Order|\WP_Error
	 */
	private function authorized_order( \WP_REST_Request $request ) {
		$not_found = new \WP_Error(
			'haramara_order_not_found',
			__( 'Pedido no encontrado.', 'haramara-core' ),
			array( 'status' => 404 )
		);

		if ( ! function_exists( 'wc_get_order' ) ) {
			return $not_found;
		}

		$order = wc_get_order( (int) $request->get_param( 'id' ) );
		if ( ! $order instanceof \WC_Order ) {
			return $not_found;
		}

		$key = (string) $request->get_param( 'key' );
		if ( '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return $not_found;
		}

		return $order;
	}

	/**
	 * Which payment methods are currently enabled at checkout.
	 *
	 * @return array{cod:bool,stripe:bool,mercadopago:bool}
	 */
	private function payment_flags(): array {
		$flags = array(
			'cod'         => false,
			'stripe'      => false,
			'mercadopago' => false,
		);

		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return $flags;
		}

		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( 'yes' !== $gateway->enabled ) {
				continue;
			}
			if ( 'cod' === $gateway->id ) {
				$flags['cod'] = true;
			} elseif ( 'stripe' === $gateway->id ) {
				// Enabled alone is not enough: without API keys the gateway
				// hides itself at checkout, so the app must not offer it.
				$flags['stripe'] = $this->stripe_has_keys();
			} elseif ( str_contains( $gateway->id, 'mercado' ) ) {
				$flags['mercadopago'] = true;
			}
		}

		return $flags;
	}

	/**
	 * Whether the Stripe gateway holds keys for its current mode.
	 */
	private function stripe_has_keys(): bool {
		$settings = get_option( 'woocommerce_stripe_settings', array() );
		if ( ! is_array( $settings ) ) {
			return false;
		}
		$test = 'yes' === ( $settings['testmode'] ?? 'no' );
		$pk   = (string) ( $settings[ $test ? 'test_publishable_key' : 'publishable_key' ] ?? '' );
		$sk   = (string) ( $settings[ $test ? 'test_secret_key' : 'secret_key' ] ?? '' );
		return '' !== trim( $pk ) && '' !== trim( $sk );
	}
}
