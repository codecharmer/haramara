<?php
/**
 * Apple Wallet pass for the Lealtad Haramara card.
 *
 * Serves a signed .pkpass store card for a member token: the same QR the app
 * renders, plus the stamp counters, in a bundle iOS adds to Wallet. The pass
 * carries webServiceURL + authenticationToken, so devices register with
 * WalletWebService and refresh automatically after every stamp/redeem;
 * re-downloading also replaces the pass in place (same passTypeIdentifier +
 * serialNumber).
 *
 * Signing needs a Pass Type ID certificate + the Apple WWDR intermediate,
 * configured EXCLUSIVELY via wp-config constants (see config()) pointing at
 * PEM files OUTSIDE the plugin directory — deploys rsync --delete and chmod
 * 644 this tree, so secrets must never live here. Unconfigured installs
 * answer 503 and get_config reports wallet_pass=false so apps hide the
 * button. Setup runbook: docs/apple-wallet.md.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Loyalty;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and serves the signed .pkpass bundle.
 */
final class WalletPass implements Bootable {

	private const NS = 'haramara/v1';

	/** Download filename offered to the browser. */
	private const FILENAME = 'haramara-lealtad.pkpass';

	/** Image files every bundle must carry, relative to assets/wallet/. */
	private const IMAGES = array( 'icon.png', 'icon@2x.png', 'icon@3x.png', 'logo.png', 'logo@2x.png' );

	/**
	 * Hook the REST surface.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * GET /app/loyalty/wallet-pass — token-authenticated, like /app/loyalty/card.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NS,
			'/app/loyalty/wallet-pass',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_pass' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Token firmado de la tarjeta de lealtad.', 'haramara-core' ),
						'sanitize_callback' => static fn( $value ): string => sanitize_text_field( (string) $value ),
					),
				),
			)
		);
	}

	/**
	 * Whether this install can sign passes (constants, extensions, cert files).
	 * Cheap enough for get_config: three constant reads and two stat calls.
	 */
	public static function configured(): bool {
		return ! is_wp_error( self::config() );
	}

	/**
	 * Build, sign, and serve the pass.
	 *
	 * Token validation runs FIRST so unconfigured installs still answer
	 * 403/404 for bad tokens — the integration script relies on that.
	 *
	 * @param \WP_REST_Request $request Incoming request with the card token.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_pass( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$card = $this->members()->card_for_token( (string) $request['token'] );
		if ( is_wp_error( $card ) ) {
			return $card;
		}

		$config = self::config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$zip = $this->build_pkpass( $card, $config );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		$response = new \WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', 'application/vnd.apple.pkpass' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . self::FILENAME . '"' );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Content-Length', (string) strlen( $zip ) );

		// The REST server would JSON-encode (and corrupt) a binary body, so
		// emit the bytes ourselves. Just-in-time filter with an identity guard:
		// every other response — including our own WP_Error paths — is untouched.
		add_filter(
			'rest_pre_serve_request',
			static function ( bool $served, \WP_HTTP_Response $result ) use ( $zip, $response ): bool {
				if ( $result !== $response ) {
					return $served;
				}
				echo $zip; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- signed binary .pkpass bytes.
				return true;
			},
			10,
			2
		);

		return $response;
	}

	/**
	 * Build the signed .pkpass bytes for a card payload. Shared by the token
	 * route above and WalletWebService's authenticated re-serve.
	 *
	 * @param array<string,int|string> $card   Card payload (Members).
	 * @param array<string,string>     $config Signing config (see config()).
	 * @return string|\WP_Error
	 */
	public function build_pkpass( array $card, array $config ): string|\WP_Error {
		$files = $this->bundle_files( $card, $config );
		if ( is_wp_error( $files ) ) {
			return $files;
		}

		$manifest = wp_json_encode( array_map( 'sha1', $files ) );
		if ( ! is_string( $manifest ) ) {
			return self::build_error();
		}

		$signature = $this->sign_manifest( $manifest, $config );
		if ( is_wp_error( $signature ) ) {
			return $signature;
		}

		$files['manifest.json'] = $manifest;
		$files['signature']     = $signature;

		return $this->build_archive( $files );
	}

