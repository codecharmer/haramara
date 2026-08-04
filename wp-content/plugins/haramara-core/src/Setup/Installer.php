<?php
/**
 * Content installer / seeder.
 *
 * Orchestrates the one-command provisioning of Haramara's storefront: options,
 * product categories, products, pages, navigation, and the WooCommerce core
 * pages. Every step is idempotent and safe to re-run — existing content is left
 * untouched unless `force` is requested. HPOS-safe (all product access goes
 * through the WooCommerce CRUD, never direct post meta).
 *
 * Not a Bootable service: it is invoked on demand from WP-CLI, from the Settings
 * screen (via the `haramara_run_content_install` action), or from an admin
 * one-shot — never automatically on every request.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Setup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {

	/** Marker meta stamped on every post we create (products, pages, nav). */
	public const MARKER_META = '_haramara_seeded';

	/** Product meta flagging an estimated price (mirrors brand-brief §5). */
	public const PRICE_ESTIMATE_META = '_haramara_price_estimate';

	/** Meta storing the authored SEO description. */
	public const META_DESC = '_haramara_meta_description';

	/** Meta storing the short SEO summary. */
	public const SEO_SHORT = '_haramara_seo_short';

	/**
	 * The three product categories the storefront is organised around,
	 * slug => definition.
	 *
	 * Consolidated from the original seven (brand-brief §5): the category tiles
	 * and the footer link to these slugs directly, so they have to be the ones
	 * the seeder creates. See LEGACY_CATEGORIES for the previous set.
	 *
	 * @var array<string,array{name:string,description:string}>
	 */
	private const CATEGORIES = array(
		'cafes'      => array(
			'name'        => 'Cafés',
			'description' => 'Espresso, cold brew y los filtrados de la casa: Chill, Groove y Funky.',
		),
		'salados'    => array(
			'name'        => 'Salados',
			'description' => 'Croissants, bagels, pretzels y masa madre con procesos manuales.',
		),
		'especiales' => array(
			'name'        => 'Especiales',
			'description' => 'Lo dulce de la casa; rota cada mes.',
		),
	);

	/**
	 * Categories seeded before the catalogue was consolidated, each mapped to
	 * its replacement.
	 *
	 * Kept for two reasons: an install seeded with the old taxonomy can be
	 * migrated in place without losing product assignments, and uninstall can
	 * still remove terms this plugin created. Every value must be a key of
	 * CATEGORIES.
	 *
	 * @var array<string,string>
	 */
	private const LEGACY_CATEGORIES = array(
		'pan-rustico' => 'salados',
		'pan-dulce'   => 'especiales',
		'varios'      => 'especiales',
	);

	/**
	 * Run the full install. Idempotent; pass `force => true` to overwrite
	 * existing products/pages, `skip_media => true` to bypass image import.
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed> Report.
	 */
	public static function install_all( array $args = array() ): array {
		$force      = ! empty( $args['force'] );
		$skip_media = ! empty( $args['skip_media'] );

		$report = array(
			'ok'          => true,
			'force'       => $force,
			'woocommerce' => self::has_woocommerce(),
			'block_theme' => wp_is_block_theme(),
			'messages'    => array(),
		);

		self::install_options();
		$report['options']    = 'installed';
		$report['messages'][] = 'Opciones instaladas.';

		$report['branding'] = self::install_branding();

		$cat_map = array();
		if ( self::has_woocommerce() ) {
			$cat_result           = self::install_categories();
			$cat_map              = $cat_result['map'];
			$report['categories'] = $cat_result['report'];
			$report['products']   = self::install_products( $cat_map, $force, $skip_media );
		} else {
			$report['categories'] = array( 'skipped' => 'WooCommerce inactivo' );
			$report['products']   = array( 'skipped' => 'WooCommerce inactivo' );
			$report['messages'][] = 'WooCommerce no está activo: se omitieron categorías y productos.';
		}

		$report['pages']      = self::install_pages( $force );
		$report['navigation'] = self::install_navigation();
		$report['menus']      = self::install_menus();
		$report['wc_pages']   = self::set_woocommerce_pages();
		$report['gateways']   = self::ensure_gateways();

		update_option( 'haramara_content_installed', 1 );
		delete_option( 'haramara_needs_content_install' );
		$report['messages'][] = 'Contenido marcado como instalado.';

		self::log( $report );

		return $report;
	}

	/* ---------------------------------------------------------------------- */
	/* Options */
	/* ---------------------------------------------------------------------- */

	/* ---------------------------------------------------------------------- */
	/* Branding */
	/* ---------------------------------------------------------------------- */

	/**
	 * Seed the site logo so the header mark survives a clean install.
	 *
	 * The header renders core/site-logo, which reads the `custom_logo` theme
	 * mod — a value that exists only in the database. Setting it by hand in the
	 * Customizer works right up until the next fresh deploy, at which point the
	 * header silently falls back to the site title, so it is seeded here with
	 * everything else.
	 *
	 * The source is the ink mark on transparency (`marca-haramara.png`); the
	 * transparent header inverts it to linen in CSS, so one asset serves both
	 * the dark hero and the light interior header.
	 *
	 * @return array{logo:int}
	 */
	public static function install_branding(): array {
		$logo_id = MediaImporter::ensure( 'marca-haramara', 'Haramara Café' );

		if ( $logo_id > 0 ) {
			set_theme_mod( 'custom_logo', $logo_id );
		}

		return array( 'logo' => $logo_id );
	}

	public static function install_options(): void {
		Options::install_defaults();
	}

	/* ---------------------------------------------------------------------- */
	/* Categories */
	/* ---------------------------------------------------------------------- */

	/**
	 * @return array{map:array<string,int>,report:array<string,int>}
	 */
	public static function install_categories(): array {
		$map    = array();
		$report = array(
			'created'  => 0,
			'existing' => 0,
			'updated'  => 0,
		);

		foreach ( self::CATEGORIES as $slug => $def ) {
			$existing = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $existing instanceof \WP_Term ) {
				$map[ $slug ] = (int) $existing->term_id;
				++$report['existing'];

				// Keep terms we created in step with the seed data — otherwise a
				// corrected name or description never reaches an install that
				// already ran. Terms the client made by hand are left alone:
				// their copy is the source of truth, not ours.
				if ( get_term_meta( $existing->term_id, self::MARKER_META, true )
					&& ( $existing->name !== $def['name'] || $existing->description !== $def['description'] )
				) {
					wp_update_term(
						$existing->term_id,
						'product_cat',
						array(
							'name'        => $def['name'],
							'description' => $def['description'],
						)
					);
					++$report['updated'];
				}

				continue;
			}
			$term = wp_insert_term(
				$def['name'],
				'product_cat',
				array(
					'slug'        => $slug,
					'description' => $def['description'],
				)
			);
			if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
				$map[ $slug ] = (int) $term['term_id'];
				update_term_meta( (int) $term['term_id'], self::MARKER_META, 1 );
				++$report['created'];
			}
		}

		$report['migrated'] = self::migrate_legacy_categories( $map );

		return array(
			'map'    => $map,
			'report' => $report,
		);
	}

	/**
	 * Move products off the legacy taxonomy and retire the old terms.
	 *
	 * Runs only after the current categories exist, so every legacy term has
	 * somewhere to send its products. The new term is *appended* rather than
	 * set outright, then the legacy term is deleted — WordPress drops the old
	 * relationships with it. Doing it in that order keeps a product that
	 * somehow belongs to two legacy categories from losing one of them.
	 *
	 * Only terms this plugin created (MARKER_META) are deleted; a category
	 * added by hand under a legacy slug is left in place, since removing
	 * someone else's taxonomy is not ours to do.
	 *
	 * @param array<string,int> $map Current slug => term_id.
	 * @return int Number of product reassignments performed.
	 */
	private static function migrate_legacy_categories( array $map ): int {
		$moved = 0;

		foreach ( self::LEGACY_CATEGORIES as $old_slug => $new_slug ) {
			$old = get_term_by( 'slug', $old_slug, 'product_cat' );
			if ( ! $old instanceof \WP_Term ) {
				continue;
			}

			$target = (int) ( $map[ $new_slug ] ?? 0 );
			if ( $target <= 0 ) {
				continue;
			}

			$product_ids = get_objects_in_term( $old->term_id, 'product_cat' );
			if ( is_array( $product_ids ) ) {
				foreach ( $product_ids as $product_id ) {
					$result = wp_set_object_terms( (int) $product_id, array( $target ), 'product_cat', true );
					if ( ! is_wp_error( $result ) ) {
						++$moved;
					}
				}
			}

			if ( get_term_meta( $old->term_id, self::MARKER_META, true ) ) {
				wp_delete_term( $old->term_id, 'product_cat' );
			}
		}

		if ( $moved > 0 ) {
			// Category counts drive the storefront filters; stale ones show
			// empty tiles.
			foreach ( $map as $term_id ) {
				wp_update_term_count_now( array( (int) $term_id ), 'product_cat' );
			}
		}

		return $moved;
	}

	/* ---------------------------------------------------------------------- */
	/* Products */
	/* ---------------------------------------------------------------------- */

	/**
	 * @param array<string,int> $cat_map
	 * @return array<string,mixed>
	 */
	public static function install_products( array $cat_map, bool $force, bool $skip_media ): array {
		$report = array(
			'created'     => 0,
			'updated'     => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'by_category' => array(),
			'media'       => 0,
		);

		$products        = self::data( 'products' );
		$report['total'] = count( $products );

		// Inventory's new-product defaults would flip manage_stock back on
		// between our save and the database, turning every made-to-order
		// drink outofstock. The installer owns stock intent while seeding.
		add_filter( 'haramara_suppress_inventory_defaults', '__return_true' );

		foreach ( $products as $def ) {
			$slug = sanitize_title( (string) ( $def['slug'] ?? $def['name'] ?? '' ) );
			if ( '' === $slug ) {
				++$report['failed'];
				continue;
			}

			$existing_id = self::product_id_by_slug( $slug );
			if ( $existing_id > 0 && ! $force ) {
				++$report['skipped'];
				self::tally_category( $report, (string) ( $def['category'] ?? '' ) );
				continue;
			}

			$id = self::upsert_product( $def, $cat_map, $existing_id, $skip_media, $report );
			if ( $id > 0 ) {
				$existing_id > 0 ? ++$report['updated'] : ++$report['created'];
				self::tally_category( $report, (string) ( $def['category'] ?? '' ) );
			} else {
				++$report['failed'];
			}
		}

		remove_filter( 'haramara_suppress_inventory_defaults', '__return_true' );

		return $report;
	}

	/**
	 * Create or update a single product via the WooCommerce CRUD (HPOS-safe).
	 *
	 * @param array<string,mixed> $def
	 * @param array<string,int>   $cat_map
	 * @param array<string,mixed> $report Passed by reference to tally media.
	 */
	private static function upsert_product( array $def, array $cat_map, int $existing_id, bool $skip_media, array &$report ): int {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}

		$product = $existing_id > 0 ? wc_get_product( $existing_id ) : new \WC_Product_Simple();
		if ( ! $product instanceof \WC_Product ) {
			$product = new \WC_Product_Simple();
		}

		$product->set_name( sanitize_text_field( (string) ( $def['name'] ?? '' ) ) );
		$product->set_slug( sanitize_title( (string) ( $def['slug'] ?? '' ) ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_short_description( self::paragraphs( (string) ( $def['short_description'] ?? '' ) ) );
		$product->set_description( self::paragraphs( (string) ( $def['description'] ?? '' ) ) );

		$price = (string) round( (float) ( $def['regular_price'] ?? 0 ), 2 );
		$product->set_regular_price( $price );
		$product->set_price( $price );

		// SKU: only set when free, to avoid duplicate-SKU exceptions on re-run.
		$sku = sanitize_text_field( (string) ( $def['sku'] ?? '' ) );
		if ( '' !== $sku ) {
			$owner = (int) wc_get_product_id_by_sku( $sku );
			if ( 0 === $owner || $owner === $product->get_id() ) {
				$product->set_sku( $sku );
			}
		}

		$stock = max( 0, (int) ( $def['stock'] ?? 0 ) );
		$product->set_manage_stock( ! empty( $def['manage_stock'] ) );
		$product->set_stock_quantity( $stock );
		// Unmanaged stock means made-to-order (drinks, bar items): always
		// purchasable. Only a managed, empty count is genuinely sold out.
		$product->set_stock_status( ( ! empty( $def['manage_stock'] ) && $stock <= 0 ) ? 'outofstock' : 'instock' );

		$cat_slug = (string) ( $def['category'] ?? '' );
		if ( isset( $cat_map[ $cat_slug ] ) ) {
			$product->set_category_ids( array( $cat_map[ $cat_slug ] ) );
		}

		$tag_ids = self::tag_ids( (array) ( $def['tags'] ?? array() ) );
		if ( $tag_ids ) {
			$product->set_tag_ids( $tag_ids );
		}

		$product->set_attributes( self::build_attributes( (array) ( $def['attributes'] ?? array() ) ) );

		// Featured image via the media importer (real photo or placeholder).
		if ( ! $skip_media ) {
			$image_key = sanitize_key( (string) ( $def['image_key'] ?? '' ) );
			if ( '' !== $image_key && class_exists( MediaImporter::class ) ) {
				$att = MediaImporter::ensure( $image_key, (string) ( $def['image_alt'] ?? '' ) );
				if ( $att > 0 ) {
					$product->set_image_id( $att );
					++$report['media'];
				}
			}
		}

		$id = $product->save();
		if ( ! $id ) {
			return 0;
		}

		update_post_meta( $id, self::MARKER_META, 1 );
		update_post_meta( $id, self::META_DESC, sanitize_text_field( (string) ( $def['meta_description'] ?? '' ) ) );
		update_post_meta( $id, self::SEO_SHORT, sanitize_text_field( (string) ( $def['seo_short'] ?? '' ) ) );

		if ( ! empty( $def['price_is_estimate'] ) ) {
			update_post_meta( $id, self::PRICE_ESTIMATE_META, 1 );
		} else {
			delete_post_meta( $id, self::PRICE_ESTIMATE_META );
		}

		return (int) $id;
	}

	/* ---------------------------------------------------------------------- */
	/* Pages */
	/* ---------------------------------------------------------------------- */

	/**
	 * @return array<string,mixed>
	 */
	public static function install_pages( bool $force ): array {
		$report = array(
			'created'    => 0,
			'updated'    => 0,
			'skipped'    => 0,
			'failed'     => 0,
			'front_page' => 0,
			'posts_page' => 0,
		);
		$pages  = self::data( 'pages' );

		foreach ( $pages as $def ) {
			$slug = sanitize_title( (string) ( $def['slug'] ?? $def['title'] ?? '' ) );
			if ( '' === $slug ) {
				++$report['failed'];
				continue;
			}

			$existing    = get_page_by_path( $slug, OBJECT, 'page' );
			$existing_id = $existing instanceof \WP_Post ? (int) $existing->ID : 0;

			if ( $existing_id > 0 && ! $force ) {
				++$report['skipped'];
			} else {
				$postarr = array(
					'ID'           => $existing_id ? $existing_id : 0,
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => sanitize_text_field( (string) ( $def['title'] ?? '' ) ),
					'post_name'    => $slug,
					'post_content' => (string) ( $def['content'] ?? '' ),
				);

				$was_existing = $existing instanceof \WP_Post;
				$id           = self::without_kses( static fn() => wp_insert_post( $postarr, true ) );
				if ( is_wp_error( $id ) || ! $id ) {
					++$report['failed'];
					continue;
				}
				$existing_id = (int) $id;
				$was_existing ? ++$report['updated'] : ++$report['created'];

				$template = (string) ( $def['template'] ?? '' );
				update_post_meta( $existing_id, '_wp_page_template', '' !== $template ? $template : 'default' );
				update_post_meta( $existing_id, self::MARKER_META, 1 );
				update_post_meta( $existing_id, self::META_DESC, sanitize_text_field( (string) ( $def['meta_description'] ?? '' ) ) );
				update_post_meta( $existing_id, self::SEO_SHORT, sanitize_text_field( (string) ( $def['seo_short'] ?? '' ) ) );
			}

			if ( ! empty( $def['is_front'] ) && $existing_id > 0 ) {
				$report['front_page'] = $existing_id;
			}
			if ( ! empty( $def['is_blog'] ) && $existing_id > 0 ) {
				$report['posts_page'] = $existing_id;
			}
		}

		if ( $report['front_page'] > 0 ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $report['front_page'] );
		}
		if ( $report['posts_page'] > 0 ) {
			update_option( 'page_for_posts', $report['posts_page'] );
		}

		return $report;
	}

	/* ---------------------------------------------------------------------- */
	/* Navigation (wp_navigation) */
	/* ---------------------------------------------------------------------- */

	/**
	 * @return array<string,int>
	 */
	public static function install_navigation(): array {
		$nav = self::data( 'navigation' );
		$out = array(
			'primary_id' => 0,
			'footer_id'  => 0,
		);

		if ( ! empty( $nav['primary'] ) ) {
			$out['primary_id'] = self::upsert_navigation_post( 'principal', 'Principal', (array) $nav['primary'] );
			update_option( 'haramara_primary_nav_id', $out['primary_id'] );
		}
		if ( ! empty( $nav['footer'] ) ) {
			$out['footer_id'] = self::upsert_navigation_post( 'pie', 'Pie de página', (array) $nav['footer'] );
			update_option( 'haramara_footer_nav_id', $out['footer_id'] );
		}

		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 */
	private static function upsert_navigation_post( string $slug, string $title, array $items ): int {
		$blocks = array();
		foreach ( $items as $item ) {
			$block = self::nav_link_block( (array) $item );
			if ( '' !== $block ) {
				$blocks[] = $block;
			}
		}
		$content = implode( "\n", $blocks );

		$existing = get_posts(
			array(
				'post_type'     => 'wp_navigation',
				'name'          => $slug,
				'post_status'   => 'any',
				'numberposts'   => 1,
				'no_found_rows' => true,
			)
		);

		$postarr = array(
			'post_type'    => 'wp_navigation',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
		);
		if ( $existing ) {
			$postarr['ID'] = (int) $existing[0]->ID;
		}

		$id = self::without_kses( static fn() => wp_insert_post( $postarr, true ) );
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		update_post_meta( (int) $id, self::MARKER_META, 1 );

		return (int) $id;
	}

	/**
	 * Build a core/navigation-link block for a nav item, resolving page slugs to
	 * live permalinks. Returns '' when the target page is missing (no 404s).
	 *
	 * @param array<string,mixed> $item
	 */
	private static function nav_link_block( array $item ): string {
		$label = sanitize_text_field( (string) ( $item['label'] ?? '' ) );
		if ( '' === $label ) {
			return '';
		}

		if ( ! empty( $item['slug'] ) ) {
			$page = get_page_by_path( sanitize_title( (string) $item['slug'] ), OBJECT, 'page' );
			if ( ! $page instanceof \WP_Post ) {
				return '';
			}
			$attrs = array(
				'label' => $label,
				'type'  => 'page',
				'id'    => (int) $page->ID,
				'url'   => get_permalink( $page ),
				'kind'  => 'post-type',
			);
		} else {
			$url = (string) ( $item['url'] ?? '' );
			if ( '' === $url ) {
				return '';
			}
			if ( str_starts_with( $url, '/' ) ) {
				$page = get_page_by_path( trim( $url, '/' ), OBJECT, 'page' );
				$url  = $page instanceof \WP_Post ? get_permalink( $page ) : home_url( $url );
			}
			$attrs = array(
				'label' => $label,
				'url'   => esc_url_raw( $url ),
				'kind'  => 'custom',
			);
		}

		if ( ! empty( $item['cta'] ) ) {
			$attrs['className'] = 'haramara-nav-cta';
		}

		return '<!-- wp:navigation-link ' . wp_json_encode( $attrs ) . ' /-->';
	}

	/* ---------------------------------------------------------------------- */
	/* Classic-theme menu locations */
	/* ---------------------------------------------------------------------- */

	/**
	 * Assign the primary menu to a classic theme location. For block themes the
	 * navigation is referenced by the header pattern, so this is a no-op.
	 *
	 * @return array<string,mixed>
	 */
	public static function install_menus(): array {
		if ( wp_is_block_theme() ) {
			return array(
				'mode' => 'block-theme',
				'note' => 'La navegación se referencia desde el patrón de encabezado.',
			);
		}

		$nav   = self::data( 'navigation' );
		$items = (array) ( $nav['primary'] ?? array() );
		if ( ! $items ) {
			return array(
				'mode' => 'classic',
				'note' => 'Sin elementos de navegación.',
			);
		}

		$menu_name = 'Haramara Principal';
		$menu      = wp_get_nav_menu_object( $menu_name );
		$menu_id   = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu( $menu_name );
		if ( is_wp_error( $menu_id ) || 0 === $menu_id ) {
			return array(
				'mode' => 'classic',
				'note' => 'No se pudo crear el menú.',
			);
		}

		// Only populate an empty menu, to keep re-runs non-duplicating.
		if ( ! wp_get_nav_menu_items( $menu_id ) ) {
			foreach ( $items as $item ) {
				$item = (array) $item;
				if ( ! empty( $item['slug'] ) ) {
					$page = get_page_by_path( sanitize_title( (string) $item['slug'] ), OBJECT, 'page' );
					if ( ! $page instanceof \WP_Post ) {
						continue;
					}
					wp_update_nav_menu_item(
						$menu_id,
						0,
						array(
							'menu-item-title'     => sanitize_text_field( (string) $item['label'] ),
							'menu-item-object'    => 'page',
							'menu-item-object-id' => (int) $page->ID,
							'menu-item-type'      => 'post_type',
							'menu-item-status'    => 'publish',
						)
					);
				} else {
					$url = (string) ( $item['url'] ?? '' );
					if ( str_starts_with( $url, '/' ) ) {
						$url = home_url( $url );
					}
					wp_update_nav_menu_item(
						$menu_id,
						0,
						array(
							'menu-item-title'  => sanitize_text_field( (string) $item['label'] ),
							'menu-item-url'    => esc_url_raw( $url ),
							'menu-item-type'   => 'custom',
							'menu-item-status' => 'publish',
						)
					);
				}
			}
		}

		$locations = get_registered_nav_menus();
		if ( $locations ) {
			$location              = array_key_exists( 'primary', $locations ) ? 'primary' : (string) array_key_first( $locations );
			$assigned              = (array) get_theme_mod( 'nav_menu_locations', array() );
			$assigned[ $location ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $assigned );
		}

		return array(
			'mode'    => 'classic',
			'menu_id' => $menu_id,
		);
	}

	/* ---------------------------------------------------------------------- */
	/* WooCommerce core pages */
	/* ---------------------------------------------------------------------- */

	/**
	 * Enable the pay-at-pickup gateway on fresh installs.
	 *
	 * The launch flow is order online, pay at the bar (Stripe keys come
	 * later), but WooCommerce ships COD disabled — leaving the Store API with
	 * zero payment methods and every checkout rejected. Seeds only when the
	 * option is absent, so a deliberate later disable in wp-admin is never
	 * overridden by deploys.
	 */
	public static function ensure_gateways(): bool {
		if ( ! self::has_woocommerce() ) {
			return false;
		}
		if ( false !== get_option( 'woocommerce_cod_settings' ) ) {
			return false;
		}

		update_option(
			'woocommerce_cod_settings',
			array(
				'enabled'            => 'yes',
				'title'              => 'Paga al recoger',
				'description'        => 'Efectivo o tarjeta al recoger en barra.',
				'instructions'       => 'Tu pedido se paga al recogerlo en Tulipán 302.',
				'enable_for_methods' => array(),
				'enable_for_virtual' => 'yes',
			),
			false
		);

		return true;
	}

	public static function set_woocommerce_pages(): bool {
		if ( ! self::has_woocommerce() ) {
			return false;
		}
		if ( class_exists( '\WC_Install' ) && method_exists( '\WC_Install', 'create_pages' ) ) {
			// Idempotent: WooCommerce skips pages that already exist.
			\WC_Install::create_pages();
			self::localize_woocommerce_pages();
			self::ensure_permalinks();
			return true;
		}
		return false;
	}

	/**
	 * WooCommerce creates its pages in the locale active at install time
	 * (often en_US on a fresh box). The site is Spanish-first, and the theme
	 * links to /mi-cuenta, so titles and the account slug are normalized here.
	 */
	private static function localize_woocommerce_pages(): void {
		$fixes = array(
			'woocommerce_cart_page_id'      => array( 'Carrito', null ),
			'woocommerce_checkout_page_id'  => array( 'Finalizar pedido', null ),
			'woocommerce_myaccount_page_id' => array( 'Mi cuenta', 'mi-cuenta' ),
		);
		foreach ( $fixes as $option => $fix ) {
			$page_id = (int) get_option( $option );
			if ( $page_id <= 0 ) {
				continue;
			}
			$update = array(
				'ID'         => $page_id,
				'post_title' => $fix[0],
			);
			if ( null !== $fix[1] ) {
				$update['post_name'] = $fix[1];
			}
			wp_update_post( $update );
		}
	}

	/**
	 * Pretty permalinks are load-bearing (every theme link and the /en/
	 * language tree assume them). A fresh install ships with plain
	 * permalinks and no .htaccess; deploys must not inherit that.
	 */
	private static function ensure_permalinks(): void {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			update_option( 'permalink_structure', '/%postname%/' );
		}
		flush_rewrite_rules( false );
	}

	/* ---------------------------------------------------------------------- */
	/* Reset (used by `wp haramara reset --yes`) */
	/* ---------------------------------------------------------------------- */

	/**
	 * Remove everything the seeder created, identified by the marker meta.
	 * Destructive; the caller is responsible for the confirmation gate.
	 *
	 * @return array<string,int>
	 */
	public static function reset(): array {
		$report = array(
			'products'   => 0,
			'pages'      => 0,
			'navigation' => 0,
			'media'      => 0,
			'categories' => 0,
		);

		$post_types = array( 'product', 'page', 'wp_navigation', 'attachment' );
		foreach ( $post_types as $type ) {
			$meta_key = 'attachment' === $type ? MediaImporter::MARKER_META : self::MARKER_META;
			$ids      = get_posts(
				array(
					'post_type'   => $type,
					'post_status' => 'any',
					'numberposts' => -1,
					'fields'      => 'ids',
					'meta_key'    => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'      => 1,          // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			foreach ( $ids as $id ) {
				if ( wp_delete_post( (int) $id, true ) ) {
					$bucket = 'product' === $type ? 'products' : ( 'page' === $type ? 'pages' : ( 'attachment' === $type ? 'media' : 'navigation' ) );
					++$report[ $bucket ];
				}
			}
		}

		// Legacy slugs included so an install seeded before the catalogue was
		// consolidated still uninstalls cleanly instead of orphaning terms.
		$category_slugs = array_merge(
			array_keys( self::CATEGORIES ),
			array_keys( self::LEGACY_CATEGORIES )
		);

		foreach ( $category_slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, 'product_cat' );
			if ( $term instanceof \WP_Term && get_term_meta( $term->term_id, self::MARKER_META, true ) ) {
				wp_delete_term( $term->term_id, 'product_cat' );
				++$report['categories'];
			}
		}

		// The logo attachment is removed with the rest of the seeded media
		// above; drop the theme mod too so it does not point at a dead ID.
		remove_theme_mod( 'custom_logo' );

		delete_option( MediaImporter::CACHE_OPTION );
		delete_option( 'haramara_content_installed' );
		delete_option( 'haramara_primary_nav_id' );
		delete_option( 'haramara_footer_nav_id' );

		return $report;
	}

	/* ---------------------------------------------------------------------- */
	/* Helpers */
	/* ---------------------------------------------------------------------- */

	/**
	 * Every product image_key mapped to its Spanish alt text. Used by the
	 * `wp haramara import-media` command to (re-)import the full media set.
	 *
	 * @return array<string,string>
	 */
	public static function media_keys(): array {
		$keys = array();
		foreach ( self::data( 'products' ) as $def ) {
			$key = sanitize_key( (string) ( $def['image_key'] ?? '' ) );
			if ( '' !== $key ) {
				$keys[ $key ] = (string) ( $def['image_alt'] ?? '' );
			}
		}
		return $keys;
	}

	public static function has_woocommerce(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	/**
	 * Load and return a data file's array (products/pages/navigation).
	 *
	 * @return array<int|string,mixed>
	 */
	private static function data( string $name ): array {
		// Internal path building only — never sanitize_file_name(), which treats
		// known document extensions as filenames ("pages" → "unnamed-file.pages"
		// on WP 7.0+ because .pages is an iWork extension).
		$name = strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $name ) );
		if ( '' === $name ) {
			return array();
		}
		$file = trailingslashit( HARAMARA_CORE_DIR ) . 'data/' . $name . '.php';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = require $file;
		return is_array( $data ) ? $data : array();
	}

	private static function product_id_by_slug( string $slug ): int {
		$post = get_page_by_path( $slug, OBJECT, 'product' );
		return $post instanceof \WP_Post ? (int) $post->ID : 0;
	}

	/**
	 * Convert double-newline-separated copy into paragraph HTML.
	 */
	private static function paragraphs( string $text ): string {
		$parts = preg_split( '/\n\n+/', trim( $text ) );
		$parts = is_array( $parts ) ? $parts : array();
		$out   = '';
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' !== $part ) {
				$out .= '<p>' . wp_kses_post( $part ) . '</p>';
			}
		}
		return $out;
	}

	/**
	 * Build WooCommerce custom product attributes for the four taxonomy flags.
	 *
	 * @param array<string,string> $flags
	 * @return array<int,\WC_Product_Attribute>
	 */
	private static function build_attributes( array $flags ): array {
		if ( ! class_exists( '\WC_Product_Attribute' ) ) {
			return array();
		}
		$labels = array(
			'masa-madre'   => 'Masa madre',
			'vegano'       => 'Vegano',
			'sin-nueces'   => 'Sin nueces',
			'de-temporada' => 'De temporada',
		);
		$attrs  = array();
		$pos    = 0;
		foreach ( $labels as $key => $label ) {
			if ( ! isset( $flags[ $key ] ) ) {
				continue;
			}
			$value = ( 'yes' === strtolower( (string) $flags[ $key ] ) ) ? 'Sí' : 'No';
			$attr  = new \WC_Product_Attribute();
			$attr->set_id( 0 );
			$attr->set_name( $label );
			$attr->set_options( array( $value ) );
			$attr->set_position( $pos++ );
			$attr->set_visible( true );
			$attr->set_variation( false );
			$attrs[] = $attr;
		}
		return $attrs;
	}

	/**
	 * Resolve tag names to product_tag term IDs, creating missing terms.
	 *
	 * @param array<int,string> $tags
	 * @return array<int,int>
	 */
	private static function tag_ids( array $tags ): array {
		$ids = array();
		foreach ( $tags as $tag ) {
			$tag = sanitize_text_field( (string) $tag );
			if ( '' === $tag ) {
				continue;
			}
			$term = term_exists( $tag, 'product_tag' );
			if ( ! $term ) {
				$term = wp_insert_term( $tag, 'product_tag' );
			}
			if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
				$ids[] = (int) $term['term_id'];
			}
		}
		return array_values( array_filter( $ids ) );
	}

	/**
	 * Run a callback with post-content kses filtering suspended, so trusted
	 * block markup (HTML comments) survives insertion under a user that lacks
	 * `unfiltered_html` (e.g. WP-CLI's user 0).
	 *
	 * @template T
	 * @param callable():T $file_name
	 * @return T
	 */
	private static function without_kses( callable $file_name ): mixed {
		$had = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $had ) {
			kses_remove_filters();
		}
		try {
			return $file_name();
		} finally {
			if ( $had ) {
				kses_init_filters();
			}
		}
	}

	/**
	 * @param array<string,mixed> $report
	 * @param string              $cat_slug
	 */
	private static function tally_category( array &$report, string $cat_slug ): void {
		if ( '' === $cat_slug ) {
			return;
		}
		$report['by_category'][ $cat_slug ] = ( $report['by_category'][ $cat_slug ] ?? 0 ) + 1;
	}

	/**
	 * @param array<string,mixed> $report
	 */
	private static function log( array $report ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[haramara-core] Instalación de contenido: ' . wp_json_encode( $report ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional operational logging.
		}
	}
}
