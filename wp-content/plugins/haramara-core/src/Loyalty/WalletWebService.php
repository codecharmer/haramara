<?php
/**
 * Apple Wallet pass web service — Phase 2: automatic pass updates.
 *
 * Implements the PassKit Web Service protocol so installed passes refresh
 * themselves: devices register here (the pass carries webServiceURL +
 * authenticationToken, see WalletPass::pass_definition()), every stamp or
 * redeem fires an APNs push, and Wallet silently re-fetches the latest pass.
 * The push channel authenticates with the SAME Pass Type ID certificate that
 * signs the passes — no new configuration beyond Phase 1's constants.
 *
 * Apple appends /v1/… to webServiceURL (rest_url('haramara/v1/wallet')), so
 * the surface is:
 *   POST   /wallet/v1/devices/{device}/registrations/{passType}/{serial}
 *   DELETE /wallet/v1/devices/{device}/registrations/{passType}/{serial}
 *   GET    /wallet/v1/devices/{device}/registrations/{passType}?passesUpdatedSince=
 *   GET    /wallet/v1/passes/{passType}/{serial}
 *   POST   /wallet/v1/log
 *
 * Auth is Apple's `Authorization: ApplePass <token>` header, where the token
 * is an HMAC over the serial (member key) in a DIFFERENT context from the card
 * token, so neither secret derives the other. The registrations/{passType}
 * listing and /log carry no auth by protocol design.
 *
 * Passes added before this shipped carry no webServiceURL — each starts
 * updating after being re-added once (same serial, replaces in place).
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Loyalty;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Plugin;
use Haramara\Core\Setup\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Device registrations, pass re-serves, and APNs pushes.
 */
final class WalletWebService implements Bootable {

	private const NS = 'haramara/v1';

	/** When a card last changed (unix time) — drives Last-Modified and passesUpdatedSince. */
	public const META_UPDATED = '_haramara_wallet_updated';

	/** APNs HTTP/2 endpoint; the device push token is appended. */
	private const APNS_ENDPOINT = 'https://api.push.apple.com/3/device/';

	/** Route fragments shared by the registration endpoints. */
	private const DEVICE_PATTERN = '(?P<device_id>[A-Za-z0-9]{8,64})';
	private const SERIAL_PATTERN = '(?P<serial>[a-z0-9]{8,64})';

