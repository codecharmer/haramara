<?php
/**
 * Activation / deactivation lifecycle.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Setup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Activator {

	/** Shared table basename (prefix added at runtime). Mirrored by Sms\Logger. */
	public const SMS_TABLE = 'haramara_sms_log';

	/** Capability that gates the Haramara operations dashboard. */
	public const CAP = 'manage_haramara';

	public static function activate(): void {
		self::create_tables();
		Options::install_defaults();
		self::grant_caps();

		// Defer heavy content seeding to an admin-time one-shot so activation stays fast.
		if ( false === get_option( 'haramara_content_installed' ) ) {
			add_option( 'haramara_needs_content_install', 1 );
		}

		if ( ! wp_next_scheduled( 'haramara_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'haramara_daily_maintenance' );
		}

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'haramara_daily_maintenance' );
		flush_rewrite_rules();
	}

	/**
	 * SMS message log (inbound + outbound), used by Sms\Logger and the admin SMS screen.
	 */
	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . self::SMS_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			direction VARCHAR(10) NOT NULL DEFAULT 'outbound',
			order_id BIGINT UNSIGNED NULL DEFAULT NULL,
			recipient VARCHAR(32) NOT NULL DEFAULT '',
			sender VARCHAR(32) NOT NULL DEFAULT '',
			body TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			provider_sid VARCHAR(64) NULL DEFAULT NULL,
			error TEXT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY direction (direction),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Grant the operations capability to admins and shop managers.
	 */
	private static function grant_caps(): void {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::CAP ) ) {
				$role->add_cap( self::CAP );
			}
		}
	}
}
