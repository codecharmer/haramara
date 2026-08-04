<?php
/**
 * SMS message log.
 *
 * Persists every inbound/outbound message to the custom table created by
 * Setup\Activator (`{prefix}haramara_sms_log`) and exposes read accessors for
 * the admin "SMS" screen. Also schedules a light daily prune of very old rows.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Sms;

use Haramara\Core\Contracts\Bootable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Logger implements Bootable {

	/** Table basename — mirrors Setup\Activator::SMS_TABLE. */
	private const TABLE = 'haramara_sms_log';

	/** Prune cron hook. */
	private const PRUNE_HOOK = 'haramara_sms_prune';

	/** Rows older than this many days are pruned. */
	private const RETENTION_DAYS = 180;

	public function boot(): void {
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune' ) );

		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	/** Fully-qualified log table name. */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Insert a log row. Missing fields fall back to safe defaults.
	 *
	 * @param array<string,mixed> $row {
	 *     direction, order_id, recipient, sender, body, status, provider_sid, error.
	 * }
	 * @return int Inserted row id, or 0 on failure.
	 */
	public static function record( array $row ): int {
		global $wpdb;

		$direction = in_array( ( $row['direction'] ?? '' ), array( 'inbound', 'outbound' ), true )
			? (string) $row['direction']
			: 'outbound';

		$data   = array(
			'created_at'   => current_time( 'mysql' ), // Site timezone.
			'direction'    => $direction,
			'recipient'    => substr( sanitize_text_field( (string) ( $row['recipient'] ?? '' ) ), 0, 32 ),
			'sender'       => substr( sanitize_text_field( (string) ( $row['sender'] ?? '' ) ), 0, 32 ),
			'body'         => sanitize_textarea_field( (string) ( $row['body'] ?? '' ) ),
			'status'       => substr( sanitize_key( (string) ( $row['status'] ?? 'queued' ) ), 0, 20 ),
			'provider_sid' => substr( sanitize_text_field( (string) ( $row['provider_sid'] ?? '' ) ), 0, 64 ),
			'error'        => sanitize_textarea_field( (string) ( $row['error'] ?? '' ) ),
		);
		$format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

		// order_id is nullable; only bind it when present.
		if ( isset( $row['order_id'] ) && '' !== (string) $row['order_id'] ) {
			$data['order_id'] = (int) $row['order_id'];
			$format[]         = '%d';
		}

		$ok = $wpdb->insert( self::table(), $data, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Most recent log rows for the admin screen.
	 *
	 * @param int                 $limit Max rows.
	 * @param array<string,mixed> $args  Optional filters: direction, order_id, status.
	 * @return array<int,array<string,mixed>>
	 */
	public static function recent( int $limit = 50, array $args = array() ): array {
		global $wpdb;

		$table  = self::table();
		$where  = array();
		$params = array();

		if ( ! empty( $args['direction'] ) ) {
			$where[]  = 'direction = %s';
			$params[] = (string) $args['direction'];
		}
		if ( ! empty( $args['order_id'] ) ) {
			$where[]  = 'order_id = %d';
			$params[] = (int) $args['order_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = sanitize_key( (string) $args['status'] );
		}

		$sql = "SELECT * FROM {$table}";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql     .= ' ORDER BY id DESC LIMIT %d';
		$params[] = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Delete rows older than the retention window. Cheap, index-backed on created_at.
	 */
	public static function prune(): void {
		global $wpdb;

		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}
}
