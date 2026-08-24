<?php
/**
 * POS request-replay guard.
 *
 * Every mutating POS route accepts a client-generated `idempotency_key`. The
 * first request carrying a given key does the work and its response is stored;
 * any later request with the same key gets that stored response back instead of
 * ringing a second sale. This is what makes a retry safe when the tablet's
 * connection drops mid-charge — and it is the foundation the offline outbox
 * replays against later, which is why it lands now rather than being retrofitted
 * around routes that already shipped without it.
 *
 * The guard is a three-step claim, not a lookup-then-write: `begin()` takes the
 * key with an INSERT against a UNIQUE column, so two tablets racing the same key
 * cannot both pass. The loser is told the request is `in_flight` rather than
 * being allowed through. `complete()` fills in the response; `release()` frees
 * the key when the work failed, so a genuine retry is still possible.
 *
 * Keys are client-supplied and untrusted: they are length-capped and reduced to
 * `[a-z0-9-]` before they ever reach SQL.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Rest;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Setup\Activator;
use Haramara\Core\Staff\Operators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Idempotency-key storage for the POS write surface.
 */
final class Idempotency implements Bootable {

	/** Prune cron hook. */
	private const PRUNE_HOOK = 'haramara_pos_idempotency_prune';

	/**
	 * Rows older than this are pruned. A replay window only has to outlive a
	 * bad connection and an offline outbox drain, not the business records —
	 * the sale itself lives in WooCommerce, not here.
	 */
	private const RETENTION_DAYS = 30;

	/** Longest client key accepted. */
	private const MAX_KEY_LENGTH = 64;

	/**
	 * How long a claim may sit unfinished before another request may take it
	 * over. `release()` only runs when the work returns an error — a PHP fatal,
	 * a timeout, or a container restart leaves the row claimed forever, and
	 * without takeover the cashier's ticket would 409 until the row was pruned
	 * 30 days later. A genuine concurrent double-tap is milliseconds apart and
	 * still collides; an abandoned claim frees itself.
	 */
	private const STALE_CLAIM_SECONDS = 60;

