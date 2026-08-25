<?php
/**
 * Cuentas abiertas — the parked ticket between armar and liquidar.
 *
 * A tab is rounds of served product waiting to be settled. Two clocks run
 * deliberately apart:
 *
 * - STOCK moves at add-time. A latte carried to the table is gone from the
 *   bar whether or not anyone has paid yet; waiting until close would let
 *   the ring-up grid promise product that no longer exists.
 * - REVENUE moves at close. The WooCommerce order is created only when the
 *   tab settles, so open tabs never pollute the summary, the corte, or the
 *   arqueo — and an abandoned tab is a visible void, not phantom income.
 *
 * Removing a line restores its stock and writes a `void` ledger row against
 * `tab_id` (the nullable-order_id shape PosEvents carries for exactly this).
 * Close hands the stored lines to WalkInOrders::create with the
 * stock_already_reserved flag: availability was enforced at add-time and the
 * stock is already out, so the order must neither re-check nor re-decrement.
 * Lines are re-priced at close by create() — a mid-tab price or modifier
 * change is settled at the price of the moment of payment; the snapshot in
 * items_json is display truth, not charge truth.
 *
 * The shift close refuses to close over open tabs (or force-voids them with
 * a supervisor authorization) — an open tab at cierre would make the arqueo
 * lie. See Shifts::close().
 *
 * Feature-flagged: Options::POS `open_tabs`, OFF by default everywhere.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Ordering;

use Haramara\Core\Catalog\ModifierApplication;
use Haramara\Core\Catalog\ModifierResolver;
use Haramara\Core\Setup\Activator;
use Haramara\Core\Setup\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab lifecycle: open → rounds → close/void.
 */
final class Tabs {

	/** Ceiling on simultaneously open tabs — a café bar, not a beer hall. */
	private const MAX_OPEN = 30;

	/** Sanity ceiling per line (parity with WalkInOrders). */
	private const MAX_QTY = 99;

	/** Fully-qualified tabs table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Activator::TABS_TABLE;
	}

	/** Whether the feature is on for this site. */
	public static function enabled(): bool {
		return (bool) Options::get( Options::POS, 'open_tabs', false );
	}

	/**
	 * All open tabs, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function open_tabs(): array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE status = 'open' ORDER BY id ASC", 'ARRAY_A' );

		return array_map( array( self::class, 'serialize' ), is_array( $rows ) ? $rows : array() );
	}

	/** Count of open tabs (the shift close's gate). */
	public static function open_count(): int {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'open'" );
	}

