<?php
/**
 * Staff device push-token registry.
 *
 * Expo push tokens for the POS tablets, stored in a single non-autoloaded
 * option keyed by token — tokens are globally unique per install, so keying by
 * token dedupes re-registrations naturally. Stale entries are pruned on
 * registration and whenever Expo reports DeviceNotRegistered.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Push;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The POS push-token registry.
 */
final class StaffTokens {

	/** Option holding the token registry. */
	public const OPTION = 'haramara_pos_push_tokens';

	/** Registrations older than 90 days are pruned (device likely retired). */
	private const MAX_AGE = 90 * 86400;

	/**
	 * Register (or refresh) a device token.
	 *
	 * @param string $token   Expo push token (ExpoPushToken[…]).
	 * @param int    $user_id Staff user the device is signed in as.
	 */
	public static function register( string $token, int $user_id ): void {
		$tokens = self::load();

		$tokens[ $token ] = array(
			'user_id'       => $user_id,
			'registered_at' => time(),
		);

		self::save( self::prune( $tokens ) );
	}

	/**
	 * Remove a token (sign-out, or Expo reported DeviceNotRegistered).
	 *
	 * @param string $token Expo push token.
	 */
	public static function remove( string $token ): void {
		$tokens = self::load();
		if ( isset( $tokens[ $token ] ) ) {
			unset( $tokens[ $token ] );
			self::save( $tokens );
		}
	}

	/**
	 * All registered device tokens.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array_keys( self::load() );
	}

	/**
	 * The stored registry. Values are typed loosely — the option comes from the
	 * database and prune() revalidates each entry's shape.
	 *
	 * @return array<string,mixed>
	 */
	private static function load(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Persist the registry (never autoloaded).
	 *
	 * @param array<string,mixed> $tokens Registry to store.
	 */
	private static function save( array $tokens ): void {
		update_option( self::OPTION, $tokens, false );
	}

	/**
	 * Drop malformed entries and registrations old enough that the device is
	 * presumed retired.
	 *
	 * @param array<string,mixed> $tokens Registry to filter.
	 * @return array<string,mixed>
	 */
	private static function prune( array $tokens ): array {
		$cutoff = time() - self::MAX_AGE;
		return array_filter(
			$tokens,
			static fn( $entry ): bool => is_array( $entry ) && (int) ( $entry['registered_at'] ?? 0 ) >= $cutoff
		);
	}
}
