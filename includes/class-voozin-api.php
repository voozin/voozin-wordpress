<?php
/**
 * Voozin REST API client.
 *
 * @package Voozin
 */

defined( 'ABSPATH' ) || exit;

/**
 * A small client for https://api.voozin.com/v1 on top of the WordPress HTTP API.
 */
class Voozin_API {

	/**
	 * The API base URL. Override with the VOOZIN_API_BASE constant or the voozin_api_base filter.
	 *
	 * @return string
	 */
	public static function base_url() {
		$base = defined( 'VOOZIN_API_BASE' ) ? VOOZIN_API_BASE : 'https://api.voozin.com';
		return untrailingslashit( (string) apply_filters( 'voozin_api_base', $base ) );
	}

	/**
	 * Sends a request and decodes the JSON response.
	 *
	 * @param string               $method  HTTP method.
	 * @param string               $path    Path starting with /v1.
	 * @param array<string, mixed> $body    JSON body, or null.
	 * @param array<string, mixed> $options timeout, idempotency_key, raw (return the response array).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function request( $method, $path, $body = null, array $options = array() ) {
		$key = Voozin_Settings::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'voozin_no_key', __( 'No Voozin API key is configured.', 'voozin' ) );
		}
		$headers = array(
			'Authorization' => 'Bearer ' . $key,
			'Accept'        => empty( $options['raw'] ) ? 'application/json' : '*/*',
			'User-Agent'    => 'voozin-wordpress/' . VOOZIN_VERSION . '; ' . home_url(),
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( ! empty( $options['idempotency_key'] ) ) {
			$headers['Idempotency-Key'] = $options['idempotency_key'];
		}

		$response = wp_remote_request(
			self::base_url() . $path,
			array(
				'method'  => $method,
				'headers' => $headers,
				'body'    => null === $body ? null : wp_json_encode( $body ),
				'timeout' => isset( $options['timeout'] ) ? (int) $options['timeout'] : 15,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status >= 200 && $status < 300 ) {
			if ( ! empty( $options['raw'] ) ) {
				return $response;
			}
			$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			return is_array( $decoded ) ? $decoded : array();
		}

		$error   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$message = is_array( $error ) && isset( $error['message'] ) ? $error['message'] : '';
		if ( is_array( $message ) ) {
			$message = implode( '; ', $message );
		}
		if ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'Voozin API returned HTTP %d.', 'voozin' ), $status );
		}
		return new WP_Error( 'voozin_http_' . $status, $message, array( 'status' => $status ) );
	}

	/**
	 * Sends one email.
	 *
	 * @param array<string, mixed> $email Request body for POST /v1/emails.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function send( array $email ) {
		return self::request( 'POST', '/v1/emails', $email, array( 'timeout' => 20 ) );
	}

	/**
	 * Active domains, cached for an hour.
	 *
	 * @param bool $refresh Skip the cache.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public static function domains( $refresh = false ) {
		$cached = get_transient( 'voozin_domains' );
		if ( ! $refresh && is_array( $cached ) ) {
			return $cached;
		}
		$response = self::request( 'GET', '/v1/domains' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$domains = array_values(
			array_filter(
				isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array(),
				static function ( $domain ) {
					return isset( $domain['status'] ) && 'ACTIVE' === $domain['status'];
				}
			)
		);
		set_transient( 'voozin_domains', $domains, HOUR_IN_SECONDS );
		return $domains;
	}

	/**
	 * Whether an address is on one of the organization's active domains.
	 *
	 * @param string $address Email address.
	 * @return bool
	 */
	public static function is_verified_sender( $address ) {
		$at = strrpos( $address, '@' );
		if ( false === $at ) {
			return false;
		}
		$domain  = strtolower( substr( $address, $at + 1 ) );
		$domains = self::domains();
		if ( is_wp_error( $domains ) ) {
			return false;
		}
		foreach ( $domains as $entry ) {
			if ( isset( $entry['name'] ) && strtolower( $entry['name'] ) === $domain ) {
				return true;
			}
		}
		return false;
	}
}
