<?php
/**
 * Receives Voozin webhooks at /wp-json/voozin/v1/webhook.
 *
 * @package Voozin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verifies Standard Webhooks signatures and dispatches events.
 */
class Voozin_Webhook {

	/** Signatures older or newer than this are rejected. */
	const TOLERANCE = 300;

	const LOG_STATUSES = array(
		'email.delivered'        => 'delivered',
		'email.bounced'          => 'bounced',
		'email.complained'       => 'complained',
		'email.delivery_delayed' => 'delayed',
	);

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/**
	 * The public webhook URL.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( 'voozin/v1/webhook' );
	}

	/**
	 * Registers the route. It is public; every request is authenticated by its signature.
	 */
	public static function register_route() {
		register_rest_route(
			'voozin/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Checks a Standard Webhooks signature.
	 *
	 * @param string $secret    whsec_… secret.
	 * @param string $id        webhook-id header.
	 * @param string $timestamp webhook-timestamp header.
	 * @param string $signature webhook-signature header.
	 * @param string $body      Raw body.
	 * @param int    $now       Current Unix time.
	 * @return bool
	 */
	public static function verify( $secret, $id, $timestamp, $signature, $body, $now = null ) {
		if ( '' === $secret || '' === $id || '' === $timestamp || '' === $signature ) {
			return false;
		}
		$now = null === $now ? time() : $now;
		if ( ! ctype_digit( $timestamp ) || abs( $now - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}
		$key      = base64_decode( preg_replace( '/^whsec_/', '', $secret ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$expected = base64_encode( hash_hmac( 'sha256', $id . '.' . $timestamp . '.' . $body, (string) $key, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		foreach ( explode( ' ', $signature ) as $entry ) {
			$parts = explode( ',', $entry, 2 );
			if ( 2 === count( $parts ) && 'v1' === $parts[0] && hash_equals( $expected, $parts[1] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Handles a delivery.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ) {
		$body = $request->get_body();
		$id   = (string) $request->get_header( 'webhook-id' );
		$ok   = self::verify(
			Voozin_Settings::webhook_secret(),
			$id,
			(string) $request->get_header( 'webhook-timestamp' ),
			(string) $request->get_header( 'webhook-signature' ),
			$body
		);
		if ( ! $ok ) {
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 400 );
		}

		// Retries and replays keep the same webhook-id.
		$seen = 'voozin_wh_' . md5( $id );
		if ( get_transient( $seen ) ) {
			return new WP_REST_Response( null, 204 );
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) || ! isset( $event['data'] ) || ! is_array( $event['data'] ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid payload' ), 400 );
		}

		$type = (string) $event['type'];
		$data = $event['data'];
		if ( 'inbound.received' === $type ) {
			Voozin_Inbox::store_inbound( $data );
		} elseif ( isset( self::LOG_STATUSES[ $type ] ) && ! empty( $data['email_id'] ) ) {
			$detail = '';
			foreach ( array( 'reason', 'bounce_reason', 'diagnostic', 'message' ) as $field ) {
				if ( ! empty( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
					$detail = $data[ $field ];
					break;
				}
			}
			Voozin_Log::update_status( (string) $data['email_id'], self::LOG_STATUSES[ $type ], $detail );
		}

		/**
		 * Fires for every verified Voozin webhook event.
		 *
		 * @param array $event The event: type, timestamp and data.
		 */
		do_action( 'voozin_event_received', $event );

		set_transient( $seen, 1, DAY_IN_SECONDS );
		return new WP_REST_Response( null, 204 );
	}
}
