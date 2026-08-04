<?php
/**
 * Thin Twilio REST client.
 *
 * Sends SMS via the Twilio Messages API using WordPress' HTTP layer
 * (`wp_remote_post`) — no external SDK. Also provides the X-Twilio-Signature
 * validation helper used to authenticate inbound webhooks.
 *
 * Configuration (including secrets) is read exclusively through Options::sms(),
 * which resolves HARAMARA_TWILIO_* constants over stored values. Secrets are
 * never logged or echoed.
 *
 * @package Haramara\Core
 */

declare( strict_types=1 );

namespace Haramara\Core\Sms;

use Haramara\Core\Setup\Options;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TwilioClient {

	/** Messages resource endpoint (Account SID interpolated at call time). */
	private const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

	/**
	 * Send an SMS.
	 *
	 * @param string $to   Destination number (E.164 recommended).
	 * @param string $body Message body.
	 * @return array{success:bool,sid:string,error:string}
	 */
	public function send( string $to, string $body ): array {
		$sms = Options::sms();

		if ( empty( $sms['enabled'] ) ) {
			return self::failure( __( 'La mensajería SMS está desactivada.', 'haramara-core' ) );
		}

		$sid   = (string) ( $sms['twilio_sid'] ?? '' );
		$token = (string) ( $sms['twilio_token'] ?? '' );
		$from  = (string) ( $sms['twilio_from'] ?? '' );
		$mssid = (string) ( $sms['messaging_service_sid'] ?? '' );
		// API-key auth (SK… + secret) still needs the Account SID (AC…) in the
		// endpoint path; with classic AC+auth-token creds the two are the same.
		$account = (string) ( $sms['twilio_account_sid'] ?? '' );
		if ( '' === $account ) {
			$account = $sid;
		}

		if ( '' === $sid || '' === $token ) {
			return self::failure( __( 'Faltan las credenciales de Twilio.', 'haramara-core' ) );
		}
		if ( '' === $from && '' === $mssid ) {
			return self::failure( __( 'No hay número remitente ni Messaging Service configurado.', 'haramara-core' ) );
		}

		$to = trim( $to );
		if ( '' === $to || '' === trim( $body ) ) {
			return self::failure( __( 'Destinatario o mensaje vacío.', 'haramara-core' ) );
		}

		// Dry run: compose and report success without calling the provider, so
		// the full order → notify → reply workflow (and the SMS log) can be
		// exercised on local/staging or while provider onboarding is pending.
		// Callers log the message either way, so nothing else changes.
		if ( ! empty( $sms['dry_run'] ) ) {
			return array(
				'success' => true,
				'sid'     => 'DRYRUN-' . substr( md5( $to . $body . (string) time() ), 0, 20 ),
				'error'   => '',
			);
		}

		// Channel prefixing: WhatsApp uses the same Messages endpoint but both
		// addresses must carry a `whatsapp:` scheme. Numbers are stored plain
		// (E.164) so the same config serves either channel.
		$channel = 'whatsapp' === (string) ( $sms['channel'] ?? 'sms' ) ? 'whatsapp' : 'sms';
		$address = static function ( string $number ) use ( $channel ): string {
			$number = trim( $number );
			if ( 'whatsapp' !== $channel || '' === $number ) {
				return $number;
			}
			return 0 === strpos( $number, 'whatsapp:' ) ? $number : 'whatsapp:' . $number;
		};

		$payload = array(
			'To'   => $address( $to ),
			'Body' => $body,
		);
		// MessagingServiceSid takes precedence when both are present.
		if ( '' !== $mssid ) {
			$payload['MessagingServiceSid'] = $mssid;
		} else {
			$payload['From'] = $address( $from );
		}

		$response = wp_remote_post(
			sprintf( self::ENDPOINT, rawurlencode( $account ) ),
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $token ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth header.
					'Accept'        => 'application/json',
				),
				'body'    => $payload,
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::failure( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();

		if ( $code >= 200 && $code < 300 && ! empty( $data['sid'] ) ) {
			return array(
				'success' => true,
				'sid'     => (string) $data['sid'],
				'error'   => '',
			);
		}

		$message = isset( $data['message'] ) && '' !== (string) $data['message']
			? (string) $data['message']
			/* translators: %d: HTTP status code. */
			: sprintf( __( 'Error de Twilio (HTTP %d).', 'haramara-core' ), $code );

		return self::failure( $message );
	}

	/**
	 * Validate an X-Twilio-Signature header.
	 *
	 * Twilio's scheme: sort the POST parameters by key, concatenate the full
	 * request URL followed by each key/value pair, then HMAC-SHA1 that string
	 * with the account auth token and base64-encode the result.
	 *
	 * @param string              $auth_token Twilio auth token (HMAC key).
	 * @param string              $url        Exact URL Twilio requested.
	 * @param array<string,mixed> $params     POST parameters as received.
	 * @param string              $signature  Provided X-Twilio-Signature value.
	 */
	public static function validate_signature( string $auth_token, string $url, array $params, string $signature ): bool {
		if ( '' === $auth_token || '' === $signature ) {
			return false;
		}

		ksort( $params );

		$data = $url;
		foreach ( $params as $key => $value ) {
			$data .= $key . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
		}

		$computed = base64_encode( hash_hmac( 'sha1', $data, $auth_token, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic auth header.

		return hash_equals( $computed, $signature );
	}

	/**
	 * @return array{success:bool,sid:string,error:string}
	 */
	private static function failure( string $error ): array {
		return array(
			'success' => false,
			'sid'     => '',
			'error'   => $error,
		);
	}
}
