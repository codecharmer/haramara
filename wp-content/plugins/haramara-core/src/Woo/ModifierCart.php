<?php
/**
 * Source-agnostic cart lifecycle for product modifiers.
 *
 * One class owns everything that happens to a modifier selection AFTER it is
 * chosen, regardless of where it was chosen:
 *
 * - classic product form  → $_POST['haramara_mod'] (rendered by
 *   Woo\ModifierFrontend, which is now rendering-ONLY)
 * - customer app / blocks → Store API `POST /cart/add-item` with
 *   `extensions.haramara.modifiers` in the JSON body
 *
 * The Store API path is the reason this class exists apart from the frontend:
 * WooCommerce's cart hooks (`woocommerce_add_to_cart_validation`,
 * `woocommerce_add_cart_item_data`, …) fire on BOTH paths but never see the
 * REST request, and a JSON body never populates $_POST — so before this
 * split, any product with a REQUIRED group was un-orderable from the app
 * (400 at submit). The one seam that does see the request,
 * `woocommerce_store_api_add_to_cart_data`, parses the extensions payload
 * into a REQUEST-SCOPED STASH; every downstream hook then reads the stash
 * first and falls back to the classic $_POST parse. One capture path, two
 * sources, zero double-registered hooks (double reprice = double charge).
 *
 * The extensions payload is an UNDECLARED request param — WordPress performs
 * no validation on it — so Catalog\ModifierApplication::validate() remains
 * the sole authority, exactly as on every other surface.
 *
 * Also registers the product `extensions` data on `wc/store/v1/products`, so
 * the customer app learns each product's groups on the products query it
 * already makes — zero extra round-trips.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Woo;

use Haramara\Core\Catalog\ModifierApplication;
use Haramara\Core\Catalog\ModifierResolver;
use Haramara\Core\Contracts\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capture, validate, reprice, display, and stamp modifier selections.
 */
final class ModifierCart implements Bootable {

	/** Cart-item key carrying the validated selections structure. */
	public const CART_ITEM_KEY = 'haramara_modifiers';

	/** Classic request field: haramara_mod[<group_id>][] = option key. */
	public const FIELD = 'haramara_mod';

	/** Store API extensions namespace: extensions.haramara.modifiers. */
	public const EXT_NAMESPACE = 'haramara';

	/**
	 * Request-scoped selections captured from the Store API body. Null means
	 * "no Store API capture ran" and the classic $_POST parse applies.
	 *
	 * @var array<int,array{group_id:int,option_keys:array<int,string>}>|null
	 */
	private static ?array $request_selections = null;

	/**
	 * Hook everything. Order matters only for reprice (priority 20, after
	 * other price filters).
	 */
	public function boot(): void {
		// Store API: the only hook that sees the REST request.
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( $this, 'capture_store_api_selections' ), 10, 2 );

		// Source-agnostic lifecycle (classic form AND Store API fire these).
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_selections' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'stash_selections' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'reprice_cart_items' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'write_order_item_meta' ), 10, 3 );

