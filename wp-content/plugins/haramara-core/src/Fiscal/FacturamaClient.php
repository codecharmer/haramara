<?php
/**
 * Facturama PAC client (CFDI 4.0 over REST).
 *
 * Talks to Facturama's API (Basic auth, JSON) through WordPress' HTTP layer —
 * no SDK. Configuration comes EXCLUSIVELY from wp-config constants, mirroring
 * the WalletPass secrets rule:
 *
 *   HARAMARA_FACTURAMA_USER     Facturama account user.
 *   HARAMARA_FACTURAMA_PASS     Facturama account password.
 *   HARAMARA_FACTURAMA_SANDBOX  true → https://apisandbox.facturama.mx.
 *   HARAMARA_FISCAL_CP          ExpeditionPlace (código postal of the café).
 *
 * An unconfigured install answers 503 from every call, so the /factura page
 * simply hides the feature. Credentials are never logged, stored, or echoed.
 *
 * SAT specifics owned here: the IVA 16% desglose (WooCommerce prices are IVA-
 * included, so Base = total / 1.16 and IVA = total − base per line, rounded to
 * 2 decimals with the rounding remainder absorbed in the largest line), the
 * default ProductCode 01010101 ("no existe en el catálogo", per-line override
 * via the `haramara_fiscal_product_code` filter), and UnitCode ACT.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Fiscal;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FacturamaClient implements PacClient {

	private const BASE_PRODUCTION = 'https://api.facturama.mx';
	private const BASE_SANDBOX    = 'https://apisandbox.facturama.mx';

	/** SAT c_ClaveProdServ fallback: 01010101 = "No existe en el catálogo". */
	private const DEFAULT_PRODUCT_CODE = '01010101';

	/** SAT c_ClaveUnidad for services/activities sold at the counter. */
	private const UNIT_CODE = 'ACT';

	/** IVA general rate; Woo prices are stored with it included. */
	private const IVA_RATE = 0.16;

	/**
	 * Largest total drift (MXN) between the order total and the sum of its
	 * line totals that is treated as rounding and absorbed silently.
	 */
	private const MAX_ROUNDING_DRIFT = 0.10;

	/**
	 * Whether this install holds Facturama credentials + the fiscal CP.
	 */
	public function is_configured(): bool {
		return ! is_wp_error( self::config() );
	}

	/**
	 * Issue (stamp) a CFDI 4.0 invoice, then fetch its PDF and XML.
	 *
	 * @param array<string,mixed> $cfdi PAC-neutral invoice description (see PacClient).
	 * @return array{uuid:string,pdf_b64:string,xml_b64:string,series:string,folio_fiscal:string}|\WP_Error
	 */
	public function issue( array $cfdi ): array|\WP_Error {
		$config = self::config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$payload = $this->payload( $cfdi, $config );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$created = $this->request( 'POST', '/2/cfdis', $payload, $config );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$id   = (string) ( $created['Id'] ?? '' );
		$uuid = self::dig_string( $created, array( 'Complement', 'TaxStamp', 'Uuid' ) );
		if ( '' === $id || '' === $uuid ) {
			$this->log( 'Facturama create response missing Id/Uuid.' );
			return new \WP_Error(
				'haramara_pac_unexpected',
				__( 'El servicio de facturación respondió de forma inesperada.', 'haramara-core' ),
				array( 'status' => 502 )
			);
		}

		// The invoice EXISTS at the PAC from here on: document-fetch failures
		// carry the uuid in the error data so callers can still record it.
		$pdf = $this->document( $id, 'pdf', $config );
		if ( is_wp_error( $pdf ) ) {
			$pdf->add_data(
				array(
					'status' => 502,
					'uuid'   => $uuid,
				)
			);
			return $pdf;
		}

		$xml = $this->document( $id, 'xml', $config );
		if ( is_wp_error( $xml ) ) {
			$xml->add_data(
				array(
					'status' => 502,
					'uuid'   => $uuid,
				)
			);
			return $xml;
		}

		return array(
			'uuid'         => $uuid,
			'pdf_b64'      => $pdf,
			'xml_b64'      => $xml,
			'series'       => (string) ( $created['Serie'] ?? '' ),
			'folio_fiscal' => (string) ( $created['Folio'] ?? '' ),
		);
	}

	/**
	 * Cancel a previously issued CFDI by its SAT UUID.
	 *
	 * Facturama's cancel endpoint takes ITS internal id, not the SAT UUID, so
	 * the UUID is first resolved through the issued-CFDI search.
	 *
	 * @param string $uuid SAT folio fiscal (UUID).
	 * @return true|\WP_Error
	 */
	public function cancel( string $uuid ): true|\WP_Error {
		$config = self::config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$uuid = strtoupper( trim( $uuid ) );
		if ( '' === $uuid ) {
			return new \WP_Error(
				'haramara_pac_bad_uuid',
				__( 'Falta el folio fiscal (UUID) de la factura.', 'haramara-core' ),
				array( 'status' => 400 )
			);
		}

		$found = $this->request( 'GET', '/cfdi?type=issued&keyword=' . rawurlencode( $uuid ), null, $config );
		if ( is_wp_error( $found ) ) {
			return $found;
		}

		$id = '';
		foreach ( $found as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row_uuid = (string) ( $row['Uuid'] ?? self::dig_string( $row, array( 'Complement', 'TaxStamp', 'Uuid' ) ) );
			if ( 0 === strcasecmp( $row_uuid, $uuid ) ) {
				$id = (string) ( $row['Id'] ?? '' );
				break;
			}
		}

		if ( '' === $id ) {
			return new \WP_Error(
				'haramara_pac_not_found',
				__( 'El servicio de facturación no encontró esa factura.', 'haramara-core' ),
				array( 'status' => 404 )
			);
		}

		/**
		 * Filter the SAT cancellation motive. 02 = "comprobante emitido con
		 * errores sin relación", the fit for a mistyped autofactura.
		 *
		 * @param string $motive SAT c_MotivoCancelacion code.
		 * @param string $uuid   SAT folio fiscal being cancelled.
		 */
		$motive = (string) apply_filters( 'haramara_fiscal_cancel_motive', '02', $uuid );

		$result = $this->request(
			'DELETE',
			'/cfdi/' . rawurlencode( $id ) . '?type=issued&motive=' . rawurlencode( $motive ),
			null,
			$config
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/* ---------------------------------------------------------------------- */
	/* Payload                                                                */
	/* ---------------------------------------------------------------------- */

	/**
	 * Map the neutral $cfdi description to Facturama's CFDI 4.0 create model.
	 *
	 * @param array<string,mixed>                              $cfdi   Neutral invoice description (see PacClient).
	 * @param array{user:string,pass:string,cp:string,base:string} $config Resolved configuration.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function payload( array $cfdi, array $config ): array|\WP_Error {
		$total = round( (float) ( $cfdi['total'] ?? 0 ), 2 );
		if ( $total <= 0 ) {
			return new \WP_Error(
				'haramara_fiscal_empty',
				__( 'El ticket no tiene un total facturable.', 'haramara-core' ),
				array( 'status' => 422 )
			);
		}

		$raw   = $cfdi['items'] ?? array();
		$lines = $this->normalize_lines( is_array( $raw ) ? $raw : array() );
		if ( array() === $lines ) {
			return new \WP_Error(
				'haramara_fiscal_empty',
				__( 'El ticket no tiene artículos facturables.', 'haramara-core' ),
				array( 'status' => 422 )
			);
		}

		$lines = $this->desglose( $lines, $total );
		if ( is_wp_error( $lines ) ) {
			return $lines;
		}

		$items = array();
		foreach ( $lines as $line ) {
			/**
			 * Filter the SAT c_ClaveProdServ for one invoice line. Default is
			 * 01010101 ("no existe en el catálogo").
			 *
			 * @param string              $code SAT product/service key.
			 * @param array<string,mixed> $line Neutral line: product_id, name, quantity, total.
			 */
			$code = (string) apply_filters( 'haramara_fiscal_product_code', self::DEFAULT_PRODUCT_CODE, $line );

			$items[] = array(
				'ProductCode' => $code,
				'Description' => mb_substr( (string) $line['name'], 0, 1000 ),
				'UnitCode'    => self::UNIT_CODE,
				'Unit'        => 'Actividad',
				'Quantity'    => $line['quantity'],
				'UnitPrice'   => round( $line['base'] / $line['quantity'], 6 ),
				'Subtotal'    => $line['base'],
				'TaxObject'   => '02',
				'Taxes'       => array(
					array(
						'Name'        => 'IVA',
						'Rate'        => self::IVA_RATE,
						'Base'        => $line['base'],
						'Total'       => $line['tax'],
						'IsRetention' => false,
					),
				),
				'Total'       => round( $line['base'] + $line['tax'], 2 ),
			);
		}

		$receiver = $cfdi['receiver'] ?? array();
		$receiver = is_array( $receiver ) ? $receiver : array();

		/**
		 * Filter the Serie stamped on autofactura CFDIs.
		 *
		 * @param string $serie Invoice series.
		 */
		$serie = (string) apply_filters( 'haramara_fiscal_serie', 'HARA' );

		return array(
			'CfdiType'        => 'I',
			'Serie'           => $serie,
			'Folio'           => mb_substr( (string) ( $cfdi['folio'] ?? '' ), 0, 40 ),
			'Currency'        => 'MXN',
			'ExpeditionPlace' => $config['cp'],
			'PaymentForm'     => (string) ( $cfdi['payment_form'] ?? '01' ),
			'PaymentMethod'   => 'PUE',
			'Exportation'     => '01',
			'Receiver'        => array(
				'Rfc'          => mb_strtoupper( trim( (string) ( $receiver['rfc'] ?? '' ) ) ),
				'Name'         => mb_strtoupper( trim( (string) ( $receiver['name'] ?? '' ) ) ),
				'CfdiUse'      => (string) ( $receiver['cfdi_use'] ?? '' ),
				'FiscalRegime' => (string) ( $receiver['fiscal_regime'] ?? '' ),
				'TaxZipCode'   => (string) ( $receiver['zip'] ?? '' ),
			),
			'Items'           => $items,
		);
	}

	/**
	 * Clean the neutral lines: positive gross totals only, quantities >= 1.
	 *
	 * @param array<int|string,mixed> $raw Neutral items as received.
	 * @return array<int,array{product_id:int,name:string,quantity:int,total:float}>
	 */
	private function normalize_lines( array $raw ): array {
		$lines = array();
		foreach ( $raw as $line ) {
			if ( ! is_array( $line ) ) {
				continue;
			}
			$gross = round( (float) ( $line['total'] ?? 0 ), 2 );
			if ( $gross <= 0 ) {
				continue; // Cortesías carry no IVA and no invoice line.
			}
			$lines[] = array(
				'product_id' => (int) ( $line['product_id'] ?? 0 ),
				'name'       => (string) ( $line['name'] ?? '' ),
				'quantity'   => max( 1, (int) ( $line['quantity'] ?? 1 ) ),
				'total'      => $gross,
			);
		}
		return $lines;
	}

	/**
	 * IVA 16% desglose over IVA-included line totals.
	 *
	 * Two rounding passes, each absorbing its remainder in the LARGEST line so
	 * the invoice total equals the amount actually charged, centavo-exact:
	 *
	 *  1. Sum of line grosses vs the order total — small drift (order-level
	 *     rounding) is added to the largest line; larger drift is refused.
	 *  2. Per-line Base = gross / 1.16 rounded to 2 decimals — the cent lost
	 *     or gained vs round(total / 1.16) is added to the largest line's
	 *     base, and each line's IVA is then exactly gross − base.
	 *
	 * @param array<int,array{product_id:int,name:string,quantity:int,total:float}> $lines Normalized lines.
	 * @param float                                                                 $total Order grand total (IVA included).
	 * @return array<int,array{product_id:int,name:string,quantity:int,total:float,base:float,tax:float}>|\WP_Error
	 */
	private function desglose( array $lines, float $total ): array|\WP_Error {
		$largest = 0;
		$sum     = 0.0;
		foreach ( $lines as $i => $line ) {
			$sum = round( $sum + $line['total'], 2 );
			if ( $line['total'] > $lines[ $largest ]['total'] ) {
				$largest = $i;
			}
		}

		$drift = round( $total - $sum, 2 );
		if ( 0.0 !== $drift ) {
			if ( abs( $drift ) > self::MAX_ROUNDING_DRIFT ) {
				// Order carries a fee/discount the line items do not explain;
				// refusing beats stamping an invoice for the wrong amount.
				return new \WP_Error(
					'haramara_fiscal_totals',
					__( 'No se pudo cuadrar el total del ticket con sus artículos. Escríbenos por WhatsApp para facturar este ticket.', 'haramara-core' ),
					array( 'status' => 422 )
				);
			}
			$lines[ $largest ]['total'] = round( $lines[ $largest ]['total'] + $drift, 2 );
		}

		$out      = array();
		$base_sum = 0.0;
		foreach ( $lines as $i => $line ) {
			$base      = round( $line['total'] / ( 1 + self::IVA_RATE ), 2 );
			$base_sum  = round( $base_sum + $base, 2 );
			$out[ $i ] = $line + array(
				'base' => $base,
				'tax'  => 0.0,
			);
		}

		$base_drift = round( round( $total / ( 1 + self::IVA_RATE ), 2 ) - $base_sum, 2 );
		if ( 0.0 !== $base_drift ) {
			$out[ $largest ]['base'] = round( $out[ $largest ]['base'] + $base_drift, 2 );
		}

		foreach ( $out as $i => $line ) {
			$out[ $i ]['tax'] = round( $line['total'] - $line['base'], 2 );
		}

		return $out;
	}

	/* ---------------------------------------------------------------------- */
	/* HTTP                                                                   */
	/* ---------------------------------------------------------------------- */

	/**
	 * One Facturama call with uniform error mapping.
	 *
	 * @param string                                               $method HTTP method.
	 * @param string                                               $path   Path (+ query) under the API base.
	 * @param array<string,mixed>|null                             $body   JSON body, or null for none.
	 * @param array{user:string,pass:string,cp:string,base:string} $config Resolved configuration.
	 * @return array<int|string,mixed>|\WP_Error Decoded JSON body (array; empty for empty bodies).
	 */
	private function request( string $method, string $path, ?array $body, array $config ): array|\WP_Error {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $config['user'] . ':' . $config['pass'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth header.
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( $config['base'] . $path, $args );

		if ( is_wp_error( $response ) ) {
			$this->log( 'Facturama network error: ' . $response->get_error_message() );
			return new \WP_Error(
				'haramara_pac_network',
				__( 'No se pudo contactar al servicio de facturación. Inténtalo de nuevo en unos minutos.', 'haramara-core' ),
				array( 'status' => 502 )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( 401 === $code || 403 === $code ) {
			$this->log( 'Facturama rejected the credentials (HTTP ' . $code . ').' );
			return new \WP_Error(
				'haramara_pac_auth',
				__( 'El servicio de facturación rechazó las credenciales del café. Avísanos por WhatsApp.', 'haramara-core' ),
				array( 'status' => 502 )
			);
		}

		if ( 400 === $code || 422 === $code ) {
			// Validation: the PAC's own message (RFC not in SAT's list, name
			// mismatch, …) is what the customer needs to see, so pass it through.
			return new \WP_Error(
				'haramara_pac_rejected',
				$this->pac_message( $data ),
				array( 'status' => 400 )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$this->log( 'Facturama unexpected HTTP ' . $code . '.' );
			return new \WP_Error(
				'haramara_pac_error',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'El servicio de facturación respondió con un error (HTTP %d).', 'haramara-core' ),
					$code
				),
				array( 'status' => 502 )
			);
		}

		return $data;
	}

	/**
	 * Fetch a stamped document as base64.
	 *
	 * @param string                                               $id     Facturama invoice id (NOT the SAT UUID).
	 * @param string                                               $type   'pdf' or 'xml'.
	 * @param array{user:string,pass:string,cp:string,base:string} $config Resolved configuration.
	 * @return string|\WP_Error Base64-encoded document bytes.
	 */
	private function document( string $id, string $type, array $config ): string|\WP_Error {
		$data = $this->request( 'GET', '/cfdi/' . $type . '/issued/' . rawurlencode( $id ), null, $config );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$content = (string) ( $data['Content'] ?? '' );
		if ( '' === $content ) {
			$this->log( 'Facturama ' . $type . ' fetch returned no content.' );
			return new \WP_Error(
				'haramara_pac_fetch_failed',
				__( 'La factura se emitió pero no se pudo descargar el documento.', 'haramara-core' ),
				array( 'status' => 502 )
			);
		}

		return $content;
	}

	/**
	 * Human-readable validation message from a Facturama error body: the top
	 * `Message` plus every `ModelState` detail, joined.
	 *
	 * @param array<int|string,mixed> $data Decoded error body.
	 */
	private function pac_message( array $data ): string {
		$parts = array();

		$message = (string) ( $data['Message'] ?? '' );
		if ( '' !== $message ) {
			$parts[] = $message;
		}

		$model_state = $data['ModelState'] ?? null;
		if ( is_array( $model_state ) ) {
			foreach ( $model_state as $errors ) {
				foreach ( (array) $errors as $error ) {
					if ( is_string( $error ) && '' !== trim( $error ) ) {
						$parts[] = trim( $error );
					}
				}
			}
		}

		if ( array() === $parts ) {
			return __( 'El servicio de facturación rechazó los datos. Revisa tu RFC y razón social.', 'haramara-core' );
		}

		return implode( ' ', array_unique( $parts ) );
	}

	/* ---------------------------------------------------------------------- */
	/* Configuration                                                          */
	/* ---------------------------------------------------------------------- */

	/**
	 * Resolved configuration from wp-config constants, or the 503 explaining
	 * that facturación is not set up. Never read from options — mirrors the
	 * WalletPass/Twilio secrets rule.
	 *
	 * @return array{user:string,pass:string,cp:string,base:string}|\WP_Error
	 */
	private static function config(): array|\WP_Error {
		$user = self::constant( 'HARAMARA_FACTURAMA_USER' );
		$pass = self::constant( 'HARAMARA_FACTURAMA_PASS' );
		$cp   = self::constant( 'HARAMARA_FISCAL_CP' );

		if ( '' === $user || '' === $pass || '' === $cp ) {
			return new \WP_Error(
				'haramara_fiscal_not_configured',
				__( 'La facturación no está configurada en este servidor.', 'haramara-core' ),
				array( 'status' => 503 )
			);
		}

		$sandbox = defined( 'HARAMARA_FACTURAMA_SANDBOX' )
			&& filter_var( constant( 'HARAMARA_FACTURAMA_SANDBOX' ), FILTER_VALIDATE_BOOLEAN );

		return array(
			'user' => $user,
			'pass' => $pass,
			'cp'   => $cp,
			'base' => $sandbox ? self::BASE_SANDBOX : self::BASE_PRODUCTION,
		);
	}

	/**
	 * A constant's value when defined and non-empty, else ''.
	 *
	 * @param string $name Constant name.
	 */
	private static function constant( string $name ): string {
		return defined( $name ) ? (string) constant( $name ) : '';
	}

	/**
	 * Safe nested string read from a decoded JSON structure.
	 *
	 * @param array<int|string,mixed> $data Decoded JSON.
	 * @param array<int,string>       $path Keys to descend.
	 */
	private static function dig_string( array $data, array $path ): string {
		$node = $data;
		foreach ( $path as $key ) {
			if ( ! is_array( $node ) || ! isset( $node[ $key ] ) ) {
				return '';
			}
			$node = $node[ $key ];
		}
		return is_scalar( $node ) ? (string) $node : '';
	}

	/**
	 * Debug-only diagnostics. Never includes credentials or receiver data.
	 *
	 * @param string $message What went wrong.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[haramara-core] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only diagnostics.
		}
	}
}
