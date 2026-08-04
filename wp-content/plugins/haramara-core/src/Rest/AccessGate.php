<?php
/**
 * Restricted Site Access exemptions for the headless API surfaces.
 *
 * While the site is gated pre-launch (10up Restricted Site Access), anonymous
 * requests — including /wp-json — are redirected to wp-login, which would break
 * the mobile app and POS. This gate keeps two namespaces reachable:
 *
 * - `haramara/v1` — every route carries its own auth (order keys, capability
 *   checks), so opening it changes nothing about security.
 * - `wc/store` — the WooCommerce Store API (cart/checkout for the app). Its
 *   exemption can additionally be locked to clients sending the shared
 *   `X-Haramara-App` header by defining the HARAMARA_APP_KEY constant in
 *   wp-config.php; leave the constant undefined for open access (local dev, or
 *   after launch when RSA is deactivated anyway).
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Rest;

use Haramara\Core\Contracts\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The RSA exemption filter.
 */
final class AccessGate implements Bootable {

	/**
	 * Hook into Restricted Site Access.
	 */
	/** Browser origins the app previews run on (Expo web dev servers). */
	private const DEV_ORIGINS = array(
		'http://localhost:8081',
		'http://localhost:8082',
		'http://localhost:8083',
		'http://localhost:8084',
		'http://localhost:19006',
		'http://127.0.0.1:8081',
		'http://127.0.0.1:8082',
		'http://127.0.0.1:8083',
		'http://127.0.0.1:8084',
	);

	/**
	 * Hook into Restricted Site Access and the CORS origin allowlist.
	 */
	public function boot(): void {
		add_filter( 'restricted_site_access_is_restricted', array( $this, 'allow_api_namespaces' ), 10, 2 );
		add_filter( 'allowed_http_origins', array( $this, 'allow_app_origins' ) );
	}

	/**
	 * The WooCommerce Store API only answers CORS for origins WordPress
	 * explicitly allows (unlike core REST, which echoes any origin) — without
	 * this, a browser build of the app loads haramara/v1 fine but every
	 * wc/store call is blocked. Registers the Expo-web dev origins plus any
	 * comma-separated origins in a HARAMARA_APP_ORIGINS constant (for a future
	 * hosted web storefront).
	 *
	 * @param string[] $origins Origins WordPress already allows.
	 * @return string[]
	 */
	public function allow_app_origins( $origins ) {
		$origins = array_merge( (array) $origins, self::DEV_ORIGINS );

		if ( defined( 'HARAMARA_APP_ORIGINS' ) && '' !== (string) HARAMARA_APP_ORIGINS ) {
			foreach ( explode( ',', (string) HARAMARA_APP_ORIGINS ) as $origin ) {
				$origin = trim( $origin );
				if ( '' !== $origin ) {
					$origins[] = $origin;
				}
			}
		}

		return array_values( array_unique( $origins ) );
	}

	/**
	 * Let API-namespace requests through the launch gate.
	 *
	 * @param bool   $is_restricted Whether the current request is restricted.
	 * @param object $wp            The WP request object (unused).
	 * @return bool
	 */
	public function allow_api_namespaces( $is_restricted, $wp = null ) {
		unset( $wp );

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against fixed needles only.
		if ( ! is_string( $uri ) || '' === $uri ) {
			return $is_restricted;
		}
		$uri = rawurldecode( $uri );

		if ( $this->matches_namespace( $uri, 'haramara/v1' ) ) {
			return false;
		}

		if ( $this->matches_namespace( $uri, 'wc/store' ) && $this->app_header_ok() ) {
			return false;
		}

		return $is_restricted;
	}

	/**
	 * Match both pretty (/wp-json/ns/) and plain (?rest_route=/ns/) REST URLs.
	 *
	 * @param string $uri Decoded request URI.
	 * @param string $ns  REST namespace without slashes (e.g. 'wc/store').
	 */
	private function matches_namespace( string $uri, string $ns ): bool {
		return str_contains( $uri, '/wp-json/' . $ns . '/' )
			|| str_contains( $uri, 'rest_route=/' . $ns . '/' );
	}

	/**
	 * Pre-launch hardening: when HARAMARA_APP_KEY is defined, the Store API
	 * exemption only applies to requests presenting the matching header, so
	 * crawlers can't reach checkout before launch.
	 */
	private function app_header_ok(): bool {
		if ( ! defined( 'HARAMARA_APP_KEY' ) || '' === (string) HARAMARA_APP_KEY ) {
			return true;
		}

		$sent = isset( $_SERVER['HTTP_X_HARAMARA_APP'] ) ? wp_unslash( $_SERVER['HTTP_X_HARAMARA_APP'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- constant-time compared below.

		return is_string( $sent ) && '' !== $sent && hash_equals( (string) HARAMARA_APP_KEY, $sent );
	}
}