		// Groups on the public products feed (wc/store/v1/products extensions).
		add_action( 'init', array( $this, 'register_product_extension' ) );
	}

	/* ---------------------------------------------------------------------- */
	/* Store API capture                                                      */
	/* ---------------------------------------------------------------------- */

	/**
	 * Parse `extensions.haramara.modifiers` from a Store API add-item request
	 * into the request stash. The payload shape is the shared wire shape:
	 * [ { group_id, option_keys: [...] } ].
	 *
	 * Nothing is validated here beyond coercion — validate_selections() runs
	 * right after (CartController fires woocommerce_add_to_cart_validation on
	 * this path) and converts any WP_Error notice into the route's 400.
	 *
	 * @param array<string,mixed> $add_to_cart_data id/quantity/variation/cart_item_data.
	 * @param \WP_REST_Request    $request          The raw add-item request.
	 * @return array<string,mixed> Unchanged — the stash carries the data.
	 */
	public function capture_store_api_selections( $add_to_cart_data, $request ) {
		self::$request_selections = null;

		if ( ! $request instanceof \WP_REST_Request ) {
			return $add_to_cart_data;
		}

		$extensions = $request->get_param( 'extensions' );
		if ( ! is_array( $extensions ) ) {
			// No extensions at all: an app build predating modifiers. The
			// stash stays empty-but-set so the classic $_POST fallback (which
			// cannot apply to a JSON request) is never consulted.
			self::$request_selections = array();
			return $add_to_cart_data;
		}

		$raw = $extensions[ self::EXT_NAMESPACE ]['modifiers'] ?? array();

		$selections = array();
		foreach ( (array) $raw as $entry ) {
			$entry       = (array) $entry;
			$option_keys = array();
			foreach ( (array) ( $entry['option_keys'] ?? array() ) as $key ) {
				$key = sanitize_key( (string) $key );
				if ( '' !== $key ) {
					$option_keys[] = $key;
				}
			}
			$selections[] = array(
				'group_id'    => absint( $entry['group_id'] ?? 0 ),
				'option_keys' => $option_keys,
			);
		}

		self::$request_selections = $selections;

		return $add_to_cart_data;
	}

	/* ---------------------------------------------------------------------- */
	/* Source-agnostic lifecycle                                              */
	/* ---------------------------------------------------------------------- */

	/**
	 * Validate the request's selections before the product may enter the cart.
	 *
	 * A product without groups passes untouched; validate() also enforces
	 * REQUIRED groups when nothing was sent at all. On the Store API path the
	 * error notice becomes the route's HTTP 400 via NoticeHandler.
	 *
	 * @param bool $passed     Prior validation verdict.
	 * @param int  $product_id Product being added.
	 */
	public function validate_selections( bool $passed, int $product_id ): bool {
		if ( ! $passed ) {
			return $passed;
		}

		if ( array() === ModifierResolver::for_product( $product_id ) ) {
			return $passed;
		}

		$validated = ModifierApplication::validate( $product_id, self::selections_from_request() );
		if ( is_wp_error( $validated ) ) {
			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $validated->get_error_message(), 'error' );
			}
			return false;
		}

		return true;
	}

	/**
	 * Stash the validated structure on the cart item.
	 *
	 * Distinct cart-item data feeds WC_Cart::generate_cart_id(), so different
	 * selections become different cart lines automatically — on both paths
	 * (the Store API's generate_cart_id also runs after its data filter).
	 *
	 * @param array<string,mixed> $cart_item_data Existing custom data.
	 * @param int                 $product_id     Product being added.
	 * @return array<string,mixed>
	 */
	public function stash_selections( array $cart_item_data, int $product_id ): array {
		$validated = ModifierApplication::validate( $product_id, self::selections_from_request() );
		if ( is_wp_error( $validated ) || array() === $validated ) {
			return $cart_item_data;
		}

		$cart_item_data[ self::CART_ITEM_KEY ] = $validated;

		return $cart_item_data;
	}

	/**
	 * Show the selections on the cart line ("Leche: Avena (+$15.00)").
	 *
	 * Feeds the classic cart template AND the Store API's item_data — the
	 * block cart, block checkout, and the customer app all render from the
	 * latter.
	 *
	 * @param array<int,array<string,string>> $item_data Display rows.
	 * @param array<string,mixed>             $cart_item Cart item.
	 * @return array<int,array<string,string>>
	 */
	public function display_cart_item_data( array $item_data, array $cart_item ): array {
		$validated = isset( $cart_item[ self::CART_ITEM_KEY ] ) ? (array) $cart_item[ self::CART_ITEM_KEY ] : array();

		foreach ( $validated as $selection ) {
			$selection = (array) $selection;
			$labels    = array();
			foreach ( (array) ( $selection['options'] ?? array() ) as $option ) {
				$labels[] = self::option_label( (array) $option );
			}
			if ( array() === $labels ) {
				continue;
			}
			$item_data[] = array(
				'key'   => (string) ( $selection['group_name'] ?? '' ),
				'value' => implode( ', ', $labels ),
			);
		}

		return $item_data;
	}

	/**
	 * Reprice carrying cart lines: fresh catalog price + per-unit delta.
	 *
	 * `woocommerce_before_calculate_totals` may run more than once per
	 * request; rebuilding from a freshly loaded product (never the possibly
	 * already-adjusted cart instance) keeps the adjustment idempotent.
	 *
	 * @param \WC_Cart|mixed $cart The cart being totalled.
	 */
	public function reprice_cart_items( $cart ): void {
		if ( ! $cart instanceof \WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item[ self::CART_ITEM_KEY ] ) || ! isset( $item['data'] ) || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}

			$delta = ModifierApplication::price_delta( (array) $item[ self::CART_ITEM_KEY ] );
			if ( 0.0 === $delta ) {
				continue;
			}

			$fresh = wc_get_product( $item['data']->get_id() );
			$base  = $fresh instanceof \WC_Product ? (float) $fresh->get_price() : (float) $item['data']->get_price();

			$item['data']->set_price( (string) max( 0.0, $base + $delta ) );
		}
	}

	/**
	 * Stamp the order line meta at checkout.
	 *
	 * apply() writes the visible + hidden meta rows only. The line
	 * subtotal/total must NOT be adjusted here: on this path the cart price
	 * already carries the delta (the no-double-count rule). The Store API
	 * checkout reuses WC_Checkout::create_order_line_items, so this covers
	 * the app path with no extra work.
	 *
	 * @param \WC_Order_Item_Product|mixed $item          Order line being built.
	 * @param string                       $cart_item_key Cart item key.
	 * @param array<string,mixed>          $values        Cart item values.
	 */
	public function write_order_item_meta( $item, string $cart_item_key, array $values ): void {
		if ( ! $item instanceof \WC_Order_Item_Product || empty( $values[ self::CART_ITEM_KEY ] ) ) {
			return;
		}

		ModifierApplication::apply( $item, (array) $values[ self::CART_ITEM_KEY ] );
	}

	/* ---------------------------------------------------------------------- */
	/* Products feed extension                                                */
	/* ---------------------------------------------------------------------- */

	/**
	 * Put each product's resolved groups on wc/store/v1/products responses as
	 * `extensions.haramara.modifier_groups`, so the app's existing products
	 * query carries everything the picker needs.
	 */
	public function register_product_extension(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'product',
				'namespace'       => self::EXT_NAMESPACE,
				'data_callback'   => static function ( $product ): array {
					return array(
						'modifier_groups' => $product instanceof \WC_Product
							? ModifierResolver::for_product( $product->get_id() )
							: array(),
					);
				},
				'schema_callback' => static function (): array {
					return array(
						'modifier_groups' => array(
							'description' => __( 'Grupos de modificadores del producto.', 'haramara-core' ),
							'type'        => 'array',
							'readonly'    => true,
						),
					);
				},
				// The literal is ARRAY_A's value; the runtime constant is not
				// visible to static analysis in this codebase (see Idempotency).
				'schema_type'     => 'ARRAY_A',
			)
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Helpers                                                                */
	/* ---------------------------------------------------------------------- */

	/**
	 * The request's selections, source-agnostic.
	 *
	 * Store API stash when a capture ran this request (even an empty one —
	 * a JSON request must never fall back to $_POST); otherwise the classic
	 * form parse.
	 *
	 * @return array<int,array{group_id:int,option_keys:array<int,string>}>
	 */
	public static function selections_from_request(): array {
		if ( null !== self::$request_selections ) {
			return self::$request_selections;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- WooCommerce's add-to-cart form posts without a nonce; every id and key is sanitized below and re-validated against the stored groups.
		$raw = isset( $_POST[ self::FIELD ] ) && is_array( $_POST[ self::FIELD ] ) ? wp_unslash( (array) $_POST[ self::FIELD ] ) : array();

		$selections = array();
		foreach ( $raw as $group_id => $keys ) {
			$option_keys = array();
			foreach ( (array) $keys as $key ) {
				$key = sanitize_key( (string) $key );
				if ( '' !== $key ) {
					$option_keys[] = $key;
				}
			}
			$selections[] = array(
				'group_id'    => absint( $group_id ),
				'option_keys' => $option_keys,
			);
		}

		return $selections;
	}

	/**
	 * Option display label with the price hint when the delta is nonzero
	 * (mirrors ModifierApplication::apply()'s visible meta format).
	 *
	 * @param array<string,mixed> $option Serialized option.
	 */
	public static function option_label( array $option ): string {
		$name = (string) ( $option['name'] ?? '' );
		$hint = self::delta_hint( (float) ( $option['price_delta'] ?? 0 ) );

		return '' !== $hint ? sprintf( '%s %s', $name, $hint ) : $name;
	}

	/**
	 * "(+$15.00)" / "(-$10.00)" — empty when the delta is zero.
	 *
	 * @param float $delta Per-unit price delta.
	 */
	public static function delta_hint( float $delta ): string {
		if ( 0.0 === $delta ) {
			return '';
		}

		return sprintf( '(%s$%s)', $delta >= 0 ? '+' : '-', number_format( abs( $delta ), 2 ) );
	}
}
