<?php
/**
 * Catalog REST endpoints (staff surface).
 *
 * Phase 4 seam: the POS asks for a product's resolved modifier groups the
 * moment a tile with modifiers is tapped. One route for now —
 * `GET /haramara/v1/pos/modifier-groups?product_id=` — same authentication
 * model as Rest\PosRoutes (Application Passwords resolving to a user with
 * the `manage_haramara` capability), every response no-store because group
 * edits in wp-admin must reach the counter on the next tap.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Rest;

use Haramara\Core\Catalog\ModifierResolver;
use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Setup\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Staff catalog endpoints under `haramara/v1/pos`.
 */
final class CatalogRoutes implements Bootable {

	private const NS = 'haramara/v1';

	/**
	 * Register the REST routes.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Route definitions for the catalog surface.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/pos/modifier-groups',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_modifier_groups' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'product_id' => array(
						'required'          => true,
						'type'              => 'integer',
						'description'       => __( 'ID del producto cuyos modificadores se resuelven.', 'haramara-core' ),
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
				),
			)
		);
	}

	/**
	 * Staff-only: any authenticated user holding the operations capability
	 * (granted to administrator + shop_manager on activation). Mirrors
	 * PosRoutes::permission().
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
	 * GET /haramara/v1/pos/modifier-groups?product_id= — resolved groups for
	 * one product (direct assignments first, then category defaults).
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_modifier_groups( \WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error(
				'haramara_wc_unavailable',
				__( 'WooCommerce no está disponible.', 'haramara-core' ),
				array( 'status' => 500 )
			);
		}

		$product = wc_get_product( (int) $request->get_param( 'product_id' ) );
		if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() ) {
			return new \WP_Error(
				'haramara_unknown_product',
				__( 'Producto no encontrado.', 'haramara-core' ),
				array( 'status' => 404 )
			);
		}

		$response = rest_ensure_response(
			array(
				'product_id' => $product->get_id(),
				'groups'     => ModifierResolver::for_product( $product->get_id() ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