	// ---------------------------------------------------------------------
	// Configuration.
	// ---------------------------------------------------------------------

	/**
	 * Signing configuration from wp-config constants, or the 503 explaining
	 * what is missing. Never stored in the database, mirroring the Twilio
	 * secrets rule in Options::sms(). Public so WalletWebService reuses the
	 * same gates (and the same cert for its APNs pushes).
	 *
	 * @return array{pass_type_id:string,team_id:string,cert_path:string,cert_password:string,wwdr_path:string}|\WP_Error
	 */
	public static function config(): array|\WP_Error {
		$config = array(
			'pass_type_id'  => self::constant( 'HARAMARA_WALLET_PASS_TYPE_ID' ),
			'team_id'       => self::constant( 'HARAMARA_WALLET_TEAM_ID' ),
			'cert_path'     => self::constant( 'HARAMARA_WALLET_CERT_PATH' ),
			'cert_password' => self::constant( 'HARAMARA_WALLET_CERT_PASSWORD' ),
			'wwdr_path'     => self::constant( 'HARAMARA_WALLET_WWDR_PATH' ),
		);

		if ( '' === $config['pass_type_id'] || '' === $config['team_id'] || '' === $config['cert_path'] || '' === $config['wwdr_path'] ) {
			return new \WP_Error(
				'haramara_wallet_not_configured',
				__( 'El pase de Wallet no está configurado en este servidor.', 'haramara-core' ),
				array( 'status' => 503 )
			);
		}

		if ( ! class_exists( \ZipArchive::class ) || ! function_exists( 'openssl_pkcs7_sign' ) ) {
			return new \WP_Error(
				'haramara_wallet_unavailable',
				__( 'El servidor no puede generar pases de Wallet.', 'haramara-core' ),
				array( 'status' => 503 )
			);
		}

		if ( ! is_readable( $config['cert_path'] ) || ! is_readable( $config['wwdr_path'] ) ) {
			return new \WP_Error(
				'haramara_wallet_cert_unreadable',
				__( 'No se pudo leer el certificado del pase.', 'haramara-core' ),
				array( 'status' => 503 )
			);
		}

		return $config;
	}

	/**
	 * A constant's value when defined and non-empty, else ''.
	 *
	 * @param string $name Constant name.
	 */
	private static function constant( string $name ): string {
		return defined( $name ) ? (string) constant( $name ) : '';
	}

	// ---------------------------------------------------------------------
	// Bundle assembly.
	// ---------------------------------------------------------------------

