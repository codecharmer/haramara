<?php
/**
 * Modifier group resolution — which groups apply to a product, in order.
 *
 * A product gets its directly-assigned groups first, then the default groups
 * of its product_cat categories, deduped by group ID. Within each of the two
 * tiers groups follow menu_order then title (the CPT's "Orden" box). The
 * serialization produced here IS the API contract, mirrored by
 * `ModifierGroup` in packages/api-client/src/types.ts:
 *
 *   { id, name, min, max, required, options: [ { key, name, price_delta } ] }
 *
 * `key` is a sanitize_key'd slug of the option name ("Shot extra" → "shot-extra"),
 * unique within its group; clients send keys back, never display names.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Catalog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-side utility: resolve and serialize the groups for a product.
 */
final class ModifierResolver {

	/**
	 * The resolved, ordered modifier groups for a product.
	 *
	 * Groups with no usable options are dropped — an empty group can never be
	 * satisfied when required and renders as noise when optional.
	 *
	 * @param int $product_id Product to resolve.
	 * @return array<int,array{id:int,name:string,min:int,max:int,required:bool,options:array<int,array{key:string,name:string,price_delta:float}>}>
	 */
	public static function for_product( int $product_id ): array {
		if ( $product_id <= 0 ) {
			return array();
		}

		$term_ids = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		$term_ids = is_array( $term_ids ) ? array_map( 'intval', $term_ids ) : array();

		$direct   = array();
		$defaults = array();

		foreach ( self::groups() as $group ) {
			$assigned_products = get_post_meta( $group->ID, ModifierGroups::META_PRODUCTS, true );
			$assigned_products = is_array( $assigned_products ) ? array_map( 'intval', $assigned_products ) : array();

			if ( in_array( $product_id, $assigned_products, true ) ) {
				$direct[] = $group;
				continue;
			}

			$assigned_cats = get_post_meta( $group->ID, ModifierGroups::META_CATS, true );
			$assigned_cats = is_array( $assigned_cats ) ? array_map( 'intval', $assigned_cats ) : array();

			if ( array() !== array_intersect( $term_ids, $assigned_cats ) ) {
				$defaults[] = $group;
			}
		}

		$out = array();
		foreach ( array_merge( $direct, $defaults ) as $group ) {
			$serialized = self::serialize( $group );
			if ( array() !== $serialized['options'] ) {
				$out[] = $serialized;
			}
		}

		return $out;
	}

	/**
	 * One group in the API shape.
	 *
	 * Option keys are slugs of the option names, deduped within the group by
	 * a numeric suffix ("chico", "chico-2") so a repeated name can never make
	 * two options indistinguishable to a client.
	 *
	 * @param \WP_Post $group Group post.
	 * @return array{id:int,name:string,min:int,max:int,required:bool,options:array<int,array{key:string,name:string,price_delta:float}>}
	 */
	public static function serialize( \WP_Post $group ): array {
		$stored  = get_post_meta( $group->ID, ModifierGroups::META_OPTIONS, true );
		$stored  = is_array( $stored ) ? array_values( $stored ) : array();
		$options = array();
		$seen    = array();

		foreach ( $stored as $option ) {
			$option = (array) $option;
			$name   = trim( (string) ( $option['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}

			$key = sanitize_key( sanitize_title( $name ) );
			if ( '' === $key ) {
				continue;
			}
			if ( isset( $seen[ $key ] ) ) {
				$base   = $key;
				$suffix = 2;
				do {
					$key = $base . '-' . $suffix;
					++$suffix;
				} while ( isset( $seen[ $key ] ) );
			}
			$seen[ $key ] = true;

			$options[] = array(
				'key'         => $key,
				'name'        => $name,
				'price_delta' => round( (float) ( $option['price_delta'] ?? 0 ), 2 ),
			);
		}

		return array(
			'id'       => (int) $group->ID,
			'name'     => (string) $group->post_title,
			'min'      => absint( (string) get_post_meta( $group->ID, ModifierGroups::META_MIN, true ) ),
			'max'      => absint( (string) get_post_meta( $group->ID, ModifierGroups::META_MAX, true ) ),
			'required' => '1' === (string) get_post_meta( $group->ID, ModifierGroups::META_REQUIRED, true ),
			'options'  => $options,
		);
	}

	/**
	 * Every published group, ordered by menu_order then title.
	 *
	 * A full fetch instead of meta queries on purpose: assignments live in
	 * serialized arrays (LIKE-matching them is a classic false-positive trap),
	 * and a café has dozens of groups at most. Cached per request so the POS
	 * product feed can resolve groups for every product with one query.
	 *
	 * @return array<int,\WP_Post>
	 */
	private static function groups(): array {
		static $cache = null;
		if ( is_array( $cache ) ) {
			return $cache;
		}

		$posts = get_posts(
			array(
				'post_type'   => ModifierGroups::POST_TYPE,
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);

		$cache = array_values( array_filter( $posts, static fn( $post ): bool => $post instanceof \WP_Post ) );

		return $cache;
	}
}