	public function boot(): void {
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune' ) );

		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			// +1h so the first run never collides with activation.
			wp_schedule_event( time() + 3600, 'daily', self::PRUNE_HOOK );
		}
	}

	/**
	 * Run a POS write through operator resolution and the replay guard.
	 *
	 * Lives here rather than on a route class so that every mutating POS
	 * endpoint can use it — the loyalty routes are registered by Loyalty\Members,
	 * not Rest\PosRoutes, and an unguarded redeem is free product.
	 *
	 * `$work` receives the resolved operator (array, or null when the tablet
	 * sent no operator header) and returns a WP_REST_Response or WP_Error.
	 * Failures release the key so a genuine retry is possible; successes keep it
	 * claimed, which is what stops a retried charge from ringing twice. A
	 * request carrying no key runs unguarded, so an older app build keeps
	 * working through the rollout.
	 *
	 * @param \WP_REST_Request $request Current request.
	 * @param string           $route   Route label recorded with the claim.
	 * @param callable         $work    The actual work.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function guard( \WP_REST_Request $request, string $route, callable $work ) {
		$operator = null;
		$token    = trim( (string) $request->get_header( 'x-pos-operator' ) );

		if ( '' !== $token ) {
			$operator = Operators::resolve_token( $token );
			// A header that is present but invalid means a stale session, not an
			// anonymous one — surface it rather than silently dropping the
			// attribution the whole feature exists to provide.
			if ( is_wp_error( $operator ) ) {
				return $operator;
			}
		}

		$key = (string) $request->get_param( 'idempotency_key' );
		if ( '' === $key ) {
			return $work( $operator );
		}

		$claim = self::begin( $key, $route, is_array( $operator ) ? (string) $operator['key'] : '' );

		if ( 'replay' === $claim['state'] ) {
			$response = rest_ensure_response( $claim['response'] );
			$response->set_status( (int) $claim['status'] );
			$response->header( 'Cache-Control', 'no-store' );
			$response->header( 'X-Pos-Idempotent-Replay', '1' );

			return $response;
		}

		if ( 'in_flight' === $claim['state'] ) {
			return new \WP_Error(
				'haramara_request_in_flight',
				__( 'Esta operación ya se está procesando. Espera un momento.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		$result = $work( $operator );

		if ( is_wp_error( $result ) ) {
			self::release( $key );
			return $result;
		}

		self::complete( $key, $result->get_status(), $result->get_data() );

		return $result;
	}

	/**
	 * The REST arg definition for the optional idempotency key.
	 *
	 * @return array<string,mixed>
	 */
	public static function arg(): array {
		return array(
			'required'          => false,
			'type'              => 'string',
			'default'           => '',
			'description'       => __( 'Clave de idempotencia generada por el cliente.', 'haramara-core' ),
			'sanitize_callback' => static fn( $value ): string => self::normalize_key( (string) $value ),
		);
	}

	/** Fully-qualified table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . Activator::IDEMPOTENCY_TABLE;
	}

	/**
	 * Normalize a client-supplied key. Returns '' when unusable.
	 *
	 * @param string $key Raw key as received.
	 */
	public static function normalize_key( string $key ): string {
		$key = strtolower( trim( $key ) );
		$key = (string) preg_replace( '/[^a-z0-9\-]/', '', $key );
		return substr( $key, 0, self::MAX_KEY_LENGTH );
	}

	/**
	 * Claim a key before doing the work.
	 *
	 * @param string $key          Normalized idempotency key.
	 * @param string $route        Route label, for support forensics.
	 * @param string $operator_key Operator that owns the request.
	 * @return array{state:string,status?:int,response?:mixed}
	 *         `fresh` — caller should do the work.
	 *         `replay` — caller should return the stored status/response.
	 *         `in_flight` — an identical request is still running.
	 */
	public static function begin( string $key, string $route, string $operator_key = '' ): array {
		global $wpdb;

		$table = self::table();

		// INSERT first: the UNIQUE index on request_key is the lock. A
		// SELECT-then-INSERT here would let two concurrent charges both pass.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$claimed = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT IGNORE INTO {$table} (request_key, route, operator_key, status, response, created_at) VALUES (%s, %s, %s, 0, NULL, %s)",
				$key,
				substr( $route, 0, 100 ),
				substr( $operator_key, 0, 32 ),
				self::now()
			)
		);

		if ( 1 === (int) $claimed ) {
			return array( 'state' => 'fresh' );
		}

		// The literal 'ARRAY_A' is the value of the WordPress constant of the
		// same name (the constant itself is not visible to static analysis in
		// this codebase — see the baselined `Constant ARRAY_A not found`
		// entries). The get_object_vars() normalization below means a wrong
		// output format could not silently reopen the double-charge path even
		// if that ever changed.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, response, created_at FROM {$table} WHERE request_key = %s", $key ), 'ARRAY_A' );

		if ( is_object( $row ) ) {
			$row = get_object_vars( $row );
		}

		if ( ! is_array( $row ) ) {
			// The row genuinely vanished between the failed insert and this read
			// (a prune at exactly the wrong moment). Treat as fresh — replaying
			// nothing is worse than the vanishingly rare double-charge.
			return array( 'state' => 'fresh' );
		}

		if ( 0 === (int) $row['status'] || null === $row['response'] ) {
			// Unfinished. Take it over once it is clearly abandoned, otherwise
			// tell the caller an identical request is genuinely still running.
			if ( self::claim_is_stale( (string) ( $row['created_at'] ?? '' ) ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update(
					$table,
					array( 'created_at' => self::now() ),
					array( 'request_key' => $key ),
					array( '%s' ),
					array( '%s' )
				);

				return array( 'state' => 'fresh' );
			}

			return array( 'state' => 'in_flight' );
		}

		return array(
			'state'    => 'replay',
			'status'   => (int) $row['status'],
			'response' => json_decode( (string) $row['response'], true ),
		);
	}

	/**
	 * Record the response a claimed key produced.
	 *
	 * @param string $key      Normalized idempotency key.
	 * @param int    $status   HTTP status.
	 * @param mixed  $response Response payload (JSON-encodable).
	 */
	public static function complete( string $key, int $status, mixed $response ): void {
		global $wpdb;

		$encoded = wp_json_encode( $response );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array(
				'status'   => $status,
				'response' => false === $encoded ? 'null' : $encoded,
			),
			array( 'request_key' => $key ),
			array( '%d', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Free a claimed key whose work failed, so the client can retry.
	 *
	 * Only failures release. A key whose work *succeeded* must stay claimed
	 * forever (until pruned) — that is the entire point.
	 *
	 * @param string $key Normalized idempotency key.
	 */
	public static function release( string $key ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table(), array( 'request_key' => $key ), array( '%s' ) );
	}

	/**
	 * Delete rows past the retention window. Index-backed on created_at.
	 */
	public static function prune(): void {
		global $wpdb;

		$table  = self::table();
		$cutoff = ( new \DateTimeImmutable( 'now', \Haramara\Core\Setup\Options::timezone() ) )
			->modify( '-' . self::RETENTION_DAYS . ' days' )
			->format( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Whether an unfinished claim is old enough to take over.
	 *
	 * @param string $created_at Café-local `Y-m-d H:i:s` stamp from the row.
	 */
	private static function claim_is_stale( string $created_at ): bool {
		if ( '' === $created_at ) {
			return true;
		}

		try {
			$claimed = new \DateTimeImmutable( $created_at, \Haramara\Core\Setup\Options::timezone() );
		} catch ( \Exception $e ) {
			return true;
		}

		$now = new \DateTimeImmutable( 'now', \Haramara\Core\Setup\Options::timezone() );

		return ( $now->getTimestamp() - $claimed->getTimestamp() ) >= self::STALE_CLAIM_SECONDS;
	}

	/**
	 * Café-local timestamp, matching Woo\Withdrawals.
	 *
	 * `current_time('mysql')` is deliberately not used: the POS renders and
	 * windows these rows by café-local time, and a UTC stamp makes evening
	 * rows fall outside the day.
	 */
	private static function now(): string {
		return ( new \DateTimeImmutable( 'now', \Haramara\Core\Setup\Options::timezone() ) )->format( 'Y-m-d H:i:s' );
	}
}
