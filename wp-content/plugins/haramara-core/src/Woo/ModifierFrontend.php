<?php
/**
 * Storefront rendering + capture of product modifier groups.
 *
 * The web half of the Phase 4 modifier system (seam 7 of
 * docs/phase4-integration.md). The FSE product page renders through core Woo
 * blocks, so groups are drawn and captured with classic hooks:
 *
 * - `woocommerce_before_add_to_cart_button` renders each resolved group as a
 *   fieldset (radios when max === 1, checkboxes otherwise) named
 *   `haramara_mod[<group_id>][]` with option keys as values.
 * - `woocommerce_add_to_cart_validation` re-validates the posted selections
 *   through Catalog\ModifierApplication::validate() (the server is the only
 *   authority; clients only send keys).
 * - `woocommerce_add_cart_item_data` stashes the VALIDATED structure on the
 *   cart item; distinct selections produce distinct cart-item keys, so two
 *   lattes with different milks never merge into one line.
 * - `woocommerce_before_calculate_totals` reprices each carrying line as
 *   fresh catalog price + per-unit delta (idempotent however many times the
 *   hook runs in a request).
 * - `woocommerce_checkout_create_order_line_item` stamps the order line meta
 *   via ModifierApplication::apply(). The cart price already carries the
 *   delta on this path, and apply() writes meta only — line subtotals are
 *   deliberately NOT touched here (the no-double-count rule).
 * - `woocommerce_get_item_data` mirrors the selections into the cart/mini
 *   cart display ("Leche: Avena (+$15.00)").
 *
 * Every ES label rendered here that is not admin data (e.g. "Obligatorio")
 * has its EN pair in data/translations.php; group/option names are catalog
 * content and ride the same dictionary once added there.
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
 * Product-page modifier UI + cart plumbing (render, validate, stash, reprice,
 * order meta).
 */
final class ModifierFrontend implements Bootable {

	/** Cart-item key carrying the validated selections structure. */
	public const CART_ITEM_KEY = 'haramara_modifiers';

	/** Request field: haramara_mod[<group_id>][] = option key. */
	public const FIELD = 'haramara_mod';

	public function boot(): void {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_groups' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_selections' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'stash_selections' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'reprice_cart_items' ), 20 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'write_order_item_meta' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------- */
	/* Product page                                                           */
	/* ---------------------------------------------------------------------- */

	/**
	 * Render the resolved groups above the add-to-cart button.
	 *
	 * Radios for single-select groups (max === 1), checkboxes otherwise. Each
	 * group/option name sits alone in its own element so the /en/ dictionary's
	 * `>Nombre<` keys can match the rendered HTML exactly.
	 */
	public function render_groups(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$groups = ModifierResolver::for_product( $product->get_id() );
		if ( array() === $groups ) {
			return;
		}

		echo '<div class="hm-modifiers">';

		foreach ( $groups as $group ) {
			$type = 1 === $group['max'] ? 'radio' : 'checkbox';

			echo '<fieldset class="hm-modifiers__group">';
			echo '<legend class="hm-modifiers__legend"><span class="hm-modifiers__title">' . esc_html( $group['name'] ) . '</span>';
			if ( $group['required'] ) {
				echo ' <span class="hm-modifiers__req">' . esc_html__( 'Obligatorio', 'haramara-core' ) . '</span>';
			}
			echo '</legend>';

			foreach ( $group['options'] as $option ) {
				echo '<label class="hm-modifiers__option">';
				printf(
					'<input type="%1$s" name="%2$s[%3$d][]" value="%4$s"%5$s>',
					esc_attr( $type ),
					esc_attr( self::FIELD ),
					absint( $group['id'] ),
					esc_attr( $option['key'] ),
					$group['required'] && 'radio' === $type ? ' required' : ''
				);
				echo ' <span class="hm-modifiers__name">' . esc_html( $option['name'] ) . '</span>';

				$hint = self::delta_hint( (float) $option['price_delta'] );
				if ( '' !== $hint ) {
					echo ' <span class="hm-modifiers__delta">' . esc_html( $hint ) . '</span>';
				}
				echo '</label>';
			}

			echo '</fieldset>';
		}

		echo '</div>';
	}

	/* ---------------------------------------------------------------------- */
	/* Cart capture                                                           */
	/* ---------------------------------------------------------------------- */

	/**
	 * Validate posted selections before the product may enter the cart.
	 *
	 * Runs for the classic form and the Store API alike. A product without
	 * groups passes untouched; validate() also enforces REQUIRED groups when
	 * nothing was posted at all.
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
	 * selections become different cart lines automatically. The structure is
	 * server-priced by validate() — client-sent prices never exist.
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
	 * Feeds the classic cart template and the Store API's item_data (block
	 * cart/checkout and the customer app render from the latter).
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
	 * already carries the delta (docs/phase4-integration.md, seam 7).
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
	/* Helpers                                                                */
	/* ---------------------------------------------------------------------- */

	/**
	 * Posted selections in the validate() wire shape.
	 *
	 * @return array<int,array{group_id:int,option_keys:array<int,string>}>
	 */
	private static function selections_from_request(): array {
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
	private static function option_label( array $option ): string {
		$name = (string) ( $option['name'] ?? '' );
		$hint = self::delta_hint( (float) ( $option['price_delta'] ?? 0 ) );

		return '' !== $hint ? sprintf( '%s %s', $name, $hint ) : $name;
	}

	/**
	 * "(+$15.00)" / "(-$10.00)" — empty when the delta is zero.
	 *
	 * @param float $delta Per-unit price delta.
	 */
	private static function delta_hint( float $delta ): string {
		if ( 0.0 === $delta ) {
			return '';
		}

		return sprintf( '(%s$%s)', $delta >= 0 ? '+' : '-', number_format( abs( $delta ), 2 ) );
	}
}
