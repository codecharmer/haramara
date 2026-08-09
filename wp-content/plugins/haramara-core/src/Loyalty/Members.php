<?php
/**
 * Lealtad Haramara — members, stamps, and the QR card.
 *
 * Launch model: a member is an anonymous, device-registered identity (no
 * account required — "sin apps que instalar, sin fricción" is the web claim;
 * "sin cuentas que estorben" is the app's). The customer app registers once
 * and renders a QR of the signed card token; staff scan it at the bar and the
 * POS calls the stamp/redeem endpoints. Web (Woo account) loyalty and a
 * merge path are a documented follow-up — reward MECHANICS are an undecided
 * product fact (PRODUCT.md), so the API reports counts and never promises a
 * reward tier.
 *
 * Storage: a private CPT (`haramara_member`) with stamp/redeem counters in
 * meta — no custom tables, consistent with the engine's storage discipline.
 * The card token is `member_key.hmac` (wp_salt-keyed), so the POS endpoint
 * can reject forged QRs without a lookup.
 *
 * Routes (namespace haramara/v1):
 *   POST /app/loyalty/register  {device}  → { memberKey, token, stamps, redeemed }
 *   GET  /app/loyalty/card      ?token=   → { stamps, redeemed }
 *   POST /pos/loyalty/stamp     {token}   → { stamps, redeemed }   (staff cap)
 *   POST /pos/loyalty/redeem    {token}   → { stamps, redeemed }   (staff cap)
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Loyalty;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Setup\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Members implements Bootable {

	private const NS = 'haramara/v1';

	/** Private storage post type. */
	public const POST_TYPE = 'haramara_member';

	/** Meta keys. */
	public const META_KEY      = '_haramara_member_key';
	public const META_DEVICE   = '_haramara_device_hash';
	public const META_STAMPS   = '_haramara_stamps';
	public const META_REDEEMED = '_haramara_redeemed';

	public function boot(): void {
		add_action( 'init', array( $this, 'register_storage' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Hidden CPT that holds one post per member.
	 */
	public function register_storage(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'supports'            => array( 'title' ),
				'labels'              => array( 'name' => __( 'Miembros de lealtad', 'haramara-core' ) ),
			)
		);
	}

	/**
	 * REST surface: app (public) + POS (staff capability).
	 */
	public function register_routes(): void {
		$token_arg = array(
			'required'          => true,
			'type'              => 'string',
			'description'       => __( 'Token firmado de la tarjeta de lealtad.', 'haramara-core' ),
			'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
		);

		register_rest_route(
			self::NS,
			'/app/loyalty/register',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'register_member' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'device' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Identificador estable del dispositivo.', 'haramara-core' ),
						'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/app/loyalty/card',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_card' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'token' => $token_arg ),
			)
		);

		foreach ( array( 'stamp', 'redeem' ) as $action ) {
			register_rest_route(
				self::NS,
				'/pos/loyalty/' . $action,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => 'stamp' === $action ? array( $this, 'stamp' ) : array( $this, 'redeem' ),
					'permission_callback' => array( $this, 'pos_permission' ),
					'args'                => array( 'token' => $token_arg ),
				)
			);
		}
	}

	/**
	 * Staff-only gate, same capability the POS order board uses.
	 *
	 * @return true|\WP_Error
	 */
	public function pos_permission() {
		if ( current_user_can( Activator::CAP ) ) {
			return true;
		}
		return new \WP_Error(
			'haramara_pos_forbidden',
			__( 'Necesitas iniciar sesión con una cuenta del equipo.', 'haramara-core' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Handlers                                                               */
	/* ---------------------------------------------------------------------- */

	/**
	 * Register (or re-fetch) the member for a device. Idempotent.
	 */
	public function register_member( \WP_REST_Request $request ): \WP_REST_Response {
		$device_hash = hash_hmac( 'sha256', (string) $request['device'], wp_salt( 'auth' ) );

		$member_id = $this->find_member_by_meta( self::META_DEVICE, $device_hash );

		if ( 0 === $member_id ) {
			$member_key = strtolower( wp_generate_password( 20, false, false ) );
			$member_id  = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => 'member-' . $member_key,
					'meta_input'  => array(
						self::META_KEY      => $member_key,
						self::META_DEVICE   => $device_hash,
						self::META_STAMPS   => 0,
						self::META_REDEEMED => 0,
					),
				),
				true
			);
			if ( is_wp_error( $member_id ) ) {
				return new \WP_REST_Response( array( 'message' => __( 'No se pudo crear la tarjeta.', 'haramara-core' ) ), 500 );
			}
		}

		$member_key = (string) get_post_meta( $member_id, self::META_KEY, true );

		return new \WP_REST_Response( $this->card_payload( $member_id, $member_key ), 200 );
	}

	/**
	 * Current card state for a signed token.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_card( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$member_id = $this->resolve_token( (string) $request['token'] );
		if ( is_wp_error( $member_id ) ) {
			return $member_id;
		}
		$member_key = (string) get_post_meta( $member_id, self::META_KEY, true );
		return new \WP_REST_Response( $this->card_payload( $member_id, $member_key ), 200 );
	}

	/**
	 * Add one stamp (staff).
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function stamp( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->bump( $request, self::META_STAMPS );
	}

	/**
	 * Record a redemption (staff). Mechanics are undecided product policy, so
	 * this only counts — it never converts stamps automatically.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function redeem( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return $this->bump( $request, self::META_REDEEMED );
	}

	/**
	 * Card lookup for sibling services (Wallet pass). Same validation and
	 * error semantics as the card route, without the REST envelope.
	 *
	 * @param string $token Signed card token (`key.hmac`).
	 * @return array<string,int|string>|\WP_Error
	 */
	public function card_for_token( string $token ): array|\WP_Error {
		$member_id = $this->resolve_token( $token );
		if ( is_wp_error( $member_id ) ) {
			return $member_id;
		}
		$member_key = (string) get_post_meta( $member_id, self::META_KEY, true );

		return $this->card_payload( $member_id, $member_key );
	}

	/* ---------------------------------------------------------------------- */
	/* Internals                                                              */
	/* ---------------------------------------------------------------------- */

	/**
	 * @param \WP_REST_Request $request Incoming staff request.
	 * @param string           $meta    Counter meta key to increment.
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function bump( \WP_REST_Request $request, string $meta ) {
		$member_id = $this->resolve_token( (string) $request['token'] );
		if ( is_wp_error( $member_id ) ) {
			return $member_id;
		}

		$count = (int) get_post_meta( $member_id, $meta, true );
		update_post_meta( $member_id, $meta, $count + 1 );

		$member_key = (string) get_post_meta( $member_id, self::META_KEY, true );
		return new \WP_REST_Response( $this->card_payload( $member_id, $member_key ), 200 );
	}

	/**
	 * Validate `key.hmac` and return the member post ID.
	 *
	 * @return int|\WP_Error
	 */
	private function resolve_token( string $token ) {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! hash_equals( $this->sign( $parts[0] ), $parts[1] ) ) {
			return new \WP_Error( 'haramara_loyalty_bad_token', __( 'Tarjeta no válida.', 'haramara-core' ), array( 'status' => 403 ) );
		}

		$member_id = $this->find_member_by_meta( self::META_KEY, $parts[0] );
		if ( 0 === $member_id ) {
			return new \WP_Error( 'haramara_loyalty_unknown', __( 'Tarjeta no encontrada.', 'haramara-core' ), array( 'status' => 404 ) );
		}
		return $member_id;
	}

	private function sign( string $member_key ): string {
		return hash_hmac( 'sha256', $member_key, wp_salt( 'auth' ) );
	}

	/** @return array<string,int|string> */
	private function card_payload( int $member_id, string $member_key ): array {
		return array(
			'memberKey' => $member_key,
			'token'     => $member_key . '.' . $this->sign( $member_key ),
			'stamps'    => (int) get_post_meta( $member_id, self::META_STAMPS, true ),
			'redeemed'  => (int) get_post_meta( $member_id, self::META_REDEEMED, true ),
		);
	}

	private function find_member_by_meta( string $meta_key, string $value ): int {
		$found = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $value,    // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return isset( $found[0] ) ? (int) $found[0] : 0;
	}
}
