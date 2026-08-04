<?php
/**
 * Stock-managed inventory: sold-out products show "Agotado" and hide the buy CTA.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Woo;

use Haramara\Core\Contracts\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensures products are stock-managed with no backorders so a product hitting 0
 * stock renders a disabled "Agotado" state on the shop and single-product views.
 */
final class Inventory implements Bootable {

	public function boot(): void {
		// Defaults for newly created products.
		add_action( 'woocommerce_new_product', array( $this, 'apply_new_product_defaults' ), 10, 2 );

		// Sold-out rendering.
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'loop_add_to_cart_link' ), 10, 2 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'single_sold_out_button' ), 31 );

		// Low-stock admin ping hook point (kept intentionally light).
		add_action( 'woocommerce_low_stock', array( $this, 'on_low_stock' ) );
	}

	/* ---------------------------------------------------------------------- */
	/* Defaults */
	/* ---------------------------------------------------------------------- */

	/**
	 * New products default to manage_stock=yes / backorders=no so that reaching 0
	 * stock immediately hides Add-to-Cart and shows "Agotado".
	 *
	 * @param int              $product_id
	 * @param \WC_Product|null $product
	 */
	public function apply_new_product_defaults( int $product_id, $product = null ): void {
		$product = $product instanceof \WC_Product ? $product : wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$changed = false;
		if ( ! $product->get_manage_stock() ) {
			$product->set_manage_stock( true );
			$changed = true;
		}
		if ( 'no' !== $product->get_backorders() ) {
			$product->set_backorders( 'no' );
			$changed = true;
		}

		if ( $changed ) {
			$product->save();
		}
	}

	/**
	 * Helper to retro-fit existing products with stock management. Callable from
	 * admin tools / WP-CLI. Returns the number of products updated.
	 */
	public static function enable_stock_management( int $limit = -1 ): int {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0;
		}

		$products = wc_get_products(
			array(
				'limit'  => $limit,
				'status' => 'publish',
				'type'   => array( 'simple', 'variable' ),
				'return' => 'objects',
			)
		);

		$count = 0;
		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$changed = false;
			if ( ! $product->get_manage_stock() ) {
				$product->set_manage_stock( true );
				$changed = true;
			}
			if ( 'no' !== $product->get_backorders() ) {
				$product->set_backorders( 'no' );
				$changed = true;
			}
			if ( $changed ) {
				$product->save();
				++$count;
			}
		}

		return $count;
	}

	/* ---------------------------------------------------------------------- */
	/* Sold-out rendering */
	/* ---------------------------------------------------------------------- */

	/**
	 * Replace the shop-loop Add-to-Cart button with a disabled "Agotado" state.
	 *
	 * @param string      $html
	 * @param \WC_Product $product
	 */
	public function loop_add_to_cart_link( string $html, $product ): string {
		if ( $product instanceof \WC_Product && ! $product->is_in_stock() ) {
			return sprintf(
				'<span class="button haramara-agotado disabled" aria-disabled="true">%s</span>',
				esc_html__( 'Agotado', 'haramara-core' )
			);
		}
		return $html;
	}

	/**
	 * On the single product page, WooCommerce hides the Add-to-Cart form when a
	 * product is out of stock (priority 30). At 31 we render a disabled button so
	 * the sold-out state is explicit rather than a blank space.
	 */
	public function single_sold_out_button(): void {
		global $product;
		if ( ! $product instanceof \WC_Product || $product->is_in_stock() ) {
			return;
		}
		printf(
			'<p class="haramara-agotado-single"><button type="button" class="button disabled" disabled aria-disabled="true">%s</button></p>',
			esc_html__( 'Agotado', 'haramara-core' )
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Low-stock ping */
	/* ---------------------------------------------------------------------- */

	/**
	 * Re-broadcast WooCommerce's low-stock event on a Haramara-namespaced hook so
	 * other modules (admin ping, SMS, dashboard) can subscribe without coupling to
	 * WooCommerce internals.
	 *
	 * @param \WC_Product $product
	 */
	public function on_low_stock( $product ): void {
		/**
		 * Fires when a product crosses the low-stock threshold.
		 *
		 * @param \WC_Product $product The affected product.
		 */
		do_action( 'haramara_low_stock_ping', $product );
	}
}
