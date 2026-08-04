<?php
/**
 * Consolidated JSON-LD @graph emitter.
 *
 * Prints a single `schema.org` graph into the document head describing the
 * business (Bakery/LocalBusiness), the website, the current page, and — where
 * relevant — breadcrumbs, the product, or the article. Emitting one graph with
 * cross-referenced `@id`s (rather than many disconnected blocks) is what search
 * engines prefer and keeps the payload small.
 *
 * All business facts come from {@see Options}; nothing is hardcoded. Output is
 * encoded with {@see wp_json_encode()} using flags that keep unicode and slashes
 * readable while hex-escaping angle brackets so the JSON cannot break out of the
 * surrounding <script> element.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Seo;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Setup\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SchemaGraph implements Bootable {

	/** Encoding flags: readable unicode + slashes, but angle brackets escaped. */
	private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP;

	/**
	 * Emit the graph just after the meta tags in <head>.
	 */
	public function boot(): void {
		add_action( 'wp_head', array( $this, 'render' ), 2 );
	}

	/**
	 * Build and print the JSON-LD graph for the current view.
	 */
	public function render(): void {
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}

		$nodes = $this->build_graph();
		if ( array() === $nodes ) {
			return;
		}

		$graph = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $nodes ),
		);

		$json = wp_json_encode( $graph, self::JSON_FLAGS );
		if ( false === $json ) {
			return;
		}

		echo "\n<!-- Haramara SEO: schema -->\n";
		wp_print_inline_script_tag( $json, array( 'type' => 'application/ld+json' ) );
	}

	/* ---------------------------------------------------------------------- */
	/* Graph assembly */
	/* ---------------------------------------------------------------------- */

	/**
	 * Assemble the list of schema nodes relevant to the current view.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function build_graph(): array {
		$business = Options::business();
		$seo      = Options::seo();
		$pickup   = Options::pickup();

		$nodes = array();

		// Always-present foundational nodes.
		$nodes[] = $this->cafe_node( $business, $seo, $pickup );
		$nodes[] = $this->website_node( $business );

		$object = get_queried_object();
		$post   = $object instanceof \WP_Post ? $object : null;

		// Single product.
		if ( is_singular( 'product' ) && null !== $post ) {
			$product_node = $this->product_node( $post, $seo );
			if ( null !== $product_node ) {
				$nodes[] = $product_node;
			}
			$nodes[] = $this->breadcrumb_node();
			return $this->prune( $nodes );
		}

		// Single post → Article + WebPage.
		if ( is_singular( 'post' ) && null !== $post ) {
			$nodes[] = $this->article_node( $post, $seo );
			$nodes[] = $this->webpage_node( $post );
			$nodes[] = $this->breadcrumb_node();
			return $this->prune( $nodes );
		}

		// Other singular (pages, CPTs) → WebPage.
		if ( is_singular() && null !== $post ) {
			$nodes[] = $this->webpage_node( $post );
			if ( ! is_front_page() ) {
				$nodes[] = $this->breadcrumb_node();
			}
			return $this->prune( $nodes );
		}

		// Archives / taxonomy / search → WebPage + breadcrumbs.
		if ( is_archive() || is_home() || is_search() || is_post_type_archive() ) {
			$nodes[] = $this->collection_node();
			$nodes[] = $this->breadcrumb_node();
		}

		return $this->prune( $nodes );
	}

	/* ---------------------------------------------------------------------- */
	/* Nodes */
	/* ---------------------------------------------------------------------- */

	/**
	 * The Bakery (LocalBusiness/Organization) node — the business identity.
	 *
	 * @param array<string,mixed> $business
	 * @param array<string,mixed> $seo
	 * @param array<string,mixed> $pickup
	 * @return array<string,mixed>
	 */
	private function cafe_node( array $business, array $seo, array $pickup ): array {
		$node = array(
			'@type'         => 'CafeOrCoffeeShop',
			'@id'           => $this->id( '#cafe' ),
			'name'          => (string) ( $business['name'] ?? get_bloginfo( 'name' ) ),
			'url'           => home_url( '/' ),
			'servesCuisine' => 'Café de especialidad y pan de masa madre',
		);

		$tagline = trim( (string) ( $business['tagline'] ?? '' ) );
		if ( '' !== $tagline ) {
			$node['description'] = $tagline;
		}

		$phone = trim( (string) ( $business['phone'] ?? '' ) );
		if ( '' !== $phone ) {
			$node['telephone'] = $phone;
		}

		$email = trim( (string) ( $business['email'] ?? '' ) );
		if ( '' !== $email ) {
			$node['email'] = $email;
		}

		$price_range = trim( (string) ( $seo['price_range'] ?? '' ) );
		if ( '' !== $price_range ) {
			$node['priceRange'] = $price_range;
		}

		// Logo (Organization) + image.
		$logo = $this->attachment_image_object( (int) ( $seo['organization_logo'] ?? 0 ) );
		if ( null !== $logo ) {
			$node['logo']  = $logo;
			$node['image'] = $logo;
		} else {
			$fallback = $this->attachment_image_object( (int) ( $seo['default_og_image'] ?? 0 ) );
			if ( null !== $fallback ) {
				$node['image'] = $fallback;
			}
		}

		// Address.
		$address = $this->postal_address( $business );
		if ( array() !== $address ) {
			$node['address'] = $address;
		}

		// Geo.
		$lat = (string) ( $business['latitude'] ?? '' );
		$lng = (string) ( $business['longitude'] ?? '' );
		if ( is_numeric( $lat ) && is_numeric( $lng ) ) {
			$node['geo'] = array(
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lat,
				'longitude' => (float) $lng,
			);
		}

		// Opening hours.
		$hours = $this->opening_hours( $pickup );
		if ( array() !== $hours ) {
			$node['openingHoursSpecification'] = $hours;
		}

		// sameAs (social profiles).
		$same_as   = array();
		$instagram = trim( (string) ( $business['instagram'] ?? '' ) );
		if ( '' !== $instagram ) {
			$same_as[] = $instagram;
		}
		if ( array() !== $same_as ) {
			$node['sameAs'] = $same_as;
		}

		return $node;
	}

	/**
	 * WebSite node with a site SearchAction.
	 *
	 * @param array<string,mixed> $business
	 * @return array<string,mixed>
	 */
	private function website_node( array $business ): array {
		return array(
			'@type'           => 'WebSite',
			'@id'             => $this->id( '#website' ),
			'url'             => home_url( '/' ),
			'name'            => (string) ( $business['name'] ?? get_bloginfo( 'name' ) ),
			'inLanguage'      => $this->language(),
			'publisher'       => array( '@id' => $this->id( '#cafe' ) ),
			'potentialAction' => array(
				array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => home_url( '/?s={search_term_string}' ),
					),
					'query-input' => 'required name=search_term_string',
				),
			),
		);
	}

	/**
	 * WebPage node for the current singular object.
	 *
	 * @return array<string,mixed>
	 */
	private function webpage_node( \WP_Post $post ): array {
		$url  = (string) get_permalink( $post );
		$node = array(
			'@type'      => 'WebPage',
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => $this->document_title(),
			'isPartOf'   => array( '@id' => $this->id( '#website' ) ),
			'inLanguage' => $this->language(),
			'about'      => array( '@id' => $this->id( '#cafe' ) ),
		);

		$desc = $this->post_description( $post );
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}

		$image = $this->post_image_object( $post );
		if ( null !== $image ) {
			$node['primaryImageOfPage'] = $image;
		}

		$node['datePublished'] = $this->iso_date( $post->post_date_gmt );
		$node['dateModified']  = $this->iso_date( $post->post_modified_gmt );

		if ( ! is_front_page() ) {
			$node['breadcrumb'] = array( '@id' => $this->id_current( '#breadcrumb' ) );
		}

		return $node;
	}

	/**
	 * CollectionPage node for archives/search.
	 *
	 * @return array<string,mixed>
	 */
	private function collection_node(): array {
		$url = $this->current_url();
		return array(
			'@type'      => 'CollectionPage',
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => $this->document_title(),
			'isPartOf'   => array( '@id' => $this->id( '#website' ) ),
			'inLanguage' => $this->language(),
			'about'      => array( '@id' => $this->id( '#cafe' ) ),
			'breadcrumb' => array( '@id' => $this->id_current( '#breadcrumb' ) ),
		);
	}

	/**
	 * Article node for single posts.
	 *
	 * @param array<string,mixed> $seo
	 * @return array<string,mixed>
	 */
	private function article_node( \WP_Post $post, array $seo ): array {
		$url  = (string) get_permalink( $post );
		$node = array(
			'@type'            => 'Article',
			'@id'              => $url . '#article',
			'headline'         => $this->truncate( $this->document_title(), 110 ),
			'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			'isPartOf'         => array( '@id' => $url . '#webpage' ),
			'datePublished'    => $this->iso_date( $post->post_date_gmt ),
			'dateModified'     => $this->iso_date( $post->post_modified_gmt ),
			'publisher'        => array( '@id' => $this->id( '#cafe' ) ),
			'inLanguage'       => $this->language(),
		);

		$author = get_the_author_meta( 'display_name', (int) $post->post_author );
		if ( is_string( $author ) && '' !== $author ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => $author,
			);
		}

		$desc = $this->post_description( $post );
		if ( '' !== $desc ) {
			$node['description'] = $desc;
		}

		$image = $this->post_image_object( $post );
		if ( null === $image ) {
			$image = $this->attachment_image_object( (int) ( $seo['default_og_image'] ?? 0 ) );
		}
		if ( null !== $image ) {
			$node['image'] = $image;
		}

		return $node;
	}

	/**
	 * Product node for a single WooCommerce product.
	 *
	 * @param array<string,mixed> $seo
	 * @return array<string,mixed>|null
	 */
	private function product_node( \WP_Post $post, array $seo ): ?array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $post );
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$url  = (string) get_permalink( $post );
		$node = array(
			'@type' => 'Product',
			'@id'   => $url . '#product',
			'name'  => (string) $product->get_name(),
			'url'   => $url,
			'brand' => array(
				'@type' => 'Brand',
				'name'  => (string) ( Options::business()['name'] ?? get_bloginfo( 'name' ) ),
			),
		);

		$desc = $this->normalize( (string) $product->get_short_description() );
		if ( '' === $desc ) {
			$desc = $this->normalize( (string) $product->get_description() );
		}
		if ( '' !== $desc ) {
			$node['description'] = $this->truncate( $desc, 300 );
		}

		$sku = (string) $product->get_sku();
		if ( '' !== $sku ) {
			$node['sku'] = $sku;
		}

		// Image: featured, else gallery, else default.
		$image = $this->post_image_object( $post );
		if ( null === $image ) {
			$gallery = $product->get_gallery_image_ids();
			if ( is_array( $gallery ) && ! empty( $gallery ) ) {
				$image = $this->attachment_image_object( (int) $gallery[0] );
			}
		}
		if ( null === $image ) {
			$image = $this->attachment_image_object( (int) ( $seo['default_og_image'] ?? 0 ) );
		}
		if ( null !== $image ) {
			$node['image'] = $image;
		}

		// Offer.
		$price = wc_get_price_to_display( $product );
		if ( '' !== (string) $price ) {
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'MXN';
			$offer    = array(
				'@type'         => 'Offer',
				'url'           => $url,
				'price'         => (string) wc_format_decimal( $price, wc_get_price_decimals() ),
				'priceCurrency' => (string) $currency,
				'availability'  => $product->is_in_stock()
					? 'https://schema.org/InStock'
					: 'https://schema.org/OutOfStock',
				'seller'        => array( '@id' => $this->id( '#cafe' ) ),
			);

			$node['offers'] = $offer;
		}

		return $node;
	}

	/**
	 * BreadcrumbList node derived from the current request path.
	 *
	 * @return array<string,mixed>
	 */
	private function breadcrumb_node(): array {
		$items = $this->breadcrumb_items();
		$list  = array();
		$pos   = 1;

		foreach ( $items as $item ) {
			$entry = array(
				'@type'    => 'ListItem',
				'position' => $pos,
				'name'     => $item['name'],
			);
			if ( '' !== $item['url'] ) {
				$entry['item'] = $item['url'];
			}
			$list[] = $entry;
			++$pos;
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $this->id_current( '#breadcrumb' ),
			'itemListElement' => $list,
		);
	}

	/**
	 * Build an ordered breadcrumb trail for the current view.
	 *
	 * @return array<int,array{name:string,url:string}>
	 */
	private function breadcrumb_items(): array {
		$items = array(
			array(
				'name' => __( 'Inicio', 'haramara-core' ),
				'url'  => (string) home_url( '/' ),
			),
		);

		$object = get_queried_object();

		if ( is_singular( 'product' ) && function_exists( 'wc_get_page_id' ) ) {
			$shop_id = (int) wc_get_page_id( 'shop' );
			if ( $shop_id > 0 ) {
				$items[] = array(
					'name' => (string) get_the_title( $shop_id ),
					'url'  => (string) get_permalink( $shop_id ),
				);
			}
			if ( $object instanceof \WP_Post ) {
				$items[] = array(
					'name' => (string) get_the_title( $object ),
					'url'  => (string) get_permalink( $object ),
				);
			}
			return $items;
		}

		if ( is_singular() && $object instanceof \WP_Post ) {
			foreach ( $this->ancestors( $object ) as $ancestor_id ) {
				$items[] = array(
					'name' => (string) get_the_title( $ancestor_id ),
					'url'  => (string) get_permalink( $ancestor_id ),
				);
			}
			$items[] = array(
				'name' => (string) get_the_title( $object ),
				'url'  => (string) get_permalink( $object ),
			);
			return $items;
		}

		if ( ( is_category() || is_tag() || is_tax() ) && $object instanceof \WP_Term ) {
			$link    = get_term_link( $object );
			$items[] = array(
				'name' => (string) $object->name,
				'url'  => is_string( $link ) ? $link : '',
			);
			return $items;
		}

		if ( is_post_type_archive() ) {
			$items[] = array(
				'name' => (string) post_type_archive_title( '', false ),
				'url'  => $this->current_url(),
			);
			return $items;
		}

		if ( is_search() ) {
			$items[] = array(
				/* translators: %s: search query. */
				'name' => sprintf( __( 'Resultados para "%s"', 'haramara-core' ), get_search_query() ),
				'url'  => '',
			);
			return $items;
		}

		return $items;
	}

	/**
	 * Post ancestor ids in root→parent order.
	 *
	 * @return int[]
	 */
	private function ancestors( \WP_Post $post ): array {
		$ancestors = get_post_ancestors( $post );
		return array_reverse( array_map( 'intval', $ancestors ) );
	}

	/* ---------------------------------------------------------------------- */
	/* Building blocks */
	/* ---------------------------------------------------------------------- */

	/**
	 * A PostalAddress array from business config.
	 *
	 * @param array<string,mixed> $business
	 * @return array<string,mixed>
	 */
	private function postal_address( array $business ): array {
		$map = array(
			'streetAddress'   => (string) ( $business['street'] ?? '' ),
			'addressLocality' => (string) ( $business['locality'] ?? '' ),
			'addressRegion'   => (string) ( $business['region'] ?? '' ),
			'postalCode'      => (string) ( $business['postal_code'] ?? '' ),
			'addressCountry'  => (string) ( $business['country'] ?? '' ),
		);
		$map = array_filter( $map, static fn( $v ) => '' !== trim( (string) $v ) );

		if ( array() === $map ) {
			return array();
		}
		return array_merge( array( '@type' => 'PostalAddress' ), $map );
	}

	/**
	 * Build openingHoursSpecification from pickup config.
	 *
	 * Maps the stored day indices (0=Sun … 6=Sat) to schema.org day URIs.
	 *
	 * @param array<string,mixed> $pickup
	 * @return array<int,array<string,mixed>>
	 */
	private function opening_hours( array $pickup ): array {
		$day_uris = array(
			0 => 'https://schema.org/Sunday',
			1 => 'https://schema.org/Monday',
			2 => 'https://schema.org/Tuesday',
			3 => 'https://schema.org/Wednesday',
			4 => 'https://schema.org/Thursday',
			5 => 'https://schema.org/Friday',
			6 => 'https://schema.org/Saturday',
		);

		$days = array_values( array_unique( array_map( 'intval', (array) ( $pickup['open_days'] ?? array() ) ) ) );
		sort( $days );

		$open  = (string) ( $pickup['open_time'] ?? '' );
		$close = (string) ( $pickup['close_time'] ?? '' );

		if ( array() === $days || ! preg_match( '/^\d{2}:\d{2}$/', $open ) || ! preg_match( '/^\d{2}:\d{2}$/', $close ) ) {
			return array();
		}

		$day_of_week = array();
		foreach ( $days as $index ) {
			if ( isset( $day_uris[ $index ] ) ) {
				$day_of_week[] = $day_uris[ $index ];
			}
		}

		if ( array() === $day_of_week ) {
			return array();
		}

		return array(
			array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $day_of_week,
				'opens'     => $open,
				'closes'    => $close,
			),
		);
	}

	/**
	 * An ImageObject for an attachment id, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	private function attachment_image_object( int $attachment_id ): ?array {
		if ( $attachment_id <= 0 ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $attachment_id, 'full' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return null;
		}
		$node = array(
			'@type' => 'ImageObject',
			'@id'   => $this->id( '#image-' . $attachment_id ),
			'url'   => (string) $src[0],
		);
		if ( isset( $src[1], $src[2] ) ) {
			$node['width']  = (int) $src[1];
			$node['height'] = (int) $src[2];
		}
		$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' !== trim( $alt ) ) {
			$node['caption'] = $this->normalize( $alt );
		}
		return $node;
	}

	/**
	 * ImageObject for a post's featured image, or null.
	 *
	 * @return array<string,mixed>|null
	 */
	private function post_image_object( \WP_Post $post ): ?array {
		if ( ! has_post_thumbnail( $post ) ) {
			return null;
		}
		return $this->attachment_image_object( (int) get_post_thumbnail_id( $post ) );
	}

	/**
	 * A clean description for a post/product WebPage node.
	 */
	private function post_description( \WP_Post $post ): string {
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post );
			if ( $product instanceof \WC_Product ) {
				$short = $this->normalize( (string) $product->get_short_description() );
				if ( '' !== $short ) {
					return $this->truncate( $short, 300 );
				}
			}
		}
		$raw = has_excerpt( $post ) ? (string) get_the_excerpt( $post ) : (string) $post->post_content;
		return $this->truncate( $this->normalize( $raw ), 300 );
	}

	/* ---------------------------------------------------------------------- */
	/* Helpers */
	/* ---------------------------------------------------------------------- */

	/** A home-anchored @id URL for a fragment. */
	private function id( string $fragment ): string {
		return (string) home_url( '/' ) . $fragment;
	}

	/** A current-URL-anchored @id for a fragment. */
	private function id_current( string $fragment ): string {
		return $this->current_url() . $fragment;
	}

	/** The current request URL. */
	private function current_url(): string {
		if ( is_singular() ) {
			$object = get_queried_object();
			if ( $object instanceof \WP_Post ) {
				return (string) get_permalink( $object );
			}
		}
		if ( ( is_category() || is_tag() || is_tax() ) ) {
			$object = get_queried_object();
			if ( $object instanceof \WP_Term ) {
				$link = get_term_link( $object );
				if ( is_string( $link ) ) {
					return $link;
				}
			}
		}
		global $wp;
		if ( $wp instanceof \WP && isset( $wp->request ) ) {
			return (string) home_url( add_query_arg( array(), $wp->request ) );
		}
		return (string) home_url( '/' );
	}

	/** The document title for the current view. */
	private function document_title(): string {
		if ( function_exists( 'wp_get_document_title' ) ) {
			$title = wp_get_document_title();
			if ( is_string( $title ) && '' !== trim( $title ) ) {
				return $title;
			}
		}
		return (string) get_bloginfo( 'name' );
	}

	/** The site language as a BCP-47 tag. */
	private function language(): string {
		$locale = get_locale();
		return '' !== $locale ? str_replace( '_', '-', $locale ) : 'es-MX';
	}

	/** GMT datetime string → ISO 8601 with offset, or empty. */
	private function iso_date( string $gmt ): string {
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return '';
		}
		$ts = strtotime( $gmt . ' UTC' );
		if ( false === $ts ) {
			return '';
		}
		return (string) wp_date( 'c', $ts );
	}

	/** Collapse markup/shortcodes/whitespace to a clean single line. */
	private function normalize( string $text ): string {
		$text = strip_shortcodes( $text );
		$text = wp_strip_all_tags( $text );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/** Truncate on a word boundary to a character budget. */
	private function truncate( string $text, int $limit ): string {
		if ( '' === $text || mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		$slice = mb_substr( $text, 0, $limit - 1 );
		$space = mb_strrpos( $slice, ' ' );
		if ( false !== $space && $space > 0 ) {
			$slice = mb_substr( $slice, 0, $space );
		}
		return rtrim( $slice ) . '…';
	}

	/**
	 * Drop null/empty nodes from the graph.
	 *
	 * @param array<int,array<string,mixed>|null> $nodes
	 * @return array<int,array<string,mixed>>
	 */
	private function prune( array $nodes ): array {
		return array_values( array_filter( $nodes, static fn( $n ) => is_array( $n ) && array() !== $n ) );
	}
}
