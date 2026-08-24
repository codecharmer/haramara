<?php
/**
 * Issued-invoice records (`{prefix}haramara_invoices`) + document storage.
 *
 * One row per invoiced order: the receiver data the customer typed on
 * /factura, the SAT UUID the PAC returned, and the filenames of the stored
 * PDF/XML. `order_id` is UNIQUE — an order is invoiceable exactly once, and
 * the database enforces it even if two issue requests race.
 *
 * The table is NOT created here: Setup\Activator owns table creation and
 * calls dbDelta with schema() — that wiring is an integration seam, see
 * docs/phase6-integration.md.
 *
 * Documents live under wp_upload_dir()/haramara-facturas/ with random
 * filenames and an .htaccess deny — they contain RFCs and razones sociales,
 * so they are only served through the tokened download route in
 * Rest\FiscalRoutes, never by direct URL. (nginx installs must mirror the
 * deny with a location block — see src/Fiscal/README.md.)
 *
 * The customer's email lives ONLY in this table. It is deliberately never
 * written to the WooCommerce order's billing fields: walk-in orders carry no
 * billing contact so order-status notifications never fire.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Fiscal;

use Haramara\Core\Setup\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Invoices {

	/** Invoice-records table basename (prefix added at runtime). */
	public const TABLE = 'haramara_invoices';

	/** Directory under wp_upload_dir()['basedir'] holding the PDFs/XMLs. */
	private const DOCUMENT_DIR = 'haramara-facturas';

	/** Fully-qualified invoices table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * dbDelta CREATE TABLE statement for the invoices table.
	 *
	 * Called by Setup\Activator::create_tables() (integration seam — this
	 * class never runs it). Follows dbDelta's quirks: two spaces after
	 * PRIMARY KEY, one index per line.
	 *
	 * @param string $prefix  Table prefix ($wpdb->prefix).
	 * @param string $charset Charset/collation clause ($wpdb->get_charset_collate()).
	 */
	public static function schema( string $prefix, string $charset ): string {
		$table = $prefix . self::TABLE;

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			folio VARCHAR(40) NOT NULL,
			uuid VARCHAR(40) NOT NULL DEFAULT '',
			rfc VARCHAR(13) NOT NULL,
			razon_social VARCHAR(254) NOT NULL,
			uso_cfdi VARCHAR(4) NOT NULL,
			regimen VARCHAR(4) NOT NULL,
			cp VARCHAR(5) NOT NULL,
			email VARCHAR(254) NOT NULL,
			pdf_path VARCHAR(120) NOT NULL DEFAULT '',
			xml_path VARCHAR(120) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY folio (folio),
			KEY uuid (uuid),
			KEY created_at (created_at)
		) {$charset};";
	}

	/**
	 * Record an issued invoice.
	 *
	 * @param int                  $order_id WooCommerce order ID.
	 * @param array<string,string> $data     Columns: folio, uuid, rfc, razon_social,
	 *                                       uso_cfdi, regimen, cp, email, pdf_path, xml_path.
	 * @return int|\WP_Error New row ID, or `haramara_invoice_exists` (409) when
	 *                       the UNIQUE order_id key rejects a duplicate.
	 */
	public static function record( int $order_id, array $data ): int|\WP_Error {
		global $wpdb;

		// Café-local timestamp, NOT current_time(): recent() and any future
		// per-day reporting slice this column with days resolved in
		// Options::timezone(), mirroring Woo\Withdrawals::create().
		$created_at = ( new \DateTimeImmutable( 'now', Options::timezone() ) )->format( 'Y-m-d H:i:s' );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'order_id'     => $order_id,
				'folio'        => mb_substr( (string) ( $data['folio'] ?? '' ), 0, 40 ),
				'uuid'         => mb_substr( (string) ( $data['uuid'] ?? '' ), 0, 40 ),
				'rfc'          => mb_substr( (string) ( $data['rfc'] ?? '' ), 0, 13 ),
				'razon_social' => mb_substr( (string) ( $data['razon_social'] ?? '' ), 0, 254 ),
				'uso_cfdi'     => mb_substr( (string) ( $data['uso_cfdi'] ?? '' ), 0, 4 ),
				'regimen'      => mb_substr( (string) ( $data['regimen'] ?? '' ), 0, 4 ),
				'cp'           => mb_substr( (string) ( $data['cp'] ?? '' ), 0, 5 ),
				'email'        => mb_substr( (string) ( $data['email'] ?? '' ), 0, 254 ),
				'pdf_path'     => mb_substr( (string) ( $data['pdf_path'] ?? '' ), 0, 120 ),
				'xml_path'     => mb_substr( (string) ( $data['xml_path'] ?? '' ), 0, 120 ),
				'created_at'   => $created_at,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return new \WP_Error(
				'haramara_invoice_exists',
				__( 'Este ticket ya fue facturado.', 'haramara-core' ),
				array( 'status' => 409 )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Invoice row for an order, or null when the order was never invoiced.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array<string,mixed>|null
	 */
	public static function for_order( int $order_id ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d LIMIT 1", $order_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Invoice row by its own ID (download tokens carry this).
	 *
	 * @param int $id Invoice row ID.
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Invoice row for a POS ticket folio, or null.
	 *
	 * @param string $folio Ticket folio as printed.
	 * @return array<string,mixed>|null
	 */
	public static function by_folio( string $folio ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE folio = %s LIMIT 1", $folio ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'ARRAY_A'
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Most recent invoices, newest first (admin/ops reads).
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 20 ): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 1, $limit )
			),
			'ARRAY_A'
		);

		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/* ---------------------------------------------------------------------- */
	/* Document storage                                                       */
	/* ---------------------------------------------------------------------- */

	/**
	 * Persist the stamped PDF + XML under the protected uploads directory.
	 *
	 * Filenames are random (32 alnum chars) so they cannot be guessed even if
	 * the .htaccess deny is ever lost; only the filename is stored in the row
	 * (never an absolute path), so backups/migrations survive a basedir move.
	 *
	 * @param string $pdf_b64 Base64-encoded PDF bytes.
	 * @param string $xml_b64 Base64-encoded XML bytes.
	 * @return array{pdf:string,xml:string}|\WP_Error Stored filenames.
	 */
	public static function store_documents( string $pdf_b64, string $xml_b64 ): array|\WP_Error {
		$dir = self::ensure_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$stored = array();
		foreach ( array(
			'pdf' => $pdf_b64,
			'xml' => $xml_b64,
		) as $type => $b64 ) {
			$bytes = base64_decode( $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the PAC's own base64 payload.
			if ( false === $bytes || '' === $bytes ) {
				return self::store_error();
			}

			$name = 'f-' . strtolower( wp_generate_password( 32, false, false ) ) . '.' . $type;
			if ( false === file_put_contents( $dir . '/' . $name, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- protected private storage, served only via the tokened route.
				return self::store_error();
			}

			$stored[ $type ] = $name;
		}

		return array(
			'pdf' => (string) $stored['pdf'],
			'xml' => (string) $stored['xml'],
		);
	}

	/**
	 * Absolute path for a stored document filename, or null when it does not
	 * exist. basename() confines the lookup to the facturas directory no
	 * matter what the row contains.
	 *
	 * @param string $filename Stored filename (pdf_path / xml_path column).
	 */
	public static function document_path( string $filename ): ?string {
		$filename = basename( $filename );
		if ( '' === $filename ) {
			return null;
		}

		$dir = self::ensure_dir();
		if ( is_wp_error( $dir ) ) {
			return null;
		}

		$path = $dir . '/' . $filename;

		return is_readable( $path ) ? $path : null;
	}

	/* ---------------------------------------------------------------------- */
	/* Internals                                                              */
	/* ---------------------------------------------------------------------- */

	/**
	 * The facturas directory, created and access-guarded on first use.
	 *
	 * @return string|\WP_Error Absolute directory path (no trailing slash).
	 */
	private static function ensure_dir(): string|\WP_Error {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) {
			return self::store_error();
		}

		$dir = (string) $uploads['basedir'] . '/' . self::DOCUMENT_DIR;
		if ( ! wp_mkdir_p( $dir ) ) {
			return self::store_error();
		}

		// Apache deny (2.4 and 2.2 syntax). nginx ignores .htaccess — the ops
		// runbook (src/Fiscal/README.md) carries the matching location block.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
			file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- writing the deny rules that protect this directory.
		}

		// Belt-and-suspenders against directory listing where the deny is not honored.
		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- empty listing guard.
		}

		return $dir;
	}

	/** Uniform 500 for storage failures. Detail never reaches the visitor. */
	private static function store_error(): \WP_Error {
		return new \WP_Error(
			'haramara_invoice_store_failed',
			__( 'No se pudo guardar la factura en el servidor.', 'haramara-core' ),
			array( 'status' => 500 )
		);
	}
}
