<?php
/**
 * Ticket folios — the printable, customer-typeable order handle.
 *
 * A folio is DERIVED from the order id, never stored as the lookup key:
 * `F<base36 id>-<4 hex HMAC>`. The checksum half is wp_salt-keyed, so a
 * customer typing their folio on the public /factura page proves they hold a
 * real ticket without the server ever scanning meta (meta lookups on the
 * order store are unreliable — see Shifts::cash_sales()) and without a
 * counter table that could drift. The same folio will print on tickets and
 * ride the /factura QR.
 *
 * Folios are case-insensitive on input and unambiguous on paper (base36
 * uppercase + hex). `F4H-9C2A` is order 161.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Ordering;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Folio derivation + verification.
 */
final class Folios {

	/** Convenience meta so admins can search orders by folio; never the lookup path. */
	public const META_FOLIO = '_haramara_pos_folio';

	/** Checksum length in hex chars. 16 bits — plenty against typos and guessing-by-increment. */
	private const CHECK_CHARS = 4;

	/**
	 * The folio for an order id.
	 *
	 * @param int $order_id Order id.
	 */
	public static function for_order( int $order_id ): string {
		$id36 = strtoupper( base_convert( (string) $order_id, 10, 36 ) );

		return 'F' . $id36 . '-' . self::check( $order_id );
	}

	/**
	 * Parse and verify a customer-typed folio back to an order id.
	 *
	 * @param string $folio Raw folio as typed.
	 * @return int|\WP_Error Order id, or an error indistinguishable between
	 *                       malformed and wrong-checksum (never confirm which).
	 */
	public static function parse( string $folio ) {
		$folio = strtoupper( trim( $folio ) );

		if ( ! preg_match( '/^F([0-9A-Z]{1,10})-([0-9A-F]{' . self::CHECK_CHARS . '})$/', $folio, $m ) ) {
			return self::bad_folio();
		}

		$order_id = (int) base_convert( $m[1], 36, 10 );
		if ( $order_id <= 0 ) {
			return self::bad_folio();
		}

		if ( ! hash_equals( self::check( $order_id ), $m[2] ) ) {
			return self::bad_folio();
		}

		return $order_id;
	}

	/**
	 * The checksum half for an order id.
	 *
	 * @param int $order_id Order id.
	 */
	private static function check( int $order_id ): string {
		return strtoupper( substr( hash_hmac( 'sha256', 'folio|' . $order_id, wp_salt( 'auth' ) ), 0, self::CHECK_CHARS ) );
	}

	/** One error for every failure mode. */
	private static function bad_folio(): \WP_Error {
		return new \WP_Error(
			'haramara_folio_invalid',
			__( 'Folio no válido. Revísalo tal como aparece en tu ticket.', 'haramara-core' ),
			array( 'status' => 404 )
		);
	}
}
