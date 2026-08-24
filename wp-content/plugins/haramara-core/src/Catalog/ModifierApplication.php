<?php
/**
 * Sale-time modifier validation and application.
 *
 * The write half of the modifier system: `validate()` turns a client's raw
 * selections (`[ { group_id, option_keys: [...] } ]`) into a trusted,
 * server-priced structure or a WP_Error; `apply()` stamps a validated
 * structure onto a WooCommerce order line as visible item meta
 * ("Leche: Avena (+$15.00)"); `price_delta()` sums the per-unit surcharge so
 * the caller can adjust the line subtotal/total (deltas are per unit —
 * multiply by the line quantity).
 *
 * Skippability contract (mirrored client-side in apps/pos/src/lib/modifiers.tsx):
 * a required group must always be selected with at least max(min, 1) options;
 * an optional group may be omitted entirely, but once engaged its min/max
 * apply. Unknown groups, unknown option keys, and duplicate group entries are
 * hard errors — the POS and storefront always send keys from a fresh resolve.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utility: validate selections against a product's resolved groups,
 * apply them to an order item, and price them.
 */
final class ModifierApplication {

	/** Hidden structured record of the applied selections (order-item meta). */
	public const META_SELECTIONS = '_haramara_modifiers';

	/** Hidden per-unit price delta actually applied (order-item meta). */
	public const META_DELTA = '_haramara_modifiers_delta';

	/**
	 * Validate raw client selections against the product's resolved groups.
	 *
	 * Returns the validated structure in resolved-group order, with every
	 * option re-priced from the stored group (client-sent prices are never
	 * trusted — clients only send keys).
	 *
	 * @param int                     $product_id Product being sold.
	 * @param array<int,array<mixed>> $selections Raw selections: [ { group_id, option_keys: [...] } ].
	 * @return array<int,array{group_id:int,group_name:string,option_keys:array<int,string>,options:array<int,array{key:string,name:string,price_delta:float}>}>|\WP_Error
	 */
	public static function validate( int $product_id, array $selections ) {
		$groups = array();
		foreach ( ModifierResolver::for_product( $product_id ) as $group ) {
			$groups[ $group['id'] ] = $group;
		}

		// Normalize: absint ids, sanitize_key'd unique keys, empties dropped.
		$chosen = array();
		foreach ( $selections as $selection ) {
			$selection = (array) $selection;
			$group_id  = absint( $selection['group_id'] ?? 0 );

			$option_keys = array();
			foreach ( (array) ( $selection['option_keys'] ?? array() ) as $key ) {
				$key = sanitize_key( (string) $key );
				if ( '' !== $key && ! in_array( $key, $option_keys, true ) ) {
					$option_keys[] = $key;
				}
			}
			if ( 0 === $group_id || array() === $option_keys ) {
				continue;
			}

			if ( ! isset( $groups[ $group_id ] ) ) {
				return new \WP_Error(
					'haramara_unknown_modifier_group',
					__( 'El grupo de modificadores no aplica a este producto.', 'haramara-core' ),
					array( 'status' => 400 )
				);
			}
			if ( isset( $chosen[ $group_id ] ) ) {
				return new \WP_Error(
					'haramara_duplicate_modifier_group',
					sprintf(
						/* translators: %s: modifier group name. */
						__( 'El grupo "%s" aparece más de una vez en la selección.', 'haramara-core' ),
						$groups[ $group_id ]['name']
					),
					array( 'status' => 400 )
				);
			}
			$chosen[ $group_id ] = $option_keys;
		}

		// Walk the resolved groups in order so the output (and the ticket) is
		// always deterministic regardless of how the client ordered its payload.
		$validated = array();
		foreach ( $groups as $group_id => $group ) {
			$option_keys = $chosen[ $group_id ] ?? array();
			$count       = count( $option_keys );
			$min         = $group['required'] ? max( $group['min'], 1 ) : $group['min'];

			if ( 0 === $count ) {
				if ( $group['required'] ) {
					return new \WP_Error(
						'haramara_modifier_required',
						sprintf(
							/* translators: %s: modifier group name. */
							__( 'Falta elegir "%s".', 'haramara-core' ),
							$group['name']
						),
						array( 'status' => 400 )
					);
				}
				continue;
			}

			if ( $count < $min ) {
				return new \WP_Error(
					'haramara_modifier_min',
					sprintf(
						/* translators: 1: modifier group name, 2: minimum selections. */
						__( '"%1$s" requiere al menos %2$d opciones.', 'haramara-core' ),
						$group['name'],
						$min
					),
					array( 'status' => 400 )
				);
			}
			if ( $group['max'] > 0 && $count > $group['max'] ) {
				return new \WP_Error(
					'haramara_modifier_max',
					sprintf(
						/* translators: 1: modifier group name, 2: maximum selections. */
						__( '"%1$s" permite máximo %2$d opciones.', 'haramara-core' ),
						$group['name'],
						$group['max']
					),
					array( 'status' => 400 )
				);
			}

			$by_key = array();
			foreach ( $group['options'] as $option ) {
				$by_key[ $option['key'] ] = $option;
			}

			$options = array();
			foreach ( $option_keys as $key ) {
				if ( ! isset( $by_key[ $key ] ) ) {
					return new \WP_Error(
						'haramara_unknown_modifier_option',
						sprintf(
							/* translators: 1: option key, 2: modifier group name. */
							__( 'La opción "%1$s" no existe en "%2$s".', 'haramara-core' ),
							$key,
							$group['name']
						),
						array( 'status' => 400 )
					);
				}
				$options[] = $by_key[ $key ];
			}

			$validated[] = array(
				'group_id'    => $group_id,
				'group_name'  => $group['name'],
				'option_keys' => $option_keys,
				'options'     => $options,
			);
		}

		return $validated;
	}

