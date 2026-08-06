<?php
/**
 * Expo push sender.
 *
 * Thin wrapper over Expo's push HTTP API (https://exp.host/--/api/v2/push/send).
 * Fire-and-forget by design: transport failures are logged under WP_DEBUG and
 * never bubble up — a push must never fatal the payment hook it rides on.
 * Tokens Expo reports as DeviceNotRegistered are handed to a caller-supplied
 * pruner (the staff registry by default).
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Push;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends push notifications through Expo.
 */
final class ExpoPush {

	private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

	/** Expo accepts at most 100 messages per request. */
	private const CHUNK = 100;

	/**
	 * Send one notification to a set of device tokens.
	 *
	 * @param string[]                      $tokens           Expo push tokens.
	 * @param string                        $title            Notification title.
	 * @param string                        $body             Notification body.
	 * @param array<string,mixed>           $data             Payload delivered to the app.
	 * @param (callable(string): void)|null $on_invalid_token Called with each token Expo reports
	 *                                                        DeviceNotRegistered; defaults to
	 *                                                        pruning the staff registry.
	 */
	public static function send( array $tokens, string $title, string $body, array $data = array(), ?callable $on_invalid_token = null ): void {
		$tokens = array_values( array_filter( array_map( 'strval', $tokens ) ) );
		if ( empty( $tokens ) ) {
			return;
		}

		$on_invalid_token ??= static fn( string $token ) => StaffTokens::remove( $token );

		foreach ( array_chunk( $tokens, self::CHUNK ) as $chunk ) {
			$messages = array();
			foreach ( $chunk as $token ) {
				$messages[] = array(
					'to'        => $token,
					'title'     => $title,
					'body'      => $body,
					'data'      => $data,
					'sound'     => 'default',
					'channelId' => 'orders',
					'priority'  => 'high',
				);
			}

			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout' => 5,
					'headers' => array(
						'Content-Type' => 'application/json',
						'Accept'       => 'application/json',
					),
					'body'    => wp_json_encode( $messages ),
				)
			);

			if ( is_wp_error( $response ) ) {
				self::log( 'Expo push request failed: ' . $response->get_error_message() );
				continue;
			}

			self::handle_tickets( $chunk, $response, $on_invalid_token );
		}
	}

	/**
	 * Inspect the per-message tickets; prune tokens Expo no longer recognises.
	 *
	 * @param string[]               $chunk            Tokens in the order they were sent.
	 * @param array<string,mixed>    $response         wp_remote_post response.
	 * @param callable(string): void $on_invalid_token Pruner for DeviceNotRegistered tokens.
	 */
	private static function handle_tickets( array $chunk, array $response, callable $on_invalid_token ): void {
		$payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$tickets = is_array( $payload ) && is_array( $payload['data'] ?? null ) ? $payload['data'] : array();

		foreach ( array_values( $tickets ) as $i => $ticket ) {
			if ( ! is_array( $ticket ) || 'error' !== ( $ticket['status'] ?? '' ) ) {
				continue;
			}

			$error = (string) ( $ticket['details']['error'] ?? '' );
			if ( 'DeviceNotRegistered' === $error && isset( $chunk[ $i ] ) ) {
				$on_invalid_token( (string) $chunk[ $i ] );
			}

			self::log( sprintf( 'Expo push ticket error (%s): %s', $error, (string) ( $ticket['message'] ?? '' ) ) );
		}
	}

	/**
	 * Debug-only diagnostics.
	 *
	 * @param string $message What went wrong.
	 */
	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[haramara-core] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug-only diagnostics.
		}
	}
}
