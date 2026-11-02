<?php
/**
 * Plugin options.
 *
 * @package Voozin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's options. The webhook secret lives in its own option so it is never autoloaded.
 */
class Voozin_Settings {

	const OPTION        = 'voozin_settings';
	const SECRET_OPTION = 'voozin_webhook_secret';

	/**
	 * Defaults for every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'api_key'      => '',
			'from_email'   => '',
			'from_name'    => '',
			'force_from'   => false,
			'fallback'     => true,
			'track_opens'  => '',
			'track_clicks' => '',
			'log_days'     => 30,
			'endpoint_id'  => '',
			'routes'       => array(),
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Updates some settings, keeping the others.
	 *
	 * @param array<string, mixed> $values New values.
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ), true );
	}

	/**
	 * The API key: the VOOZIN_API_KEY constant wins over the saved option.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'VOOZIN_API_KEY' ) && VOOZIN_API_KEY ) {
			return (string) VOOZIN_API_KEY;
		}
		return (string) self::get( 'api_key' );
	}

	/**
	 * Whether the key comes from wp-config.php.
	 *
	 * @return bool
	 */
	public static function key_from_constant() {
		return defined( 'VOOZIN_API_KEY' ) && VOOZIN_API_KEY;
	}

	/**
	 * The webhook signing secret (whsec_…).
	 *
	 * @return string
	 */
	public static function webhook_secret() {
		return (string) get_option( self::SECRET_OPTION, '' );
	}

	/**
	 * Saves the webhook signing secret without autoloading it.
	 *
	 * @param string $secret Secret.
	 */
	public static function set_webhook_secret( $secret ) {
		update_option( self::SECRET_OPTION, $secret, false );
	}
}