	/**
	 * Stamp a validated structure onto an order line.
	 *
	 * Writes one visible meta row per selected group ("Leche: Avena", price
	 * hints appended only when an option's delta is nonzero) plus two hidden
	 * rows: the structured selections and the per-unit delta actually
	 * applied, so reports and refunds never have to re-parse display text.
	 *
	 * Does NOT save the item and does NOT touch totals — the caller adjusts
	 * the line subtotal/total by `price_delta( $validated ) * quantity` and
	 * saves (see docs/phase4-integration.md).
	 *
	 * @param \WC_Order_Item_Product                                                                                                      $item      Order line to annotate.
	 * @param array<int,array{group_id:int,group_name:string,option_keys:array<int,string>,options:array<int,array{key:string,name:string,price_delta:float}>}> $validated Result of validate().
	 */
	public static function apply( \WC_Order_Item_Product $item, array $validated ): void {
		if ( array() === $validated ) {
			return;
		}

		foreach ( $validated as $selection ) {
			$labels = array();
			foreach ( $selection['options'] as $option ) {
				$labels[] = 0.0 !== $option['price_delta']
					? sprintf( '%s (%s)', $option['name'], self::format_delta( $option['price_delta'] ) )
					: $option['name'];
			}
			$item->add_meta_data( $selection['group_name'], implode( ', ', $labels ), false );
		}

		$compact = array();
		foreach ( $validated as $selection ) {
			$compact[] = array(
				'group_id'    => $selection['group_id'],
				'option_keys' => $selection['option_keys'],
			);
		}
		$item->add_meta_data( self::META_SELECTIONS, $compact, true );
		$item->add_meta_data( self::META_DELTA, (string) self::price_delta( $validated ), true );
	}

	/**
	 * Total per-unit price delta of a validated structure, in MXN.
	 *
	 * @param array<int,array{group_id:int,group_name:string,option_keys:array<int,string>,options:array<int,array{key:string,name:string,price_delta:float}>}> $validated Result of validate().
	 */
	public static function price_delta( array $validated ): float {
		$total = 0.0;
		foreach ( $validated as $selection ) {
			foreach ( $selection['options'] as $option ) {
				$total += $option['price_delta'];
			}
		}

		return round( $total, 2 );
	}

	/**
	 * "+$15.00" / "-$10.00" — the price hint shown after an option name.
	 *
	 * @param float $delta Per-unit price delta.
	 */
	private static function format_delta( float $delta ): string {
		return sprintf( '%s$%s', $delta >= 0 ? '+' : '-', number_format( abs( $delta ), 2 ) );
	}
}