	/**
	 * Open an empty tab.
	 *
	 * @param string              $label    Mesa/nombre ("Mesa 2", "Sr. de la ventana").
	 * @param array<string,mixed> $operator Who opened it.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function open( string $label, array $operator ) {
		if ( ! self::enabled() ) {
			return self::disabled_error();
		}

		$label = mb_substr( sanitize_text_field( $label ), 0, 80 );
		if ( '' === $label ) {
			return new \WP_Error(
				'haramara_tab_label_required',
				__( 'Ponle nombre a la cuenta (mesa o cliente).', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		if ( self::open_count() >= self::MAX_OPEN ) {
			return new \WP_Error(
				'haramara_too_many_tabs',
				__( 'Hay demasiadas cuentas abiertas. Cierra algunas primero.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		$shift = Shifts::current();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			self::table(),
			array(
				'status'        => 'open',
				'label'         => $label,
				'opened_at'     => ( new \DateTimeImmutable( 'now', Options::timezone() ) )->format( 'Y-m-d H:i:s' ),
				'shift_id'      => null !== $shift ? (int) $shift['id'] : 0,
				'operator_key'  => substr( sanitize_key( (string) ( $operator['key'] ?? '' ) ), 0, 32 ),
				'operator_name' => substr( sanitize_text_field( (string) ( $operator['name'] ?? '' ) ), 0, 80 ),
				'items_json'    => (string) wp_json_encode( array() ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'haramara_tab_not_opened',
				__( 'No se pudo abrir la cuenta. Intenta de nuevo.', 'haramara-core' ),
				array( 'status' => 500 )
			);
		}

		return self::find( (int) $wpdb->insert_id );
	}

	/**
	 * Add a round: validate every line, decrement stock, append to the tab.
	 *
	 * Same validate-all-then-apply discipline as salidas: no SQL transaction
	 * (stock updates are relative), stock decrement BEFORE the snapshot write
	 * — an unlogged decrement understates the tab; the reverse would corrupt
	 * stock truth.
	 *
	 * @param int                                     $tab_id   Tab.
	 * @param array<int,array<string,mixed>>          $items    [{product_id, quantity, modifiers?}].
	 * @param array<string,mixed>                     $operator Who served the round.
	 * @return array<string,mixed>|\WP_Error The updated tab.
	 */
	public static function add_lines( int $tab_id, array $items, array $operator ) {
		$row = self::load_open( $tab_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( empty( $items ) ) {
			return new \WP_Error(
				'haramara_empty_round',
				__( 'La ronda no tiene artículos.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		// ---- validate everything first --------------------------------------
		$resolved = array();
		foreach ( $items as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			$quantity   = (int) ( $item['quantity'] ?? 0 );

			if ( $product_id <= 0 || $quantity <= 0 || $quantity > self::MAX_QTY ) {
				return new \WP_Error(
					'haramara_invalid_line',
					__( 'Artículo o cantidad no válidos.', 'haramara-core' ),
					array( 'status' => 400 )
				);
			}

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product || 'publish' !== $product->get_status() || ! $product->is_purchasable() ) {
				return new \WP_Error(
					'haramara_unknown_product',
					sprintf(
						/* translators: %d: product id. */
						__( 'El producto %d no está disponible para la venta.', 'haramara-core' ),
						$product_id
					),
					array( 'status' => 400 )
				);
			}

			$stock = $product->get_stock_quantity();
			if ( $product->managing_stock() && ! $product->backorders_allowed() && null !== $stock && (int) $stock < $quantity ) {
				return new \WP_Error(
					'haramara_insufficient_stock',
					sprintf(
						/* translators: 1: product name, 2: units in stock. */
						__( 'Solo quedan %2$d de "%1$s".', 'haramara-core' ),
						$product->get_name(),
						(int) $stock
					),
					array( 'status' => 409 )
				);
			}

			$validated = ModifierApplication::validate(
				$product_id,
				array_values( (array) ( $item['modifiers'] ?? array() ) )
			);
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$resolved[] = array(
				'product'   => $product,
				'quantity'  => $quantity,
				'modifiers' => $validated,
			);
		}

		// ---- apply ---------------------------------------------------------
		$lines = self::lines_of( $row );
		foreach ( $resolved as $entry ) {
			$product = $entry['product'];

			if ( $product->managing_stock() ) {
				wc_update_product_stock( $product, $entry['quantity'], 'decrease' );
			}

			$delta   = ModifierApplication::price_delta( $entry['modifiers'] );
			$lines[] = array(
				'product_id'      => $product->get_id(),
				'name'            => substr( $product->get_name(), 0, 200 ),
				'quantity'        => $entry['quantity'],
				'unit_price'      => (float) $product->get_price(),
				'price_delta'     => $delta,
				'modifiers'       => $entry['modifiers'],
				'modifier_labels' => self::labels_for( $product->get_id(), $entry['modifiers'] ),
				'served_at'       => ( new \DateTimeImmutable( 'now', Options::timezone() ) )->format( 'H:i' ),
				'served_by'       => substr( sanitize_text_field( (string) ( $operator['name'] ?? '' ) ), 0, 80 ),
			);
		}

		self::store_lines( $tab_id, $lines );

		return self::find( $tab_id );
	}

	/**
	 * Remove one line: restore its stock and put the void on the ledger.
	 *
	 * @param int                 $tab_id      Tab.
	 * @param int                 $index       Zero-based line index.
	 * @param string              $reason_code One of PosEvents::REASONS.
	 * @param string              $reason_note Note; required for `otro`.
	 * @param array<string,mixed> $operator    Who removed it.
	 * @return array<string,mixed>|\WP_Error The updated tab.
	 */
	public static function remove_line( int $tab_id, int $index, string $reason_code, string $reason_note, array $operator ) {
		$row = self::load_open( $tab_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( ! in_array( $reason_code, PosEvents::REASONS, true ) ) {
			return new \WP_Error(
				'haramara_invalid_reason',
				__( 'Motivo no válido.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}
		if ( 'otro' === $reason_code && '' === trim( $reason_note ) ) {
			return new \WP_Error(
				'haramara_reason_note_required',
				__( 'Con motivo "Otro" es obligatorio escribir el detalle.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		$lines = self::lines_of( $row );
		if ( ! isset( $lines[ $index ] ) ) {
			return new \WP_Error(
				'haramara_line_not_found',
				__( 'Esa línea ya no está en la cuenta.', 'haramara-core' ),
				array( 'status' => 404 )
			);
		}

		$line = $lines[ $index ];
		unset( $lines[ $index ] );

		$product = wc_get_product( (int) $line['product_id'] );
		if ( $product instanceof \WC_Product && $product->managing_stock() ) {
			wc_update_product_stock( $product, (int) $line['quantity'], 'increase' );
		}

		self::store_lines( $tab_id, array_values( $lines ) );

		PosEvents::record(
			array(
				'type'        => 'void',
				'shift_id'    => (int) $row['shift_id'],
				'operator'    => $operator,
				'tab_id'      => $tab_id,
				'amount'      => round( ( (float) $line['unit_price'] + (float) $line['price_delta'] ) * (int) $line['quantity'], 2 ),
				'reason_code' => $reason_code,
				'reason_note' => $reason_note,
				'items'       => array(
					array(
						'name'     => (string) $line['name'],
						'quantity' => (int) $line['quantity'],
					),
				),
			)
		);

		return self::find( $tab_id );
	}

	/**
	 * Settle the tab: build the WooCommerce order from the stored lines.
	 *
	 * @param int                 $tab_id   Tab.
	 * @param string              $payment  WalkInOrders::PAYMENTS member.
	 * @param array<string,mixed> $operator Who charged it.
	 * @param array<string,mixed> $discount Optional sale discount (WalkInOrders policy applies).
	 * @param array<string,mixed> $tip            Optional propina.
	 * @param string              $card_reference Terminal auth/reference on card settles.
	 * @return \WC_Order|\WP_Error
	 */
	public static function close( int $tab_id, string $payment, array $operator, array $discount = array(), array $tip = array(), string $card_reference = '' ) {
		$row = self::load_open( $tab_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		$lines = self::lines_of( $row );
		if ( array() === $lines ) {
			return new \WP_Error(
				'haramara_tab_empty',
				__( 'La cuenta está vacía. Anúlala en lugar de cobrarla.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		$items = array();
		foreach ( $lines as $line ) {
			$items[] = array(
				'product_id' => (int) $line['product_id'],
				'quantity'   => (int) $line['quantity'],
				'modifiers'  => (array) $line['modifiers'],
			);
		}

		$order = WalkInOrders::create(
			$items,
			$payment,
			sprintf(
				/* translators: %s: tab label. */
				__( 'Cuenta: %s', 'haramara-core' ),
				(string) $row['label']
			),
			$operator,
			$discount,
			$tip,
			array( 'stock_already_reserved' => true ),
			$card_reference
		);
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		self::mark_closed( $tab_id, $order->get_id() );

		return $order;
	}

	/**
	 * Void the whole tab: restore all stock, one ledger row.
	 *
	 * @param int                 $tab_id      Tab.
	 * @param string              $reason_code One of PosEvents::REASONS.
	 * @param string              $reason_note Note; required for `otro`.
	 * @param array<string,mixed> $operator    Who voided it.
	 * @return array<string,mixed>|\WP_Error The ledger event.
	 */
	public static function void_tab( int $tab_id, string $reason_code, string $reason_note, array $operator ) {
		$row = self::load_open( $tab_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		if ( ! in_array( $reason_code, PosEvents::REASONS, true ) ) {
			return new \WP_Error(
				'haramara_invalid_reason',
				__( 'Motivo no válido.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}
		if ( 'otro' === $reason_code && '' === trim( $reason_note ) ) {
			return new \WP_Error(
				'haramara_reason_note_required',
				__( 'Con motivo "Otro" es obligatorio escribir el detalle.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		$lines = self::lines_of( $row );
		$total = 0.0;
		$items = array();
		foreach ( $lines as $line ) {
			$product = wc_get_product( (int) $line['product_id'] );
			if ( $product instanceof \WC_Product && $product->managing_stock() ) {
				wc_update_product_stock( $product, (int) $line['quantity'], 'increase' );
			}
			$total  += ( (float) $line['unit_price'] + (float) $line['price_delta'] ) * (int) $line['quantity'];
			$items[] = array(
				'name'     => (string) $line['name'],
				'quantity' => (int) $line['quantity'],
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array(
				'status'    => 'void',
				'closed_at' => ( new \DateTimeImmutable( 'now', Options::timezone() ) )->format( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $tab_id,
				'status' => 'open',
			),
			array( '%s', '%s' ),
			array( '%d', '%s' )
		);

		return PosEvents::record(
			array(
				'type'        => 'void',
				'shift_id'    => (int) $row['shift_id'],
				'operator'    => $operator,
				'tab_id'      => $tab_id,
				'amount'      => round( $total, 2 ),
				'reason_code' => $reason_code,
				'reason_note' => '' !== $reason_note ? $reason_note : (string) $row['label'],
				'items'       => $items,
			)
		);
	}

	/**
	 * Force-void every open tab (the shift close's authorized path).
	 *
	 * @param array<string,mixed> $operator      Who is closing the shift.
	 * @param string              $authorized_by Supervisor who authorized it.
	 * @return int Tabs voided.
	 */
	public static function void_all_open( array $operator, string $authorized_by ): int {
		$count = 0;
		foreach ( self::open_tabs() as $tab ) {
			$event = self::void_tab(
				(int) $tab['id'],
				'cliente_cancelo',
				sprintf(
					/* translators: 1: tab label, 2: supervisor name. */
					__( 'Cierre de turno forzado (%1$s) autorizado por %2$s.', 'haramara-core' ),
					(string) $tab['label'],
					$authorized_by
				),
				$operator
			);
			if ( ! is_wp_error( $event ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * One tab by id, serialized.
	 *
	 * @param int $tab_id Tab id.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function find( int $tab_id ) {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $tab_id ), 'ARRAY_A' );

		if ( ! is_array( $row ) ) {
			return new \WP_Error(
				'haramara_tab_not_found',
				__( 'Cuenta no encontrada.', 'haramara-core' ),
				array( 'status' => 404 )
			);
		}

		return self::serialize( $row );
	}

	/* === Internals === */

	/**
	 * Load a tab row that is still open.
	 *
	 * @param int $tab_id Tab id.
	 * @return array<string,mixed>|\WP_Error Raw row.
	 */
	private static function load_open( int $tab_id ) {
		if ( ! self::enabled() ) {
			return self::disabled_error();
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error(
				'haramara_wc_unavailable',
				__( 'WooCommerce no está disponible.', 'haramara-core' ),
				array( 'status' => 500 )
			);
		}

		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $tab_id ), 'ARRAY_A' );

		if ( ! is_array( $row ) ) {
			return new \WP_Error(
				'haramara_tab_not_found',
				__( 'Cuenta no encontrada.', 'haramara-core' ),
				array( 'status' => 404 )
			);
		}
		if ( 'open' !== (string) $row['status'] ) {
			return new \WP_Error(
				'haramara_tab_not_open',
				__( 'Esta cuenta ya fue cobrada o anulada.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		return $row;
	}

	/**
	 * Decoded lines of a raw row.
	 *
	 * @param array<string,mixed> $row Raw tab row.
	 * @return array<int,array<string,mixed>>
	 */
	private static function lines_of( array $row ): array {
		$decoded = json_decode( (string) ( $row['items_json'] ?? '[]' ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Persist lines.
	 *
	 * @param int                             $tab_id Tab id.
	 * @param array<int,array<string,mixed>> $lines  Lines.
	 */
	private static function store_lines( int $tab_id, array $lines ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array( 'items_json' => (string) wp_json_encode( array_values( $lines ) ) ),
			array( 'id' => $tab_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a tab settled.
	 *
	 * @param int $tab_id   Tab id.
	 * @param int $order_id The order that settled it.
	 */
	private static function mark_closed( int $tab_id, int $order_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array(
				'status'          => 'closed',
				'closed_order_id' => $order_id,
				'closed_at'       => ( new \DateTimeImmutable( 'now', Options::timezone() ) )->format( 'Y-m-d H:i:s' ),
			),
			array(
				'id'     => $tab_id,
				'status' => 'open',
			),
			array( '%s', '%d', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Human labels for validated selections ("Leche: Avena").
	 *
	 * @param int                            $product_id Product.
	 * @param array<int,array<string,mixed>> $validated  Validated selections.
	 * @return string[]
	 */
	private static function labels_for( int $product_id, array $validated ): array {
		$groups = array();
		foreach ( ModifierResolver::for_product( $product_id ) as $group ) {
			$groups[ (int) $group['id'] ] = $group;
		}

		$out = array();
		foreach ( $validated as $selection ) {
			$group = $groups[ (int) ( $selection['group_id'] ?? 0 ) ] ?? null;
			if ( null === $group ) {
				continue;
			}
			$names = array();
			foreach ( (array) ( $selection['option_keys'] ?? array() ) as $key ) {
				foreach ( $group['options'] as $option ) {
					if ( $option['key'] === $key ) {
						$names[] = $option['name'];
					}
				}
			}
			if ( array() !== $names ) {
				$out[] = $group['name'] . ': ' . implode( ', ', $names );
			}
		}
		return $out;
	}

	/**
	 * Wire shape of a tab row.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private static function serialize( array $row ): array {
		$lines = self::lines_of( $row );
		$total = 0.0;
		foreach ( $lines as $line ) {
			$total += ( (float) $line['unit_price'] + (float) $line['price_delta'] ) * (int) $line['quantity'];
		}

		return array(
			'id'              => (int) $row['id'],
			'status'          => (string) $row['status'],
			'label'           => (string) $row['label'],
			'opened_at'       => (string) $row['opened_at'],
			'opened_by'       => (string) $row['operator_name'],
			'shift_id'        => (int) $row['shift_id'],
			'lines'           => array_values(
				array_map(
					static fn( array $line ): array => array(
						'product_id'      => (int) $line['product_id'],
						'name'            => (string) $line['name'],
						'quantity'        => (int) $line['quantity'],
						'unit_price'      => (float) $line['unit_price'],
						'price_delta'     => (float) $line['price_delta'],
						'modifiers'       => (array) $line['modifiers'],
						'modifier_labels' => array_map( 'strval', (array) ( $line['modifier_labels'] ?? array() ) ),
						'served_at'       => (string) ( $line['served_at'] ?? '' ),
						'served_by'       => (string) ( $line['served_by'] ?? '' ),
					),
					$lines
				)
			),
			'total'           => round( $total, 2 ),
			'closed_order_id' => isset( $row['closed_order_id'] ) && null !== $row['closed_order_id'] ? (int) $row['closed_order_id'] : null,
		);
	}

	/** The one error for a disabled feature. */
	private static function disabled_error(): \WP_Error {
		return new \WP_Error(
			'haramara_tabs_disabled',
			__( 'Las cuentas abiertas no están habilitadas.', 'haramara-core' ),
			array( 'status' => 409 )
		);
	}
}