	/**
	 * Bundle contents — pass.json plus the images, as filename => raw bytes.
	 * The images are read into memory once so the manifest hashes and the
	 * archive contain byte-identical content — any mismatch makes iOS reject
	 * the pass silently.
	 *
	 * @param array<string,int|string> $card   Card payload (Members::card_for_token()).
	 * @param array<string,string>     $config Signing config (see config()).
	 * @return array<string,string>|\WP_Error
	 */
	private function bundle_files( array $card, array $config ): array|\WP_Error {
		$pass_json = wp_json_encode( $this->pass_definition( $card, $config ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $pass_json ) ) {
			return self::build_error();
		}

		$files = array( 'pass.json' => $pass_json );
		$dir   = trailingslashit( dirname( __DIR__, 2 ) ) . 'assets/wallet/'; // Plugin root, src/Loyalty/ is two levels deep.

		foreach ( self::IMAGES as $image ) {
			$bytes = is_readable( $dir . $image )
				? file_get_contents( $dir . $image ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled plugin asset.
				: false;
			if ( false === $bytes ) {
				$this->log( 'Wallet pass asset missing or unreadable: ' . $image );
				return self::build_error();
			}
			$files[ $image ] = $bytes;
		}

		return $files;
	}

	/**
	 * The store-card definition. serialNumber is the stable member key, and the
	 * QR message is the full signed token — the POS scans it exactly like the
	 * in-app QR. Counters only (Sellos / Recompensas canjeadas): no surface may
	 * promise a reward tier (PRODUCT.md).
	 *
	 * @param array<string,int|string> $card   Card payload (Members::card_for_token()).
	 * @param array<string,string>     $config Signing config (see config()).
	 * @return array<string,mixed>
	 */
	private function pass_definition( array $card, array $config ): array {
		return array(
			'formatVersion'       => 1,
			'passTypeIdentifier'  => $config['pass_type_id'],
			'serialNumber'        => (string) $card['memberKey'],
			'teamIdentifier'      => $config['team_id'],
			// Phase 2: devices register against WalletWebService for automatic
			// updates. Apple appends /v1/… to this base URL.
			'webServiceURL'       => untrailingslashit( rest_url( self::NS . '/wallet' ) ),
			'authenticationToken' => WalletWebService::auth_token( (string) $card['memberKey'] ),
			'organizationName'    => 'Haramara Café',
			'description'         => __( 'Tarjeta de lealtad Haramara', 'haramara-core' ),
			'logoText'            => 'Haramara',
			// Brand palette (DESIGN.md): noche ground, bone text, brass labels —
			// brass is light, never a fill under bone text.
			'foregroundColor'     => 'rgb(239,232,220)',
			'backgroundColor'     => 'rgb(13,12,10)',
			'labelColor'          => 'rgb(200,165,102)',
			'barcodes'            => array(
				array(
					'format'          => 'PKBarcodeFormatQR',
					'message'         => (string) $card['token'],
					'messageEncoding' => 'iso-8859-1',
				),
			),
			'storeCard'           => array(
				'primaryFields'   => array(
					array(
						'key'   => 'stamps',
						'label' => __( 'Sellos', 'haramara-core' ),
						'value' => (int) $card['stamps'],
					),
				),
				'secondaryFields' => array(
					array(
						'key'   => 'redeemed',
						'label' => __( 'Recompensas canjeadas', 'haramara-core' ),
						'value' => (int) $card['redeemed'],
					),
				),
			),
		);
	}

	// ---------------------------------------------------------------------
	// Signing.
	// ---------------------------------------------------------------------

	/**
	 * Detached PKCS#7 signature (DER) over manifest.json, chained through the
	 * Apple WWDR intermediate.
	 *
	 * @param string               $manifest Manifest JSON string.
	 * @param array<string,string> $config   Signing config (see config()).
	 * @return string|\WP_Error
	 */
	private function sign_manifest( string $manifest, array $config ): string|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php'; // wp_tempnam() is not loaded in REST context.

		$manifest_tmp = wp_tempnam( 'haramara-manifest' );
		$sig_tmp      = wp_tempnam( 'haramara-signature' );

		try {
			if ( ! is_string( $manifest_tmp ) || ! is_string( $sig_tmp ) ) {
				return self::build_error();
			}
			if ( false === file_put_contents( $manifest_tmp, $manifest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private temp file for openssl.
				return self::build_error();
			}

			$pem = file_get_contents( $config['cert_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- certificate outside the webroot.
			if ( false === $pem ) {
				return self::sign_error();
			}

			$cert = openssl_x509_read( $pem );
			$pkey = openssl_pkey_get_private( $pem, '' !== $config['cert_password'] ? $config['cert_password'] : null );
			if ( false === $cert || false === $pkey ) {
				$this->log( 'Wallet cert/key parse failed: ' . (string) openssl_error_string() );
				return self::sign_error();
			}

			$signed = openssl_pkcs7_sign(
				$manifest_tmp,
				$sig_tmp,
				$cert,
				$pkey,
				array(),
				PKCS7_BINARY | PKCS7_DETACHED,
				$config['wwdr_path']
			);
			if ( true !== $signed ) {
				$this->log( 'openssl_pkcs7_sign failed: ' . (string) openssl_error_string() );
				return self::sign_error();
			}

			$smime = file_get_contents( $sig_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- private temp file from openssl.
			if ( false === $smime ) {
				return self::sign_error();
			}

			return $this->extract_der( $smime );
		} finally {
			foreach ( array( $manifest_tmp, $sig_tmp ) as $tmp ) {
				if ( is_string( $tmp ) && file_exists( $tmp ) ) {
					unlink( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own temp file.
				}
			}
		}
	}

	/**
	 * Pull the raw DER signature out of openssl's S/MIME output: the base64
	 * body of the smime.p7s attachment, between its blank line and the closing
	 * MIME boundary.
	 *
	 * @param string $smime Full S/MIME document written by openssl_pkcs7_sign().
	 * @return string|\WP_Error
	 */
	private function extract_der( string $smime ): string|\WP_Error {
		$marker = 'filename="smime.p7s"';
		$pos    = strpos( $smime, $marker );
		if ( false === $pos ) {
			return self::sign_error();
		}
		$rest = substr( $smime, $pos + strlen( $marker ) );

		if ( 1 !== preg_match( '/\r?\n\r?\n/', $rest, $m, PREG_OFFSET_CAPTURE ) ) {
			return self::sign_error();
		}
		$body = substr( $rest, (int) $m[0][1] + strlen( (string) $m[0][0] ) );

		$end    = strpos( $body, '------' );
		$base64 = trim( false !== $end ? substr( $body, 0, $end ) : $body );

		$der = base64_decode( $base64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding openssl's own S/MIME output.
		if ( false === $der || '' === $der ) {
			return self::sign_error();
		}
		return $der;
	}

	/**
	 * Zip the bundle in a temp file and return its bytes.
	 *
	 * @param array<string,string> $files filename => raw bytes.
	 * @return string|\WP_Error
	 */
	private function build_archive( array $files ): string|\WP_Error {
		$zip_tmp = wp_tempnam( 'haramara-pass' );

		try {
			if ( ! is_string( $zip_tmp ) ) {
				return self::build_error();
			}

			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_tmp, \ZipArchive::OVERWRITE ) ) {
				return self::build_error();
			}
			foreach ( $files as $name => $bytes ) {
				$zip->addFromString( $name, $bytes );
			}
			if ( true !== $zip->close() ) {
				return self::build_error();
			}

			$bytes = file_get_contents( $zip_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- private temp file we just wrote.
			return false === $bytes ? self::build_error() : $bytes;
		} finally {
			if ( is_string( $zip_tmp ) && file_exists( $zip_tmp ) ) {
				unlink( $zip_tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- removing our own temp file.
			}
		}
	}

	// ---------------------------------------------------------------------
	// Support.
	// ---------------------------------------------------------------------

	/**
	 * The booted Members service (or a fresh one — Members is stateless).
	 */
	private function members(): Members {
		return Plugin::instance()->get( Members::class ) ?? new Members();
	}

	/** Uniform 500 for bundle/zip failures. Detail goes to the debug log only. */
	private static function build_error(): \WP_Error {
		return new \WP_Error(
			'haramara_wallet_build_failed',
			__( 'No se pudo generar el pase.', 'haramara-core' ),
			array( 'status' => 500 )
		);
	}

	/** Uniform 500 for certificate/signature failures. Detail goes to the debug log only. */
	private static function sign_error(): \WP_Error {
		return new \WP_Error(
			'haramara_wallet_sign_failed',
			__( 'No se pudo firmar el pase.', 'haramara-core' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Debug-only diagnostics.
	 *
	 * @param string $message What went wrong.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[haramara-core] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only diagnostics.
		}
	}
}
