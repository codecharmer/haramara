<?php
/**
 * Public /factura REST surface (autofactura CFDI 4.0).
 *
 * The printed counter ticket carries a folio + QR to the /factura page; there
 * the customer proves possession with folio + total, types their fiscal data,
 * and a PAC stamps the CFDI. Routes (namespace haramara/v1):
 *
 *   POST /factura/validate  {folio, total}         → order summary
 *   POST /factura/issue     {folio, total, rfc, …} → stamp + email + download tokens
 *   GET  /factura/download  ?token=                → streams the PDF or XML
 *
 * Public (no auth) but heavily guarded: per-IP transient rate limit on
 * validate/issue, possession proof via the folio+total pair (unknown folio and
 * wrong total are the same 404), HMAC download tokens (wp_salt-keyed, invoice
 * id + type + expiry — mirroring the Loyalty\Members token idiom), and
 * Cache-Control: no-store on every response.
 *
 * Folio → order resolution is Phase 2 territory: this class holds a
 * Fiscal\FolioResolver, shipped as the PendingFolioResolver stub (always 503,
 * so the page shows "aún no disponible"). The swap to the real resolver is
 * the one-line default change in __construct() — docs/phase6-integration.md.
 *
 * PRODUCT RULE: the email captured here is delivery-only. It is written to the
 * haramara_invoices table and used by wp_mail — NEVER to the order's billing
 * fields (walk-in orders deliberately carry no billing contact so order-status
 * notifications never fire), and never through Sms/* or Push/*.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Rest;

use Haramara\Core\Contracts\Bootable;
use Haramara\Core\Fiscal\FacturamaClient;
use Haramara\Core\Fiscal\FolioResolver;
use Haramara\Core\Fiscal\Invoices;
use Haramara\Core\Fiscal\PacClient;
use Haramara\Core\Fiscal\PendingFolioResolver;
use Haramara\Core\Ordering\WalkInOrders;
use Haramara\Core\Setup\Activator;
use Haramara\Core\Setup\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FiscalRoutes implements Bootable {

	private const NS = 'haramara/v1';

	/** Max validate+issue attempts per IP per hour (shared counter). */
	private const RATE_MAX = 10;

	/** Download-token lifetime: 7 days, in seconds. */
	private const TOKEN_TTL = 7 * 24 * 3600;

	/**
	 * SAT c_UsoCFDI values the issue endpoint accepts (the page's select
	 * offers a subset — src/Fiscal/README.md).
	 */
	private const USO_CFDI = array( 'G01', 'G02', 'G03', 'I01', 'I02', 'I03', 'I04', 'I05', 'I06', 'I07', 'I08', 'D01', 'D02', 'D03', 'D04', 'D05', 'D06', 'D07', 'D08', 'D09', 'D10', 'S01', 'CP01', 'CN01' );

	/** SAT c_RegimenFiscal values the issue endpoint accepts. */
	private const REGIMEN = array( '601', '603', '605', '606', '607', '608', '610', '611', '612', '614', '615', '616', '620', '621', '622', '623', '624', '625', '626' );

	private FolioResolver $resolver;

	private PacClient $pac;

	/**
	 * The Phase 2 seam: swapping PendingFolioResolver for the real resolver is
	 * a one-line change of the default below (docs/phase6-integration.md).
	 *
	 * @param FolioResolver|null $resolver Folio → order resolution.
	 * @param PacClient|null     $pac      CFDI-stamping PAC client.
	 */
	public function __construct( ?FolioResolver $resolver = null, ?PacClient $pac = null ) {
		$this->resolver = $resolver ?? new \Haramara\Core\Fiscal\OrderFolioResolver();
		$this->pac      = $pac ?? new FacturamaClient();
	}

	/**
	 * Hook the REST surface.
	 */
	public function boot(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Route definitions.
	 */
	public function register_routes(): void {
		$text_arg = static function ( string $description, bool $required = true ): array {
			return array(
				'required'          => $required,
				'type'              => 'string',
				'description'       => $description,
				'sanitize_callback' => static fn( mixed $value ): string => sanitize_text_field( (string) $value ),
			);
		};

		$proof_args = array(
			'folio' => $text_arg( __( 'Folio impreso en el ticket.', 'haramara-core' ) ),
			'total' => array(
				'required'          => true,
				'type'              => 'number',
				'description'       => __( 'Total del ticket en MXN, IVA incluido.', 'haramara-core' ),
				'sanitize_callback' => static fn( mixed $value ): float => round( (float) $value, 2 ),
			),
		);

		register_rest_route(
			self::NS,
			'/factura/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate' ),
				'permission_callback' => '__return_true',
				'args'                => $proof_args,
			)
		);

		register_rest_route(
			self::NS,
			'/factura/issue',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'issue' ),
				'permission_callback' => '__return_true',
				'args'                => $proof_args + array(
					'rfc'            => $text_arg( __( 'RFC del receptor.', 'haramara-core' ) ),
					'razon_social'   => $text_arg( __( 'Razón social del receptor (sin régimen societario).', 'haramara-core' ) ),
					'uso_cfdi'       => $text_arg( __( 'Uso de CFDI (catálogo SAT).', 'haramara-core' ) ),
					'regimen_fiscal' => $text_arg( __( 'Régimen fiscal del receptor (catálogo SAT).', 'haramara-core' ) ),
					'cp'             => $text_arg( __( 'Código postal fiscal del receptor.', 'haramara-core' ) ),
					'email'          => array(
						'required'          => true,
						'type'              => 'string',
						'description'       => __( 'Correo donde se entrega la factura.', 'haramara-core' ),
						'sanitize_callback' => static fn( mixed $value ): string => sanitize_email( (string) $value ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/factura/download',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => $text_arg( __( 'Token firmado de descarga.', 'haramara-core' ), false ),
					'id'    => array(
						'required'          => false,
						'type'              => 'integer',
						'description'       => __( 'ID de la factura (solo personal autenticado).', 'haramara-core' ),
						'sanitize_callback' => static fn( mixed $value ): int => (int) $value,
					),
					'type'  => $text_arg( __( 'Documento: pdf o xml.', 'haramara-core' ), false ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------- */
	/* Handlers                                                               */
	/* ---------------------------------------------------------------------- */

	/**
	 * POST /factura/validate — prove possession, preview what will be invoiced.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function validate( \WP_REST_Request $request ): \WP_REST_Response {
		$guard = $this->guards();
		if ( is_wp_error( $guard ) ) {
			return $this->error_response( $guard );
		}

		$order = $this->resolve_order( $request );
		if ( is_wp_error( $order ) ) {
			return $this->error_response( $order );
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'     => $item->get_name(),
				'quantity' => (int) $item->get_quantity(),
			);
		}

		$created = $order->get_date_created();
		$date    = null !== $created
			? $created->setTimezone( Options::timezone() )->format( 'Y-m-d H:i' )
			: '';

		return $this->respond(
			array(
				'folio' => (string) $request['folio'],
				'date'  => $date,
				'items' => $items,
				'total' => round( (float) $order->get_total(), 2 ),
			)
		);
	}

	/**
	 * POST /factura/issue — stamp the CFDI, store + email the documents.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function issue( \WP_REST_Request $request ): \WP_REST_Response {
		$guard = $this->guards();
		if ( is_wp_error( $guard ) ) {
			return $this->error_response( $guard );
		}

		$order = $this->resolve_order( $request );
		if ( is_wp_error( $order ) ) {
			return $this->error_response( $order );
		}

		$receiver = $this->receiver_from( $request );
		if ( is_wp_error( $receiver ) ) {
			return $this->error_response( $receiver );
		}

		$folio = (string) $request['folio'];
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'product_id' => $item instanceof \WC_Order_Item_Product ? $item->get_product_id() : 0,
				'name'       => $item->get_name(),
				'quantity'   => max( 1, (int) $item->get_quantity() ),
				'total'      => round( (float) $order->get_line_total( $item, true ), 2 ),
			);
		}

		$issued = $this->pac->issue(
			array(
				'folio'        => $folio,
				'total'        => round( (float) $order->get_total(), 2 ),
				'payment_form' => $this->payment_form( $order ),
				'receiver'     => array(
					'rfc'           => $receiver['rfc'],
					'name'          => $receiver['razon_social'],
					'cfdi_use'      => $receiver['uso_cfdi'],
					'fiscal_regime' => $receiver['regimen'],
					'zip'           => $receiver['cp'],
				),
				'items'        => $items,
			)
		);

		if ( is_wp_error( $issued ) ) {
			// A fetch-failed error carries the uuid: the CFDI EXISTS at the PAC,
			// so record it (without documents) or a retry would stamp it twice.
			$data = $issued->get_error_data();
			$uuid = is_array( $data ) ? (string) ( $data['uuid'] ?? '' ) : '';
			if ( '' !== $uuid ) {
				$stamped = array(
					'folio' => $folio,
					'uuid'  => $uuid,
				);
				Invoices::record( $order->get_id(), $stamped + $receiver );
			}
			return $this->error_response( $issued );
		}

		$paths = Invoices::store_documents( $issued['pdf_b64'], $issued['xml_b64'] );
		if ( is_wp_error( $paths ) ) {
			// Invoice exists at the PAC — record it anyway (no documents) so
			// the folio cannot be double-invoiced; downloads just won't offer.
			$this->log( 'Invoice document storage failed: ' . $paths->get_error_code() );
			$paths = array(
				'pdf' => '',
				'xml' => '',
			);
		}

		$invoice_id = Invoices::record(
			$order->get_id(),
			array(
				'folio'    => $folio,
				'uuid'     => $issued['uuid'],
				'pdf_path' => $paths['pdf'],
				'xml_path' => $paths['xml'],
			) + $receiver
		);

		$downloads = null;
		if ( ! is_wp_error( $invoice_id ) ) {
			$downloads = array(
				'pdf' => $this->download_token( $invoice_id, 'pdf' ),
				'xml' => $this->download_token( $invoice_id, 'xml' ),
			);
		} else {
			$this->log( 'Invoice record failed after stamping: ' . $invoice_id->get_error_code() );
		}

		$email_sent = $this->send_documents( $receiver['email'], $folio, $issued, $paths );

		return $this->respond(
			array(
				'uuid'         => $issued['uuid'],
				'series'       => $issued['series'],
				'folio_fiscal' => $issued['folio_fiscal'],
				'email_sent'   => $email_sent,
				'downloads'    => $downloads,
			),
			201
		);
	}

	/**
	 * GET /factura/download — stream the PDF or XML.
	 *
	 * Tokened (the normal customer path) OR authenticated: staff holding the
	 * POS capability may fetch by invoice id + type without a token.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 */
	public function download( \WP_REST_Request $request ): \WP_REST_Response {
		$token = (string) $request['token'];

		if ( '' !== $token ) {
			$claim = $this->verify_token( $token );
		} elseif ( current_user_can( Activator::CAP ) ) {
			$claim = array(
				'id'   => (int) $request['id'],
				'type' => (string) $request['type'],
			);
		} else {
			$claim = new \WP_Error(
				'haramara_factura_forbidden',
				__( 'Falta el token de descarga.', 'haramara-core' ),
				array( 'status' => 403 )
			);
		}

		if ( is_wp_error( $claim ) ) {
			return $this->error_response( $claim );
		}

		$not_found = new \WP_Error(
			'haramara_factura_document_missing',
			__( 'El documento ya no está disponible. Escríbenos por WhatsApp y te lo reenviamos.', 'haramara-core' ),
			array( 'status' => 404 )
		);

		if ( ! in_array( $claim['type'], array( 'pdf', 'xml' ), true ) ) {
			return $this->error_response( $not_found );
		}

		$invoice = Invoices::find( (int) $claim['id'] );
		if ( null === $invoice ) {
			return $this->error_response( $not_found );
		}

		$column = 'pdf' === $claim['type'] ? 'pdf_path' : 'xml_path';
		$path   = Invoices::document_path( (string) ( $invoice[ $column ] ?? '' ) );
		if ( null === $path ) {
			return $this->error_response( $not_found );
		}

		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- protected private storage this plugin wrote.
		if ( false === $bytes ) {
			return $this->error_response( $not_found );
		}

		$filename = 'factura-' . sanitize_file_name( (string) $invoice['folio'] ) . '.' . $claim['type'];

		$response = new \WP_REST_Response( null, 200 );
		$response->header( 'Content-Type', 'pdf' === $claim['type'] ? 'application/pdf' : 'application/xml' );
		$response->header( 'Content-Disposition', 'attachment; filename="' . $filename . '"' );
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Content-Length', (string) strlen( $bytes ) );

		// The REST server would JSON-encode (and corrupt) a binary body, so
		// emit the bytes ourselves — same identity-guarded idiom as
		// Loyalty\WalletPass::get_pass().
		add_filter(
			'rest_pre_serve_request',
			static function ( bool $served, \WP_HTTP_Response $result ) use ( $bytes, $response ): bool {
				if ( $result !== $response ) {
					return $served;
				}
				echo $bytes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw PDF/XML bytes stored by this plugin.
				return true;
			},
			10,
			2
		);

		return $response;
	}

	/* ---------------------------------------------------------------------- */
	/* Guards                                                                 */
	/* ---------------------------------------------------------------------- */

	/**
	 * Shared entry guards for validate/issue: rate limit, then PAC availability.
	 *
	 * @return true|\WP_Error
	 */
	private function guards(): true|\WP_Error {
		$limited = $this->rate_limit();
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		if ( ! $this->pac->is_configured() ) {
			return new \WP_Error(
				'haramara_fiscal_not_configured',
				__( 'La facturación no está configurada en este servidor.', 'haramara-core' ),
				array( 'status' => 503 )
			);
		}

		return true;
	}

	/**
	 * Per-IP transient counter, RATE_MAX per hour across validate+issue.
	 * Each attempt re-arms the hour (sliding window) — the cheap shape for a
	 * guard whose only job is to make folio guessing pointless.
	 *
	 * @return true|\WP_Error
	 */
	private function rate_limit(): true|\WP_Error {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
			: '';

		// Hashed: transient names must stay short, and the raw IP has no
		// business being readable in the options table.
		$key   = 'haramara_fiscal_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_MAX ) {
			return new \WP_Error(
				'haramara_fiscal_rate_limited',
				__( 'Demasiados intentos. Espera una hora e inténtalo de nuevo.', 'haramara-core' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, $count + 1, 3600 ); // One hour.

		return true;
	}

	/**
	 * Folio + total → invoiceable order: resolver, order load, not-yet-invoiced.
	 *
	 * @param \WP_REST_Request $request Incoming request (folio + total args).
	 * @return \WC_Order|\WP_Error
	 */
	private function resolve_order( \WP_REST_Request $request ): \WC_Order|\WP_Error {
		$order_id = $this->resolver->resolve( (string) $request['folio'], (float) $request['total'] );
		if ( is_wp_error( $order_id ) ) {
			return $order_id;
		}

		$not_found = new \WP_Error(
			'haramara_factura_not_found',
			__( 'No encontramos un ticket con ese folio y total. Revisa los datos impresos.', 'haramara-core' ),
			array( 'status' => 404 )
		);

		if ( ! function_exists( 'wc_get_order' ) ) {
			return $not_found;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return $not_found;
		}

		if ( null !== Invoices::for_order( $order->get_id() ) ) {
			return new \WP_Error(
				'haramara_factura_exists',
				__( 'Este ticket ya fue facturado. Revisa tu correo o escríbenos por WhatsApp.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		return $order;
	}

	/**
	 * Validate + normalize the receiver fields from the issue request.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return array{rfc:string,razon_social:string,uso_cfdi:string,regimen:string,cp:string,email:string}|\WP_Error
	 */
	private function receiver_from( \WP_REST_Request $request ): array|\WP_Error {
		$rfc     = mb_strtoupper( trim( (string) $request['rfc'] ) );
		$name    = mb_strtoupper( trim( (string) $request['razon_social'] ) );
		$uso     = strtoupper( trim( (string) $request['uso_cfdi'] ) );
		$regimen = trim( (string) $request['regimen_fiscal'] );
		$cp      = trim( (string) $request['cp'] );
		$email   = (string) $request['email'];

		if ( ! $this->valid_rfc( $rfc ) ) {
			return $this->invalid( __( 'El RFC no tiene un formato válido.', 'haramara-core' ) );
		}
		if ( '' === $name ) {
			return $this->invalid( __( 'Escribe la razón social tal como aparece en tu constancia, sin el régimen societario.', 'haramara-core' ) );
		}
		if ( ! in_array( $uso, self::USO_CFDI, true ) ) {
			return $this->invalid( __( 'El uso de CFDI no es válido.', 'haramara-core' ) );
		}
		if ( ! in_array( $regimen, self::REGIMEN, true ) ) {
			return $this->invalid( __( 'El régimen fiscal no es válido.', 'haramara-core' ) );
		}
		if ( 1 !== preg_match( '/^\d{5}$/', $cp ) ) {
			return $this->invalid( __( 'El código postal fiscal debe tener 5 dígitos.', 'haramara-core' ) );
		}
		if ( '' === $email || ! is_email( $email ) ) {
			return $this->invalid( __( 'Escribe un correo electrónico válido.', 'haramara-core' ) );
		}

		return array(
			'rfc'          => $rfc,
			'razon_social' => $name,
			'uso_cfdi'     => $uso,
			'regimen'      => $regimen,
			'cp'           => $cp,
			'email'        => $email,
		);
	}

	/**
	 * RFC shape check: 3-letter root (morales, 12 chars) or 4-letter root
	 * (físicas, 13 chars), then date digits and homoclave. Covers the SAT
	 * generic RFCs (XAXX010101000 / XEXX010101000). Existence is the PAC's
	 * job — its rejection message passes through to the customer.
	 *
	 * @param string $rfc Uppercased, trimmed RFC.
	 */
	private function valid_rfc( string $rfc ): bool {
		return 1 === preg_match( '/^([A-ZÑ&]{3}|[A-ZÑ&]{4})\d{6}[A-Z\d]{3}$/u', $rfc );
	}

	/**
	 * SAT c_FormaPago for the order: how the counter sale was actually paid.
	 * 01 efectivo, 04 tarjeta — walk-ins record the real mode in POS meta;
	 * online Stripe orders are card.
	 *
	 * @param \WC_Order $order The order being invoiced.
	 */
	private function payment_form( \WC_Order $order ): string {
		$pos_payment = (string) $order->get_meta( WalkInOrders::META_PAYMENT );

		if ( 'card_external' === $pos_payment ) {
			$form = '04';
		} elseif ( 'cash' === $pos_payment ) {
			$form = '01';
		} elseif ( 'stripe' === $order->get_payment_method() ) {
			$form = '04';
		} else {
			$form = '01';
		}

		/**
		 * Filter the SAT c_FormaPago stamped on an autofactura.
		 *
		 * @param string    $form  SAT payment-form code.
		 * @param \WC_Order $order The order being invoiced.
		 */
		return (string) apply_filters( 'haramara_fiscal_payment_form', $form, $order );
	}

	/* ---------------------------------------------------------------------- */
	/* Download tokens                                                        */
	/* ---------------------------------------------------------------------- */

	/**
	 * Signed download token: `id.type.expires.hmac` (wp_salt-keyed), the same
	 * idiom as the Loyalty\Members card token. Carries no RFC and no email —
	 * personal data never travels in URLs.
	 *
	 * @param int    $invoice_id Invoice row ID.
	 * @param string $type       'pdf' or 'xml'.
	 */
	private function download_token( int $invoice_id, string $type ): string {
		$expires = time() + self::TOKEN_TTL;
		$payload = $invoice_id . '.' . $type . '.' . $expires;

		return $payload . '.' . $this->sign( $payload );
	}

	/**
	 * Validate a download token.
	 *
	 * @param string $token Presented token.
	 * @return array{id:int,type:string}|\WP_Error
	 */
	private function verify_token( string $token ): array|\WP_Error {
		$invalid = new \WP_Error(
			'haramara_factura_bad_token',
			__( 'El enlace de descarga no es válido o ya venció. Solicita la factura de nuevo o escríbenos por WhatsApp.', 'haramara-core' ),
			array( 'status' => 403 )
		);

		$parts = explode( '.', $token );
		if ( 4 !== count( $parts ) ) {
			return $invalid;
		}

		list( $id, $type, $expires, $signature ) = $parts;

		if ( ! hash_equals( $this->sign( $id . '.' . $type . '.' . $expires ), $signature ) ) {
			return $invalid;
		}
		if ( (int) $expires < time() ) {
			return $invalid;
		}

		return array(
			'id'   => (int) $id,
			'type' => $type,
		);
	}

	private function sign( string $payload ): string {
		return hash_hmac( 'sha256', 'haramara-factura|' . $payload, wp_salt( 'auth' ) );
	}

	/* ---------------------------------------------------------------------- */
	/* Delivery                                                               */
	/* ---------------------------------------------------------------------- */

	/**
	 * Email the stamped documents through wp_mail — this flow's OWN mailer.
	 * Never routed through Sms\OrderNotifications or Push\OrderStatusNotifier,
	 * and the address never touches the order.
	 *
	 * @param string                                                                        $email  Delivery address.
	 * @param string                                                                        $folio  POS ticket folio.
	 * @param array{uuid:string,pdf_b64:string,xml_b64:string,series:string,folio_fiscal:string} $issued PAC result.
	 * @param array{pdf:string,xml:string}                                                  $paths  Stored filenames ('' when storage failed).
	 * @return bool Whether wp_mail accepted the message.
	 */
	private function send_documents( string $email, string $folio, array $issued, array $paths ): bool {
		$business = Options::business();

		$subject = sprintf(
			/* translators: %s: POS ticket folio. */
			__( 'Tu factura de Haramara Café — ticket %s', 'haramara-core' ),
			$folio
		);

		$lines = array(
			__( 'Hola:', 'haramara-core' ),
			'',
			sprintf(
				/* translators: %s: POS ticket folio. */
				__( 'Adjuntamos tu factura (CFDI) del ticket %s de Haramara Café, en PDF y XML.', 'haramara-core' ),
				$folio
			),
			'',
			sprintf(
				/* translators: %s: SAT UUID (folio fiscal). */
				__( 'Folio fiscal (UUID): %s', 'haramara-core' ),
				$issued['uuid']
			),
			'',
			sprintf(
				/* translators: %s: WhatsApp link. */
				__( '¿Dudas con tu factura? Escríbenos por WhatsApp: %s', 'haramara-core' ),
				(string) ( $business['whatsapp'] ?? '' )
			),
			'',
			'Haramara Café',
		);

		$attachments = array();
		foreach ( array( $paths['pdf'], $paths['xml'] ) as $filename ) {
			$path = Invoices::document_path( $filename );
			if ( null !== $path ) {
				$attachments[] = $path;
			}
		}

		return wp_mail( $email, $subject, implode( "\n", $lines ), array(), $attachments );
	}

	/* ---------------------------------------------------------------------- */
	/* Response plumbing                                                      */
	/* ---------------------------------------------------------------------- */

	/**
	 * Success envelope, caching disabled (the flow mutates state and the
	 * proxy caches header-less GETs — cf. Loyalty\Members::respond()).
	 *
	 * @param array<string,mixed> $data   Response body.
	 * @param int                 $status HTTP status.
	 */
	private function respond( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Error envelope in WP_Error's REST JSON shape, but built by hand so the
	 * no-store header rides along — "every response no-store" includes errors.
	 *
	 * @param \WP_Error $error The error to serialize.
	 */
	private function error_response( \WP_Error $error ): \WP_REST_Response {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500;

		return $this->respond(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}

	/** 422 shorthand for receiver-field validation. */
	private function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'haramara_fiscal_invalid', $message, array( 'status' => 422 ) );
	}

	/**
	 * Debug-only diagnostics. Never includes receiver data or credentials.
	 *
	 * @param string $message What went wrong.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[haramara-core] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only diagnostics.
		}
	}
}
