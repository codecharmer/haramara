<?php
/**
 * Storefront RENDERING of product modifier groups — nothing else.
 *
 * The FSE product page renders through core Woo blocks, so groups are drawn
 * with `woocommerce_before_add_to_cart_button` as fieldsets (radios when
 * max === 1, checkboxes otherwise) named `haramara_mod[<group_id>][]`.
 *
 * Everything that happens AFTER a selection is chosen — capture (classic
 * $_POST and Store API extensions alike), validation, cart stash, reprice,
 * cart display, order-line meta — lives in Woo\ModifierCart, which is
 * deliberately source-agnostic and registered on its own. Keeping this class
 * rendering-only is what lets pacifica (no classic modifier UI) ship the
 * cart lifecycle without this file, and what guarantees no cart hook is ever
 * registered twice (double reprice = double charge).
 *
 * Every ES label rendered here that is not admin data (e.g. "Obligatorio")
 * has its EN pair in data/translations.php; group/option names are catalog
 * content and ride the same dictionary once added there.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Woo;

use Haramara\Core\Catalog\ModifierResolver;
use Haramara\Core\Contracts\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product-page modifier fieldsets.
 */
final class ModifierFrontend implements Bootable {

	/** Back-compat aliases; the canonical constants live on ModifierCart. */
	public const CART_ITEM_KEY = ModifierCart::CART_ITEM_KEY;
	public const FIELD         = ModifierCart::FIELD;

	/**
	 * Rendering only — the cart lifecycle is ModifierCart's.
	 */
	public function boot(): void {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_groups' ) );
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
	/* Helpers                                                                */
	/* ---------------------------------------------------------------------- */

	/**
	 * "(+$15.00)" / "(-$10.00)" — empty when the delta is zero.
	 *
	 * @param float $delta Per-unit price delta.
	 */
	private static function delta_hint( float $delta ): string {
		return ModifierCart::delta_hint( $delta );
	}
}
