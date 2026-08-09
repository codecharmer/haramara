<?php
/**
 * POS REST endpoints (staff tablet).
 *
 * Authenticated staff surface under `haramara/v1/pos`: the incoming-order
 * queue and its slide-to-accept endpoint, the live pickup board, status
 * transitions, walk-in counter sales, the day's totals, a ring-up product feed
 * with exact stock (which the public Store API deliberately hides), inventory
 * writes (recount + internal withdrawals, see Woo\Withdrawals), the shared
 * employee-name list backing the withdrawal person picker, and push-token
 * registration for new-order alerts. Auth = WordPress Application Passwords
 * (Basic over HTTPS) resolving to a user with the `manage_haramara`
 * capability; every response is no-store.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Rest;

use Haramara\Core\Admin\Reports;
use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Ordering\OrderBoard;
use Haramara\Core\Ordering\StatusTransitions;
use Haramara\Core\Ordering\WalkInOrders;
use Haramara\Core\Push\StaffTokens;
use Haramara\Core\Setup\Activator;
use Haramara\Core\Setup\Options;
use Haramara\Core\Woo\Withdrawals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Staff REST endpoints under `haramara/v1/pos`.
 */
final class PosRoutes implements Bootable {

	private const NS = 'haramara/v1';

	/**
	 * Register the REST routes.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Route definitions for the POS surface.
	 */
	public function register_routes(): void {
		$date_arg = array(
			'required'          => false,
			'type'              => 'string',
			'description'       => __( 'Fecha en formato YYYY-MM-DD (por defecto: hoy).', 'haramara-core' ),
			'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
			'validate_callback' => static fn( $value ): bool => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $value ),
		);

