<?php
/**
 * Native bilingual layer (es default · en under /en/).
 *
 * The site is authored in Spanish. English is served from the same content:
 * requests under /en/ are detected before WordPress routes, the prefix is
 * stripped so normal routing resolves, the locale is forced to en_US (which
 * flips every WooCommerce/core string via language packs), and the rendered
 * page passes through a single strtr() dictionary (data/translations.php)
 * that carries every editorial string. hreflang alternates are emitted on
 * both languages.
 *
 * Chosen over Polylang deliberately: the editorial surface here is pattern/
 * template-driven FSE, where the free multilingual plugins are weakest, and
 * the project standard is native capability over plugins.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\I18n;

use Haramara\Core\Contracts\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SiteLanguage implements Bootable {

	public const PREFIX = 'en';

	/** Current front-end language, 'es' or 'en'. */
	private static string $lang = 'es';

	/** Request path with the /en prefix already stripped (query string removed). */
	private static string $path = '/';

	/** @var array<string,string>|null */
	private static ?array $dictionary = null;

	public function boot(): void {
		if ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw URI needed for routing; used for prefix match only.

		if ( preg_match( '#^/' . self::PREFIX . '(/|$|\?)#', $uri ) ) {
			self::$lang = 'en';

			$stripped = substr( $uri, strlen( self::PREFIX ) + 1 );
			if ( '' === $stripped || '?' === $stripped[0] ) {
				$stripped = '/' . $stripped;
			}
			$_SERVER['REQUEST_URI'] = $stripped;

			add_filter( 'locale', array( $this, 'force_english_locale' ) );
			add_filter( 'determine_locale', array( $this, 'force_english_locale' ) );
			add_filter( 'home_url', array( $this, 'prefix_front_urls' ), 10, 2 );
			// The canonical redirect compares the prefixed canonical against the
			// stripped request and would loop; alternates make intent explicit.
			add_filter( 'redirect_canonical', '__return_false' );
			add_filter( 'body_class', array( $this, 'body_class' ) );
			add_action( 'template_redirect', array( $this, 'start_translation_buffer' ), 0 );
		}

		self::$path = strtok( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path only, escaped on output.

		add_action( 'wp_head', array( $this, 'hreflang_alternates' ), 2 );
	}

	/** Current language code for templates and patterns. */
	public static function current(): string {
		return self::$lang;
	}

	/** The language-neutral request path (no /en prefix, no query). */
	public static function path(): string {
		return '' !== self::$path ? self::$path : '/';
	}

	/** Absolute URL of the current page in the given language. */
	public static function url_for( string $lang ): string {
		$base = untrailingslashit( (string) get_option( 'home' ) );
		$path = self::path();
		return 'en' === $lang ? $base . '/' . self::PREFIX . $path : $base . $path;
	}

	public function force_english_locale(): string {
		return 'en_US';
	}

	/**
	 * Keep every front-end URL inside the /en/ tree; leave admin, API and
	 * asset URLs untouched.
	 *
	 * @param string $url  Full URL produced by home_url().
	 * @param string $path Requested path.
	 */
	public function prefix_front_urls( string $url, string $path ): string {
		if ( preg_match( '#(wp-admin|wp-json|wp-login|wp-content|wp-includes|\.xml|\.php)#', $url ) ) {
			return $url;
		}

		$base = untrailingslashit( (string) get_option( 'home' ) );
		if ( 0 !== strpos( $url, $base ) ) {
			return $url;
		}

		$rest = substr( $url, strlen( $base ) );
		if ( '/' . self::PREFIX === $rest || 0 === strpos( $rest, '/' . self::PREFIX . '/' ) ) {
			return $url;
		}

		return $base . '/' . self::PREFIX . ( '' !== $rest ? $rest : '/' );
	}

	/**
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function body_class( array $classes ): array {
		$classes[] = 'lang-en';
		return $classes;
	}

	/**
	 * Translate the fully rendered page in one pass. strtr() prefers the
	 * longest key at each position, so whole sentences win over fragments.
	 */
	public function start_translation_buffer(): void {
		$map = self::dictionary();
		if ( array() === $map ) {
			return;
		}
		ob_start(
			static function ( string $html ) use ( $map ): string {
				return strtr( $html, $map );
			}
		);
	}

	/** @return array<string,string> */
	private static function dictionary(): array {
		if ( null === self::$dictionary ) {
			$file             = dirname( __DIR__, 2 ) . '/data/translations.php';
			$map              = file_exists( $file ) ? require $file : array();
			self::$dictionary = is_array( $map ) ? $map : array();
		}
		return self::$dictionary;
	}

	/**
	 * hreflang alternates on both language versions of every front-end page.
	 */
	public function hreflang_alternates(): void {
		$es = self::url_for( 'es' );
		$en = self::url_for( 'en' );

		printf( '<link rel="alternate" hreflang="es-MX" href="%s" />' . "\n", esc_url( $es ) );
		printf( '<link rel="alternate" hreflang="en" href="%s" />' . "\n", esc_url( $en ) );
		printf( '<link rel="alternate" hreflang="x-default" href="%s" />' . "\n", esc_url( $es ) );
	}
}