	/**
	 * Hook the REST surface and the card-change listener.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'haramara_loyalty_card_updated', array( $this, 'on_card_updated' ), 10, 2 );
	}

	/**
	 * The PassKit web service endpoints. Auth happens inside the handlers —
	 * Apple's protocol mixes authenticated and open routes on one surface.
	 */
	public function register_routes(): void {
		$registration = '/wallet/v1/devices/' . self::DEVICE_PATTERN . '/registrations/(?P<pass_type>[^/]+)';

		register_rest_route(
			self::NS,
			$registration . '/' . self::SERIAL_PATTERN,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'register_device' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unregister_device' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NS,
			$registration,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_registrations' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/wallet/v1/passes/(?P<pass_type>[^/]+)/' . self::SERIAL_PATTERN,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_updated_pass' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/wallet/v1/log',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'log_messages' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Per-pass web-service secret. Deterministic (no storage), keyed like the
	 * card token but in a distinct HMAC context.
	 *
	 * @param string $member_key The member's public key (= pass serial).
	 */
	public static function auth_token( string $member_key ): string {
		return hash_hmac( 'sha256', 'wallet-pass:' . $member_key, wp_salt( 'auth' ) );
	}

	// ---------------------------------------------------------------------
	// Handlers.
	// ---------------------------------------------------------------------

	/**
	 * POST registration — Wallet announces a device shows this pass.
	 * 201 created, 200 already registered, 401 bad auth.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function register_device( \WP_REST_Request $request ): \WP_REST_Response {
		$serial = (string) $request['serial'];
		if ( ! $this->authorized( $request, $serial ) || 0 === $this->members()->member_id_for_key( $serial ) ) {
			return self::status( 401 );
		}

		$push_token = sanitize_text_field( (string) $request['pushToken'] );
		if ( '' === $push_token || strlen( $push_token ) > 200 ) {
			return self::status( 400 );
		}

		global $wpdb;
		$table    = self::table();
		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom registrations table.
			$wpdb->prepare(
				"SELECT id, push_token FROM {$table} WHERE device_id = %s AND serial = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a constant.
				(string) $request['device_id'],
				$serial
			)
		);

		if ( null !== $existing ) {
			if ( (string) $existing->push_token !== $push_token ) {
				$wpdb->update( $table, array( 'push_token' => $push_token ), array( 'id' => (int) $existing->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom registrations table.
			}
			return self::status( 200 );
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom registrations table.
			$table,
			array(
				'device_id'  => (string) $request['device_id'],
				'push_token' => $push_token,
				'serial'     => $serial,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		return self::status( 201 );
	}

	/**
	 * DELETE registration — the pass was removed from the device.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function unregister_device( \WP_REST_Request $request ): \WP_REST_Response {
		$serial = (string) $request['serial'];
		if ( ! $this->authorized( $request, $serial ) ) {
			return self::status( 401 );
		}

		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom registrations table.
			self::table(),
			array(
				'device_id' => (string) $request['device_id'],
				'serial'    => $serial,
			)
		);

		return self::status( 200 );
	}

	/**
	 * GET serials for a device, optionally only those changed since a tag.
	 * 200 with {lastUpdated, serialNumbers}, or 204 when nothing applies.
	 * Unauthenticated by protocol design — it only reveals opaque serials the
	 * device already shows.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function list_registrations( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom registrations table.
			$wpdb->prepare(
				"SELECT serial FROM {$table} WHERE device_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a constant.
				(string) $request['device_id']
			)
		);

		if ( array() === $rows ) {
			return self::status( 204 );
		}

		$since   = absint( (string) $request->get_param( 'passesUpdatedSince' ) );
		$serials = array();
		$latest  = 0;

		foreach ( $rows as $serial ) {
			$member_id = $this->members()->member_id_for_key( (string) $serial );
			if ( 0 === $member_id ) {
				continue; // Member gone; stale registration.
			}
			$updated = $this->last_updated( $member_id );
			$latest  = max( $latest, $updated );
			if ( $updated > $since ) {
				$serials[] = (string) $serial;
			}
		}

		if ( array() === $serials ) {
			return self::status( 204 );
		}

		$response = new \WP_REST_Response(
			array(
				'lastUpdated'   => (string) $latest,
				'serialNumbers' => $serials,
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * GET the latest pass for a serial. Honors If-Modified-Since with a 304 so
	 * devices skip unchanged rebuilds.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_updated_pass( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$serial = (string) $request['serial'];
		if ( ! $this->authorized( $request, $serial ) ) {
			return self::status( 401 );
		}

		$member_id = $this->members()->member_id_for_key( $serial );
		if ( 0 === $member_id ) {
			return self::status( 404 );
		}

		$config = WalletPass::config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$updated  = $this->last_updated( $member_id );
		$modified = strtotime( (string) $request->get_header( 'if_modified_since' ) );
		if ( false !== $modified && $modified >= $updated ) {
			return self::status( 304 );
		}

		$card = $this->members()->card_for_member_key( $serial );
		if ( is_wp_error( $card ) ) {
			return $card;
		}

		$zip = $this->wallet_pass()->build_pkpass( $card, $config );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		$response = new \WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', 'application/vnd.apple.pkpass' );
		$response->header( 'Last-Modified', gmdate( 'D, d M Y H:i:s', $updated ) . ' GMT' );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Content-Length', (string) strlen( $zip ) );

		// Same binary-emission pattern as WalletPass::get_pass(): the REST
		// server would JSON-encode the bytes; the identity guard spares every
		// other response.
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
	 * POST /log — device-reported errors. Surfaced in the debug log only; the
	 * endpoint always answers 200 so devices never retry-loop.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function log_messages( \WP_REST_Request $request ): \WP_REST_Response {
		$logs = $request['logs'];
		if ( is_array( $logs ) ) {
			foreach ( $logs as $entry ) {
				if ( is_string( $entry ) ) {
					$this->log( 'Wallet device log: ' . $entry );
				}
			}
		}
		return self::status( 200 );
	}

	// ---------------------------------------------------------------------
	// Card-change fanout.
	// ---------------------------------------------------------------------

	/**
	 * A stamp or redeem happened: mark the card changed and nudge every device
	 * showing its pass. Runs inline in the POS request — registrations per
	 * member are typically one device, and the APNs timeout is short.
	 *
	 * @param int    $member_id  Member post ID.
	 * @param string $member_key The member's public key (= pass serial).
	 */
	public function on_card_updated( int $member_id, string $member_key ): void {
		update_post_meta( $member_id, self::META_UPDATED, time() );

		$config = WalletPass::config();
		if ( is_wp_error( $config ) ) {
			return; // Unconfigured install: nothing is registered, nothing to push.
		}

		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom registrations table.
			$wpdb->prepare(
				"SELECT id, push_token FROM {$table} WHERE serial = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from a constant.
				$member_key
			)
		);

		foreach ( $rows as $row ) {
			$status = $this->push_update( (string) $row->push_token, $config );
			if ( 410 === $status ) {
				$wpdb->delete( $table, array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- APNs says the token is gone.
			}
		}
	}

	/**
	 * Empty APNs push: "this pass changed, come fetch it". Authenticated with
	 * the pass certificate itself (client cert), topic = the pass type ID.
	 * wp_remote_post() can't send HTTP/2 with a client certificate, so this is
	 * raw curl by necessity.
	 *
	 * @param string               $push_token Device push token from registration.
	 * @param array<string,string> $config     Signing config (WalletPass::config()).
	 * @return int HTTP status from APNs, or 0 on transport failure.
	 */
	private function push_update( string $push_token, array $config ): int {
		if ( ! function_exists( 'curl_init' ) || ! defined( 'CURL_HTTP_VERSION_2TLS' ) ) {
			$this->log( 'Wallet push skipped: curl with HTTP/2 unavailable.' );
			return 0;
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- APNs requires HTTP/2 + client certs; the WP HTTP API supports neither.
		$ch = curl_init( self::APNS_ENDPOINT . rawurlencode( $push_token ) );
		if ( false === $ch ) {
			return 0;
		}

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => '{"aps":{}}',
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 3,
				CURLOPT_TIMEOUT        => 5,
				CURLOPT_SSLCERT        => $config['cert_path'],
				CURLOPT_HTTPHEADER     => array(
					'apns-topic: ' . $config['pass_type_id'],
					'apns-push-type: alert',
					'Content-Type: application/json',
				),
			)
		);
		if ( '' !== $config['cert_password'] ) {
			curl_setopt( $ch, CURLOPT_KEYPASSWD, $config['cert_password'] );
		}

		$body   = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		if ( false === $body || ( 200 !== $status && 410 !== $status ) ) {
			$this->log( sprintf( 'Wallet push failed (%d): %s', $status, false === $body ? curl_error( $ch ) : (string) $body ) );
		}
		curl_close( $ch );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		return $status;
	}

	// ---------------------------------------------------------------------
	// Support.
	// ---------------------------------------------------------------------

	/**
	 * When this member's pass content last changed. Falls back to the member's
	 * registration time for cards never stamped since Phase 2 shipped.
	 *
	 * @param int $member_id Member post ID.
	 */
	private function last_updated( int $member_id ): int {
		$updated = (int) get_post_meta( $member_id, self::META_UPDATED, true );
		return $updated > 0 ? $updated : (int) get_post_time( 'U', true, $member_id );
	}

	/**
	 * Validate Apple's `Authorization: ApplePass <token>` header for a serial.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @param string           $serial  Pass serial (= member key).
	 */
	private function authorized( \WP_REST_Request $request, string $serial ): bool {
		$header = trim( (string) $request->get_header( 'authorization' ) );
		if ( 0 !== stripos( $header, 'ApplePass ' ) ) {
			return false;
		}
		return hash_equals( self::auth_token( $serial ), trim( substr( $header, strlen( 'ApplePass ' ) ) ) );
	}

	/**
	 * Prefixed registrations table name.
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Activator::WALLET_DEVICES_TABLE;
	}

	/**
	 * Status-only response — Apple's protocol reads codes, not bodies.
	 * Always no-store: production sits behind a caching proxy that stores any
	 * GET without explicit cache headers, and a stale registrations listing
	 * makes devices skip real updates (observed on first prod verification).
	 *
	 * @param int $code HTTP status.
	 */
	private static function status( int $code ): \WP_REST_Response {
		$response = new \WP_REST_Response( null, $code );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * The booted Members service (or a fresh one — Members is stateless).
	 */
	private function members(): Members {
		return Plugin::instance()->get( Members::class ) ?? new Members();
	}

	/**
	 * The booted WalletPass service (or a fresh one — it is stateless too).
	 */
	private function wallet_pass(): WalletPass {
		return Plugin::instance()->get( WalletPass::class ) ?? new WalletPass();
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
