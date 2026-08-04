<?php
/**
 * Front-end meta tag emitter.
 *
 * Prints the document-level SEO surface — description, Open Graph, Twitter Cards,
 * canonical, and robots directives — into `wp_head`. Every value is derived from
 * the queried object at request time and from {@see Options}; nothing about the
 * business is hardcoded here.
 *
 * The emitter is a good citizen: if a dedicated SEO plugin (Yoast, Rank Math, or
 * SEOPress) is active it bails out entirely so the two never fight over the head
 * and emit duplicate tags.
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

final class MetaTags implements Bootable {

	/**
	 * Register the head emitter early so tags land near the top of <head>,
	 * before most theme/plugin output.
	 */
	public function boot(): void {
		add_action( 'wp_head', array( $this, 'render' ), 1 );
	}

	/**
	 * Emit the full meta surface for the current view.
	 */
	public function render(): void {
		if ( $this->seo_plugin_active() ) {
			return;
		}

		$business = Options::business();
		$seo      = Options::seo();

		$queried_object = $this->queried_object();
		$type           = $this->og_type();
		$title          = $this->document_title();
		$desc           = $this->description( $queried_object, $business );
		$url            = $this->canonical_url();
		$locale         = 'es_MX';
		$site           = (string) ( $business['name'] ?? get_bloginfo( 'name' ) );
		$image          = $this->image( $queried_object, $seo );
		$twitter        = $this->twitter_handle( $seo );

		echo "\n<!-- Haramara SEO: meta -->\n";

		// Robots.
		printf(
			"<meta name=\"robots\" content=\"%s\">\n",
			esc_attr( $this->robots() )
		);

		// Description.
		if ( '' !== $desc ) {
			printf(
				"<meta name=\"description\" content=\"%s\">\n",
				esc_attr( $desc )
			);
		}

		// Canonical.
		if ( '' !== $url ) {
			printf(
				"<link rel=\"canonical\" href=\"%s\">\n",
				esc_url( $url )
			);
		}

		// Open Graph.
		printf( "<meta property=\"og:type\" content=\"%s\">\n", esc_attr( $type ) );
		printf( "<meta property=\"og:title\" content=\"%s\">\n", esc_attr( $title ) );
		if ( '' !== $desc ) {
			printf( "<meta property=\"og:description\" content=\"%s\">\n", esc_attr( $desc ) );
		}
		if ( '' !== $url ) {
			printf( "<meta property=\"og:url\" content=\"%s\">\n", esc_url( $url ) );
		}
		printf( "<meta property=\"og:site_name\" content=\"%s\">\n", esc_attr( $site ) );
		printf( "<meta property=\"og:locale\" content=\"%s\">\n", esc_attr( $locale ) );

		if ( null !== $image ) {
			printf( "<meta property=\"og:image\" content=\"%s\">\n", esc_url( $image['url'] ) );
			if ( $image['width'] > 0 ) {
				printf( "<meta property=\"og:image:width\" content=\"%d\">\n", (int) $image['width'] );
			}
			if ( $image['height'] > 0 ) {
				printf( "<meta property=\"og:image:height\" content=\"%d\">\n", (int) $image['height'] );
			}
			if ( '' !== $image['alt'] ) {
				printf( "<meta property=\"og:image:alt\" content=\"%s\">\n", esc_attr( $image['alt'] ) );
			}
		}

		// Product-specific Open Graph pricing.
		if ( 'product' === $type ) {
			$price = $this->product_price( $queried_object );
			if ( null !== $price ) {
				printf( "<meta property=\"product:price:amount\" content=\"%s\">\n", esc_attr( $price['amount'] ) );
				printf( "<meta property=\"product:price:currency\" content=\"%s\">\n", esc_attr( $price['currency'] ) );
				printf( "<meta property=\"og:availability\" content=\"%s\">\n", esc_attr( $price['availability'] ) );
			}
		}

		// Twitter Cards.
		printf( "<meta name=\"twitter:card\" content=\"%s\">\n", 'summary_large_image' );
		if ( '' !== $twitter ) {
			printf( "<meta name=\"twitter:site\" content=\"%s\">\n", esc_attr( $twitter ) );
			printf( "<meta name=\"twitter:creator\" content=\"%s\">\n", esc_attr( $twitter ) );
		}
		printf( "<meta name=\"twitter:title\" content=\"%s\">\n", esc_attr( $title ) );
		if ( '' !== $desc ) {
			printf( "<meta name=\"twitter:description\" content=\"%s\">\n", esc_attr( $desc ) );
		}
		if ( null !== $image ) {
			printf( "<meta name=\"twitter:image\" content=\"%s\">\n", esc_url( $image['url'] ) );
			if ( '' !== $image['alt'] ) {
				printf( "<meta name=\"twitter:image:alt\" content=\"%s\">\n", esc_attr( $image['alt'] ) );
			}
		}

		echo "<!-- /Haramara SEO: meta -->\n";
	}

	/* ---------------------------------------------------------------------- */
	/* Detection */
	/* ---------------------------------------------------------------------- */

	/**
	 * Detect a dedicated SEO plugin so we can defer to it and avoid duplicates.
	 */
	private function seo_plugin_active(): bool {
		// Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend' ) || function_exists( 'wpseo_init' ) ) {
			return true;
		}
		// Rank Math.
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return true;
		}
		// SEOPress.
		if ( defined( 'SEOPRESS_VERSION' ) || function_exists( 'seopress_init' ) ) {
			return true;
		}
		// The SEO Framework.
		if ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'the_seo_framework' ) ) {
			return true;
		}

		/**
		 * Allow a site to force-disable Haramara meta output (e.g. another plugin).
		 *
		 * @param bool $active Whether a competing SEO solution is active.
		 */
		return (bool) apply_filters( 'haramara_seo_meta_suppressed', false );
	}

	/* ---------------------------------------------------------------------- */
	/* Value derivation */
	/* ---------------------------------------------------------------------- */

	/**
	 * The current queried object when it is a WP_Post; null otherwise.
	 */
	private function queried_object(): ?\WP_Post {
		if ( ! is_singular() ) {
			return null;
		}
		$queried_object = get_queried_object();
		return $queried_object instanceof \WP_Post ? $queried_object : null;
	}

	/**
	 * Map the current view to an Open Graph object type.
	 */
	private function og_type(): string {
		if ( is_singular( 'product' ) ) {
			return 'product';
		}
		if ( is_singular( 'post' ) ) {
			return 'article';
		}
		return 'website';
	}

	/**
	 * The document title for social sharing.
	 */
	private function document_title(): string {
		if ( function_exists( 'wp_get_document_title' ) ) {
			$title = wp_get_document_title();
			if ( is_string( $title ) && '' !== trim( $title ) ) {
				return $title;
			}
		}
		return (string) get_bloginfo( 'name' );
	}

	/**
	 * Derive a meta description from the queried object, falling back to the
	 * business tagline.
	 *
	 * @param array<string,mixed> $business
	 */
	private function description( ?\WP_Post $queried_object, array $business ): string {
		$raw = '';

		if ( $queried_object instanceof \WP_Post ) {
			// WooCommerce product short description takes priority.
			if ( 'product' === $queried_object->post_type && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $queried_object );
				if ( $product instanceof \WC_Product ) {
					$short = (string) $product->get_short_description();
					if ( '' === trim( wp_strip_all_tags( $short ) ) ) {
						$short = (string) $product->get_description();
					}
					$raw = $short;
				}
			}

			if ( '' === trim( wp_strip_all_tags( $raw ) ) ) {
				$raw = has_excerpt( $queried_object ) ? (string) get_the_excerpt( $queried_object ) : (string) $queried_object->post_content;
			}
		} elseif ( is_home() || is_front_page() ) {
			$raw = (string) get_bloginfo( 'description' );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$raw = (string) $term->description;
			}
		}

		$clean = $this->normalize( $raw );

		if ( '' === $clean ) {
			$clean = $this->normalize( (string) ( $business['tagline'] ?? get_bloginfo( 'description' ) ) );
		}

		return $this->truncate( $clean, 160 );
	}

	/**
	 * Collapse markup/shortcodes/whitespace into a clean single-line string.
	 */
	private function normalize( string $text ): string {
		$text = strip_shortcodes( $text );
		$text = wp_strip_all_tags( $text );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/**
	 * Truncate to a character budget on a word boundary.
	 */
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
	 * Resolve the canonical URL for the current request.
	 */
	private function canonical_url(): string {
		if ( is_singular() ) {
			$url = wp_get_canonical_url();
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		if ( is_front_page() || is_home() ) {
			return (string) home_url( '/' );
		}
		if ( ( is_category() || is_tag() || is_tax() ) ) {
			$term = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					return $link;
				}
			}
		}
		if ( is_post_type_archive() ) {
			$link = get_post_type_archive_link( (string) get_query_var( 'post_type' ) );
			if ( is_string( $link ) ) {
				return $link;
			}
		}

		global $wp;
		if ( $wp instanceof \WP && isset( $wp->request ) ) {
			return (string) home_url( add_query_arg( array(), $wp->request ) );
		}
		return '';
	}

	/**
	 * Choose an OG/Twitter image: featured image, else the configured default.
	 *
	 * @param array<string,mixed> $seo
	 * @return array{url:string,width:int,height:int,alt:string}|null
	 */
	private function image( ?\WP_Post $queried_object, array $seo ): ?array {
		$attachment_id = 0;

		if ( $queried_object instanceof \WP_Post && has_post_thumbnail( $queried_object ) ) {
			$attachment_id = (int) get_post_thumbnail_id( $queried_object );
		}

		if ( $attachment_id <= 0 ) {
			$attachment_id = (int) ( $seo['default_og_image'] ?? 0 );
		}

		if ( $attachment_id <= 0 ) {
			return null;
		}

		$src = wp_get_attachment_image_src( $attachment_id, 'full' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return null;
		}

		$alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' === trim( $alt ) && $queried_object instanceof \WP_Post ) {
			$alt = (string) get_the_title( $queried_object );
		}

		return array(
			'url'    => (string) $src[0],
			'width'  => isset( $src[1] ) ? (int) $src[1] : 0,
			'height' => isset( $src[2] ) ? (int) $src[2] : 0,
			'alt'    => $this->normalize( $alt ),
		);
	}

	/**
	 * The Twitter handle from SEO options, normalized to an @-prefixed value.
	 *
	 * @param array<string,mixed> $seo
	 */
	private function twitter_handle( array $seo ): string {
		$handle = trim( (string) ( $seo['twitter_handle'] ?? '' ) );
		if ( '' === $handle ) {
			return '';
		}
		return '@' . ltrim( $handle, '@' );
	}

	/**
	 * Robots directive for the current view.
	 */
	private function robots(): string {
		$index = true;

		if ( is_search() || is_404() ) {
			$index = false;
		}
		if ( is_paged() && ! is_singular() ) {
			// Keep paged archives crawlable but signal the paginated nature.
			$index = true;
		}

		$directives = $index
			? array( 'index', 'follow', 'max-image-preview:large' )
			: array( 'noindex', 'follow' );

		/**
		 * Filter the robots directives array for the current view.
		 *
		 * @param string[] $directives
		 */
		$directives = (array) apply_filters( 'haramara_seo_robots', $directives );

		return implode( ', ', array_map( 'strval', $directives ) );
	}

	/**
	 * Product price data for Open Graph, guarded for WooCommerce.
	 *
	 * @return array{amount:string,currency:string,availability:string}|null
	 */
	private function product_price( ?\WP_Post $queried_object ): ?array {
		if ( ! $queried_object instanceof \WP_Post || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $queried_object );
		if ( ! $product instanceof \WC_Product ) {
			return null;
		}

		$amount = wc_get_price_to_display( $product );
		if ( '' === (string) $amount ) {
			return null;
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'MXN';

		return array(
			'amount'       => (string) wc_format_decimal( $amount, wc_get_price_decimals() ),
			'currency'     => (string) $currency,
			'availability' => $product->is_in_stock() ? 'instock' : 'outofstock',
		);
	}
}
