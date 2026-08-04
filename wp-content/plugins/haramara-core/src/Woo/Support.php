<?php
/**
 * WooCommerce foundation: currency, custom order statuses, pickup-only store.
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
 * Configures WooCommerce for a single-location, pickup-only, MXN bakery:
 *
 * - Nudges the store currency to MXN (non-destructive, only if not already MXN).
 * - Registers the operational order statuses `wc-queued`, `wc-preparing` and `wc-ready`.
 * - Treats every order as local pickup: no shipping needs, no shipping address.
 * - Localises the stock label to "Agotado".
 */
final class Support implements Bootable {

	/** Post status key for "En fila" (accepted from the POS queue). */
	public const STATUS_QUEUED = 'wc-queued';

	/** Post status key for "Preparando". */
	public const STATUS_PREPARING = 'wc-preparing';

	/** Post status key for "Listo para recoger". */
	public const STATUS_READY = 'wc-ready';

	public function boot(): void {
		// Currency (light touch — only when the store default is not MXN).
		add_filter( 'woocommerce_currency', array( $this, 'maybe_force_mxn' ) );
		add_filter( 'woocommerce_currency_symbol', array( $this, 'mxn_symbol' ), 10, 2 );

		// Custom order statuses.
		add_action( 'init', array( $this, 'register_order_statuses' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_order_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( $this, 'mark_statuses_paid' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( $this, 'include_in_reports' ) );

		// Pickup-only: never require shipping.
		add_filter( 'woocommerce_cart_needs_shipping', '__return_false' );
		add_filter( 'woocommerce_cart_needs_shipping_address', '__return_false' );
		add_filter( 'woocommerce_ship_to_different_address_checked', '__return_false' );
		add_filter( 'woocommerce_default_address_fields', array( $this, 'relax_address_requirements' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'pickup_only_notice' ), 5 );

		// Localise the out-of-stock availability label.
		add_filter( 'woocommerce_get_availability_text', array( $this, 'localize_out_of_stock' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------- */
	/* Currency */
	/* ---------------------------------------------------------------------- */

	/**
	 * Force MXN unless the store default is already MXN. Non-destructive: an
	 * admin who has deliberately configured MXN sees no change.
	 */
	public function maybe_force_mxn( string $currency ): string {
		return 'MXN' === $currency ? $currency : 'MXN';
	}

	/**
	 * Disambiguate the peso symbol from USD in a bilingual context.
	 */
	public function mxn_symbol( string $symbol, string $currency ): string {
		return 'MXN' === $currency ? 'MX$' : $symbol;
	}

	/* ---------------------------------------------------------------------- */
	/* Order statuses */
	/* ---------------------------------------------------------------------- */

	/**
	 * Register the post statuses backing the operational workflow: accepted
	 * from the POS queue ("En fila"), then prepared and ready for pickup.
	 */
	public function register_order_statuses(): void {
		register_post_status(
			self::STATUS_QUEUED,
			array(
				'label'                     => _x( 'En fila', 'Order status', 'haramara-core' ),
				'public'                    => false,
				'internal'                  => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: order count. */
				'label_count'               => _n_noop( 'En fila <span class="count">(%s)</span>', 'En fila <span class="count">(%s)</span>', 'haramara-core' ),
			)
		);

		register_post_status(
			self::STATUS_PREPARING,
			array(
				'label'                     => _x( 'Preparando', 'Order status', 'haramara-core' ),
				'public'                    => false,
				'internal'                  => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: order count. */
				'label_count'               => _n_noop( 'Preparando <span class="count">(%s)</span>', 'Preparando <span class="count">(%s)</span>', 'haramara-core' ),
			)
		);

		register_post_status(
			self::STATUS_READY,
			array(
				'label'                     => _x( 'Listo para recoger', 'Order status', 'haramara-core' ),
				'public'                    => false,
				'internal'                  => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: order count. */
				'label_count'               => _n_noop( 'Listo para recoger <span class="count">(%s)</span>', 'Listo para recoger <span class="count">(%s)</span>', 'haramara-core' ),
			)
		);
	}

	/**
	 * Insert the statuses into the WooCommerce dropdown, positioned after
	 * "Processing" and before "Completed".
	 *
	 * @param array<string,string> $statuses
	 * @return array<string,string>
	 */
	public function add_order_statuses( array $statuses ): array {
		$ordered = array();
		foreach ( $statuses as $key => $label ) {
			$ordered[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$ordered[ self::STATUS_QUEUED ]    = _x( 'En fila', 'Order status', 'haramara-core' );
				$ordered[ self::STATUS_PREPARING ] = _x( 'Preparando', 'Order status', 'haramara-core' );
				$ordered[ self::STATUS_READY ]     = _x( 'Listo para recoger', 'Order status', 'haramara-core' );
			}
		}

		// Fallback: if "Processing" was absent, ensure they still exist.
		if ( ! isset( $ordered[ self::STATUS_PREPARING ] ) ) {
			$ordered[ self::STATUS_QUEUED ]    = _x( 'En fila', 'Order status', 'haramara-core' );
			$ordered[ self::STATUS_PREPARING ] = _x( 'Preparando', 'Order status', 'haramara-core' );
			$ordered[ self::STATUS_READY ]     = _x( 'Listo para recoger', 'Order status', 'haramara-core' );
		}

		return $ordered;
	}

	/**
	 * "En fila", "Preparando" and "Listo" are post-payment operational states —
	 * they count as paid so revenue/stock bookkeeping treats them like
	 * Processing/Completed.
	 *
	 * @param string[] $statuses Status keys without the `wc-` prefix.
	 * @return string[]
	 */
	public function mark_statuses_paid( array $statuses ): array {
		foreach ( array( 'queued', 'preparing', 'ready' ) as $status ) {
			if ( ! in_array( $status, $statuses, true ) ) {
				$statuses[] = $status;
			}
		}
		return $statuses;
	}

	/**
	 * Include the operational statuses in analytics/report counts.
	 *
	 * @param string[] $statuses
	 * @return string[]
	 */
	public function include_in_reports( array $statuses ): array {
		foreach ( array( 'queued', 'preparing', 'ready' ) as $status ) {
			if ( ! in_array( $status, $statuses, true ) ) {
				$statuses[] = $status;
			}
		}
		return $statuses;
	}

	/* ---------------------------------------------------------------------- */
	/* Pickup-only store */
	/* ---------------------------------------------------------------------- */

	/**
	 * The bread is picked up at the counter — a reservation must never require
	 * the customer's street address. Applies to classic checkout, block
	 * checkout, and Store API alike (all three consult these base fields).
	 *
	 * @param array<string,array<string,mixed>> $fields Address field definitions.
	 * @return array<string,array<string,mixed>>
	 */
	public function relax_address_requirements( array $fields ): array {
		foreach ( array( 'address_1', 'address_2', 'city', 'postcode', 'state' ) as $key ) {
			if ( isset( $fields[ $key ] ) ) {
				$fields[ $key ]['required'] = false;
			}
		}
		return $fields;
	}

	/**
	 * Reassure the customer that this is a reserve-&-pickup store. Robust for
	 * both classic and block checkout (block checkout still fires this hook when
	 * the shortcode fallback is used; the notice is harmless otherwise).
	 */
	public function pickup_only_notice(): void {
		if ( ! function_exists( 'wc_print_notice' ) ) {
			return;
		}
		wc_print_notice(
			esc_html__( 'Todos los pedidos son para recoger en tienda (Calle Ejemplo 000). No hay envíos.', 'haramara-core' ),
			'notice'
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Stock label */
	/* ---------------------------------------------------------------------- */

	/**
	 * Render the out-of-stock label as "Agotado" everywhere WooCommerce derives
	 * availability text (single product, shop loop, availability array).
	 *
	 * @param string      $text
	 * @param \WC_Product $product
	 */
	public function localize_out_of_stock( string $text, $product ): string {
		if ( $product instanceof \WC_Product && ! $product->is_in_stock() ) {
			return esc_html__( 'Agotado', 'haramara-core' );
		}
		return $text;
	}
}