		register_rest_route(
			self::NS,
			'/pos/board',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_board' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'date'         => $date_arg,
					'include_done' => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pos/queue',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_queue' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/pos/orders/(?P<id>\d+)/accept',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'accept' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/pos/orders/(?P<id>\d+)/transition',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'transition' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'status' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => StatusTransitions::ALLOWED,
						'sanitize_callback' => static fn( $value ): string => sanitize_key( (string) $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pos/walk-in',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_walk_in' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'items'   => array(
						'required'    => true,
						'type'        => 'array',
						'description' => __( 'Líneas de venta: [{product_id, quantity}].', 'haramara-core' ),
					),
					'payment' => array(
						'required'          => true,
						'type'              => 'string',
						'enum'              => array( 'cash', 'card_external', 'card-external' ),
						'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
					),
					'note'    => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => static fn( $value ): string => sanitize_textarea_field( (string) $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pos/summary',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_summary' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array( 'date' => $date_arg ),
			)
		);

		register_rest_route(
			self::NS,
			'/pos/products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_products' ),
				'permission_callback' => array( $this, 'permission' ),
			)
		);

		register_rest_route(
			self::NS,
			'/pos/products/(?P<id>\d+)/stock',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_stock' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'quantity' => array(
						'required'          => true,
						'type'              => 'integer',
						'description'       => __( 'Cantidad absoluta en existencia (recuento).', 'haramara-core' ),
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value >= 0 && (int) $value <= 999,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pos/withdrawals',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_withdrawal' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'items'       => array(
							'required'    => true,
							'type'        => 'array',
							'description' => __( 'Líneas de salida: [{product_id, quantity}].', 'haramara-core' ),
						),
						'destination' => array(
							'required'          => true,
							'type'              => 'string',
							'enum'              => Withdrawals::DESTINATIONS,
							'sanitize_callback' => static fn( $value ): string => sanitize_key( (string) $value ),
						),
						'person'      => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'description'       => __( 'Quién se lleva el producto (opcional).', 'haramara-core' ),
							'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
						),
						'note'        => array(
							'required'          => false,
							'type'              => 'string',
							'default'           => '',
							'sanitize_callback' => static fn( $value ): string => sanitize_textarea_field( (string) $value ),
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_withdrawals' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array( 'date' => $date_arg ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pos/employees',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_employees' ),
					'permission_callback' => array( $this, 'permission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'add_employee' ),
					'permission_callback' => array( $this, 'permission' ),
					'args'                => array(
						'name' => array(
							'required'          => true,
							'type'              => 'string',
							'description'       => __( 'Nombre del empleado a agregar.', 'haramara-core' ),
							'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
							'validate_callback' => static fn( $value ): bool => '' !== trim( (string) $value ) && mb_strlen( trim( (string) $value ) ) <= 80,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/pos/push-token',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'register_push_token' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Token de notificaciones Expo del dispositivo.', 'haramara-core' ),
						'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
						'validate_callback' => static fn( $value ): bool => (bool) preg_match( '/^Expo(nent)?PushToken\[[\w-]+\]$/', (string) $value ),
					),
				),
			)
		);
	}

	/**
	 * Staff-only: any authenticated user holding the operations capability
	 * (granted to administrator + shop_manager on activation).
	 *
	 * @return bool|\WP_Error
	 */
	public function permission() {
		if ( current_user_can( Activator::CAP ) ) {
			return true;
		}

		return new \WP_Error(
			'haramara_pos_forbidden',
			__( 'Necesitas iniciar sesión con una cuenta del equipo.', 'haramara-core' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * GET /haramara/v1/pos/board?date=&include_done=
	 *
	 * @param \WP_REST_Request $request Current request.
	 */
	public function get_board( \WP_REST_Request $request ): \WP_REST_Response {
		$date = $this->requested_date( $request );

		$response = rest_ensure_response(
			OrderBoard::for_date( $date, (bool) $request->get_param( 'include_done' ) )
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * GET /haramara/v1/pos/queue — online orders awaiting acceptance.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_queue() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $this->wc_unavailable();
		}

		$orders = array_map(
			array( OrderBoard::class, 'serialize_order' ),
			OrderBoard::incoming_orders()
		);

		$response = rest_ensure_response(
			array(
				'orders' => $orders,
				'count'  => count( $orders ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * POST /haramara/v1/pos/orders/{id}/accept — slide-to-accept from the queue.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function accept( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return $this->wc_unavailable();
		}

		$order = wc_get_order( (int) $request->get_param( 'id' ) );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error(
				'haramara_order_not_found',
				__( 'Pedido no encontrado.', 'haramara-core' ),
				array( 'status' => 404 )
			);
		}

		$user   = wp_get_current_user();
		$result = StatusTransitions::accept(
			$order,
			sprintf(
				/* translators: %s: staff display name. */
				__( 'Pedido aceptado desde el POS por %s.', 'haramara-core' ),
				'' !== $user->display_name ? $user->display_name : $user->user_login
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = rest_ensure_response( OrderBoard::serialize_order( $order ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * POST /haramara/v1/pos/push-token — register this device for new-order pushes.
	 *
	 * @param \WP_REST_Request $request Current request.
	 */
	public function register_push_token( \WP_REST_Request $request ): \WP_REST_Response {
		StaffTokens::register(
			(string) $request->get_param( 'token' ),
			get_current_user_id()
		);

		$response = rest_ensure_response( array( 'registered' => true ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * POST /haramara/v1/pos/orders/{id}/transition
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function transition( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return $this->wc_unavailable();
		}

		$order = wc_get_order( (int) $request->get_param( 'id' ) );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error(
				'haramara_order_not_found',
				__( 'Pedido no encontrado.', 'haramara-core' ),
				array( 'status' => 404 )
			);
		}

		$result = StatusTransitions::apply(
			$order,
			(string) $request->get_param( 'status' ),
			__( 'Estado actualizado desde el POS.', 'haramara-core' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$response = rest_ensure_response( OrderBoard::serialize_order( $order ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * POST /haramara/v1/pos/walk-in
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_walk_in( \WP_REST_Request $request ) {
		$raw   = (array) $request->get_param( 'items' );
		$items = array();
		foreach ( $raw as $line ) {
			$line    = (array) $line;
			$items[] = array(
				'product_id' => absint( $line['product_id'] ?? 0 ),
				'quantity'   => absint( $line['quantity'] ?? 0 ),
			);
		}

		$order = WalkInOrders::create(
			$items,
			(string) $request->get_param( 'payment' ),
			(string) $request->get_param( 'note' )
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$response = rest_ensure_response( OrderBoard::serialize_order( $order ) );
		$response->set_status( 201 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * GET /haramara/v1/pos/summary?date= — close-of-day totals.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_summary( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $this->wc_unavailable();
		}

		$date   = $this->requested_date( $request );
		$orders = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => Reports::PAID_STATUSES,
				'date_created' => OrderBoard::day_range( $date ),
			)
		);
		$orders = is_array( $orders ) ? $orders : array();

		$revenue    = 0.0;
		$by_channel = array();
		$by_payment = array();
		$item_qty   = array();
		$count      = 0;

		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$total    = (float) $order->get_total();
			$revenue += $total;
			++$count;

			if ( WalkInOrders::CREATED_VIA === $order->get_created_via() ) {
				$kind    = (string) $order->get_meta( WalkInOrders::META_PAYMENT );
				$channel = 'walkin_' . ( '' !== $kind ? $kind : 'cash' );
			} else {
				$channel = 'pickup_online';
			}
			$this->bucket( $by_channel, $channel, $total );
			$this->bucket( $by_payment, (string) $order->get_payment_method(), $total );

			foreach ( $order->get_items() as $item ) {
				$name              = $item->get_name();
				$item_qty[ $name ] = ( $item_qty[ $name ] ?? 0 ) + (int) $item->get_quantity();
			}
		}

		arsort( $item_qty );
		$top = array();
		foreach ( array_slice( $item_qty, 0, 10, true ) as $name => $qty ) {
			$top[] = array(
				'name'     => (string) $name,
				'quantity' => (int) $qty,
			);
		}

		$response = rest_ensure_response(
			array(
				'date'              => $date,
				'orders_total'      => $count,
				'revenue'           => round( $revenue, 2 ),
				'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'MXN',
				'by_channel'        => $by_channel,
				'by_payment_method' => $by_payment,
				'top_items'         => $top,
				// Salidas internas: valued at price snapshots, never revenue.
				'withdrawals'       => Withdrawals::summary_for_date( $date ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * GET /haramara/v1/pos/products — ring-up grid feed with exact stock.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_products() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return $this->wc_unavailable();
		}

		$products = wc_get_products(
			array(
				'limit'   => 100,
				'status'  => 'publish',
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);
		$products = is_array( $products ) ? $products : array();

		$out = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$out[] = $this->serialize_product( $product );
		}

		$response = rest_ensure_response( array( 'products' => $out ) );
		$response->header( 'Cache-Control', 'private, max-age=30' );

		return $response;
	}

	/**
	 * POST /haramara/v1/pos/products/{id}/stock — set the absolute on-hand
	 * quantity (morning recount). Last write wins: a recount is absolute
	 * truth, and the response returns the authoritative state.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_stock( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_update_product_stock' ) ) {
			return $this->wc_unavailable();
		}

		$product = wc_get_product( (int) $request->get_param( 'id' ) );
		if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
			return new \WP_Error(
				'haramara_unknown_product',
				__( 'Producto no encontrado.', 'haramara-core' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $product->managing_stock() ) {
			return new \WP_Error(
				'haramara_stock_unmanaged',
				sprintf(
					/* translators: %s: product name. */
					__( '"%s" no maneja inventario.', 'haramara-core' ),
					$product->get_name()
				),
				array( 'status' => 400 )
			);
		}

		wc_update_product_stock( $product, (int) $request->get_param( 'quantity' ), 'set' );

		// Re-read so derived fields (in_stock, stock_status) reflect the write.
		$product = wc_get_product( $product->get_id() );

		$response = rest_ensure_response( $this->serialize_product( $product ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * POST /haramara/v1/pos/withdrawals — record a salida interna.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_withdrawal( \WP_REST_Request $request ) {
		$raw   = (array) $request->get_param( 'items' );
		$items = array();
		foreach ( $raw as $line ) {
			$line    = (array) $line;
			$items[] = array(
				'product_id' => absint( $line['product_id'] ?? 0 ),
				'quantity'   => absint( $line['quantity'] ?? 0 ),
			);
		}

		$withdrawal = Withdrawals::create(
			$items,
			(string) $request->get_param( 'destination' ),
			(string) $request->get_param( 'person' ),
			(string) $request->get_param( 'note' ),
			get_current_user_id()
		);
		if ( is_wp_error( $withdrawal ) ) {
			return $withdrawal;
		}

		$response = rest_ensure_response( $withdrawal );
		$response->set_status( 201 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * GET /haramara/v1/pos/withdrawals?date= — salidas internas history.
	 *
	 * @param \WP_REST_Request $request Current request.
	 */
	public function get_withdrawals( \WP_REST_Request $request ): \WP_REST_Response {
		$date = $this->requested_date( $request );

		$response = rest_ensure_response(
			array(
				'date'        => $date,
				'withdrawals' => Withdrawals::for_date( $date ),
				'totals'      => Withdrawals::summary_for_date( $date ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * GET /haramara/v1/pos/employees — names for the withdrawal person picker.
	 */
	public function get_employees(): \WP_REST_Response {
		$response = rest_ensure_response( array( 'employees' => Options::employees() ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * POST /haramara/v1/pos/employees — add a name to the shared list.
	 *
	 * A duplicate (case-insensitive) is a success no-op so two devices adding
	 * the same person never surface an error at the counter.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_employee( \WP_REST_Request $request ) {
		$name  = trim( (string) $request->get_param( 'name' ) );
		$names = Options::add_employee( $name );

		$present = false;
		foreach ( $names as $existing ) {
			if ( 0 === strcasecmp( $existing, $name ) ) {
				$present = true;
				break;
			}
		}
		if ( ! $present ) {
			return new \WP_Error(
				'haramara_employees_full',
				__( 'La lista de empleados está llena.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		$response = rest_ensure_response( array( 'employees' => $names ) );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * The POS product payload, shared by the ring-up feed and set_stock.
	 *
	 * @param \WC_Product $product Product to serialize.
	 * @return array<string,mixed>
	 */
	private function serialize_product( \WC_Product $product ): array {
		$image_id = (int) $product->get_image_id();

		return array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'price'          => (float) $product->get_price(),
			'in_stock'       => $product->is_in_stock(),
			'manage_stock'   => $product->managing_stock(),
			'stock_quantity' => $product->get_stock_quantity(),
			'image'          => $image_id > 0 ? (string) wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '',
			'categories'     => array_map( 'strval', (array) wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) ) ),
		);
	}

	/**
	 * The requested date, defaulting to today in the bakery timezone.
	 *
	 * @param \WP_REST_Request $request Current request.
	 */
	private function requested_date( \WP_REST_Request $request ): string {
		$date = (string) $request->get_param( 'date' );
		if ( '' !== $date ) {
			return $date;
		}
		return ( new \DateTimeImmutable( 'now', Options::timezone() ) )->format( 'Y-m-d' );
	}

	/**
	 * Accumulate one order into a count/revenue bucket.
	 *
	 * @param array<string,array{count:int,revenue:float}> $buckets Buckets, mutated in place.
	 * @param string                                       $key     Bucket key (channel or payment method).
	 * @param float                                        $total   Order total to add.
	 */
	private function bucket( array &$buckets, string $key, float $total ): void {
		if ( '' === $key ) {
			$key = 'other';
		}
		if ( ! isset( $buckets[ $key ] ) ) {
			$buckets[ $key ] = array(
				'count'   => 0,
				'revenue' => 0.0,
			);
		}
		++$buckets[ $key ]['count'];
		$buckets[ $key ]['revenue'] = round( $buckets[ $key ]['revenue'] + $total, 2 );
	}

	/**
	 * Standard error for requests arriving before WooCommerce is loaded.
	 */
	private function wc_unavailable(): \WP_Error {
		return new \WP_Error(
			'haramara_wc_unavailable',
			__( 'WooCommerce no está disponible.', 'haramara-core' ),
			array( 'status' => 500 )
		);
	}
}
