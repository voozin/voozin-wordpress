<?php
/**
 * Sends wp_mail() through the Voozin API.
 *
 * @package Voozin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Short-circuits wp_mail() with the pre_wp_mail filter (WordPress 5.7+).
 */
class Voozin_Mailer {

	/** The API accepts up to 50 recipients in "to" per email. */
	const MAX_TO = 50;

	/** Attachments above this total are left to PHP mail (the API limit is 20 MB decoded). */
	const MAX_ATTACHMENT_BYTES = 20971520;

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( '' !== Voozin_Settings::api_key() ) {
			add_filter( 'pre_wp_mail', array( __CLASS__, 'pre_wp_mail' ), 10, 2 );
		}
	}

	/**
	 * The pre_wp_mail filter: returns true or false when Voozin handled the email, or null to let WordPress send it.
	 *
	 * @param null|bool            $return Short-circuit value from earlier filters.
	 * @param array<string, mixed> $atts   to, subject, message, headers, attachments.
	 * @return null|bool
	 */
	public static function pre_wp_mail( $return, $atts ) {
		if ( null !== $return ) {
			return $return;
		}
		$email = self::build( $atts );
		if ( is_wp_error( $email ) ) {
			return self::fail( $atts, $email );
		}

		$recipients = (array) $email['to'];
		foreach ( array_chunk( $recipients, self::MAX_TO ) as $i => $chunk ) {
			$email['to'] = $chunk;
			$email       = apply_filters( 'voozin_email_payload', $email, $atts );
			$response    = Voozin_API::send( $email );
			if ( is_wp_error( $response ) ) {
				// Once some recipients got the email, PHP mail would send it to them again.
				return self::fail( $atts, $response, $chunk, 0 === $i );
			}
			Voozin_Log::add( $chunk, $email['subject'], 'sent', isset( $response['id'] ) ? (string) $response['id'] : '' );
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core wp_mail hook.
		do_action(
			'wp_mail_succeeded',
			array(
				'to'          => $recipients,
				'subject'     => $atts['subject'],
				'message'     => $atts['message'],
				'headers'     => $atts['headers'],
				'attachments' => $atts['attachments'],
			)
		);
		// phpcs:enable
		return true;
	}

	/**
	 * Logs a failure, then either lets WordPress send the email (fallback) or reports wp_mail_failed.
	 *
	 * @param array<string, mixed> $atts       wp_mail arguments.
	 * @param WP_Error             $error      What went wrong.
	 * @param string[]|null        $recipients Recipients of the failed chunk.
	 * @param bool                 $can_fall_back Whether nothing was sent yet.
	 * @return null|false
	 */
	private static function fail( $atts, WP_Error $error, $recipients = null, $can_fall_back = true ) {
		$to       = null === $recipients ? self::addresses( $atts['to'] ) : $recipients;
		$too_big  = 'voozin_attachments_too_large' === $error->get_error_code();
		$fallback = $can_fall_back && ( $too_big || Voozin_Settings::get( 'fallback' ) );
		Voozin_Log::add( $to, (string) $atts['subject'], 'failed', '', $error->get_error_message(), $fallback ? 'php-mail' : 'voozin' );
		if ( $fallback ) {
			return null;
		}
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $error->get_error_message(), $atts ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core wp_mail hook.
		return false;
	}

	/**
	 * Turns wp_mail arguments into a POST /v1/emails body.
	 *
	 * @param array<string, mixed> $atts wp_mail arguments.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function build( $atts ) {
		$headers      = self::parse_headers( isset( $atts['headers'] ) ? $atts['headers'] : array() );
		$content_type = apply_filters( 'wp_mail_content_type', $headers['content_type'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core wp_mail hook.

		$to = self::addresses( $atts['to'] );
		if ( empty( $to ) ) {
			return new WP_Error( 'voozin_no_recipients', __( 'The email has no recipients.', 'voozin' ) );
		}

		$message = (string) $atts['message'];
		$email   = array(
			'from'    => self::from( $headers['from_email'], $headers['from_name'] ),
			'to'      => $to,
			'subject' => (string) $atts['subject'],
		);
		if ( 'text/html' === $content_type ) {
			$email['html'] = $message;
			$email['text'] = self::html_to_text( $message );
		} else {
			$email['text'] = $message;
		}
		if ( $headers['cc'] ) {
			$email['cc'] = $headers['cc'];
		}
		if ( $headers['bcc'] ) {
			$email['bcc'] = $headers['bcc'];
		}
		if ( $headers['reply_to'] ) {
			$email['reply_to'] = $headers['reply_to'];
		}
		if ( $headers['custom'] ) {
			$email['headers'] = $headers['custom'];
		}
		foreach ( array( 'track_opens', 'track_clicks' ) as $tracking ) {
			$value = (string) Voozin_Settings::get( $tracking );
			if ( '' !== $value ) {
				$email[ $tracking ] = '1' === $value;
			}
		}
		$email['tags'] = array( 'source' => 'wordpress' );

		$attachments = self::attachments( isset( $atts['attachments'] ) ? $atts['attachments'] : array() );
		if ( is_wp_error( $attachments ) ) {
			return $attachments;
		}
		if ( $attachments ) {
			$email['attachments'] = $attachments;
		}
		return $email;
	}

	/**
	 * The From header: the plugin's address unless the email sets one on a verified domain (and From is not forced).
	 *
	 * @param string $from_email From address in the headers.
	 * @param string $from_name  From name in the headers.
	 * @return string
	 */
	private static function from( $from_email, $from_name ) {
		$default_email = (string) Voozin_Settings::get( 'from_email' );
		$default_name  = (string) Voozin_Settings::get( 'from_name' );
		if ( '' === $default_email ) {
			$default_email = 'wordpress@' . preg_replace( '/^www\./', '', (string) wp_parse_url( network_home_url(), PHP_URL_HOST ) );
		}
		if ( '' === $default_name ) {
			$default_name = get_bloginfo( 'name' );
		}

		$email = $from_email ? $from_email : $default_email;
		$name  = $from_name ? $from_name : $default_name;
		$email = (string) apply_filters( 'wp_mail_from', $email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core wp_mail hook.
		$name  = (string) apply_filters( 'wp_mail_from_name', $name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core wp_mail hook.

		if ( Voozin_Settings::get( 'force_from' ) || ( $email !== $default_email && ! Voozin_API::is_verified_sender( $email ) ) ) {
			$email = $default_email;
			$name  = $from_name ? $from_name : $default_name;
		}
		return self::format_address( $name, $email );
	}

	/**
	 * "Name <address>" with the name quoted when needed.
	 *
	 * @param string $name  Display name.
	 * @param string $email Address.
	 * @return string
	 */
	public static function format_address( $name, $email ) {
		$name = trim( str_replace( array( "\r", "\n", '"' ), array( '', '', "'" ), (string) $name ) );
		if ( '' === $name ) {
			return $email;
		}
		return preg_match( '/[(),:;<>@\[\]\\\\.]/', $name ) ? sprintf( '"%s" <%s>', $name, $email ) : sprintf( '%s <%s>', $name, $email );
	}

	/**
	 * Splits a list of addresses given as a string or array.
	 *
	 * @param string|string[] $list Addresses.
	 * @return string[]
	 */
	public static function addresses( $list ) {
		if ( ! is_array( $list ) ) {
			$list = explode( ',', (string) $list );
		}
		$out = array();
		foreach ( $list as $address ) {
			$address = trim( (string) $address );
			if ( '' !== $address ) {
				$out[] = $address;
			}
		}
		return $out;
	}

	/**
	 * Parses wp_mail headers the way wp_mail() does.
	 *
	 * @param string|string[] $headers Raw headers.
	 * @return array<string, mixed>
	 */
	public static function parse_headers( $headers ) {
		$result = array(
			'from_email'   => '',
			'from_name'    => '',
			'cc'           => array(),
			'bcc'          => array(),
			'reply_to'     => array(),
			'content_type' => 'text/plain',
			'charset'      => '',
			'custom'       => array(),
		);
		if ( ! is_array( $headers ) ) {
			$headers = explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );
		}
		foreach ( $headers as $name => $header ) {
			if ( ! is_int( $name ) ) {
				$header = $name . ': ' . $header;
			}
			if ( false === strpos( (string) $header, ':' ) ) {
				continue;
			}
			list( $name, $content ) = explode( ':', (string) $header, 2 );
			$name    = trim( $name );
			$content = trim( $content );
			switch ( strtolower( $name ) ) {
				case 'from':
					if ( preg_match( '/^(.*)<(.+)>\s*$/', $content, $m ) ) {
						$result['from_name']  = trim( trim( $m[1] ), '"' );
						$result['from_email'] = trim( $m[2] );
					} else {
						$result['from_email'] = $content;
					}
					break;
				case 'content-type':
					$parts                  = explode( ';', $content );
					$result['content_type'] = strtolower( trim( $parts[0] ) );
					foreach ( array_slice( $parts, 1 ) as $param ) {
						if ( preg_match( '/charset\s*=\s*"?([^";]+)"?/i', $param, $m ) ) {
							$result['charset'] = trim( $m[1] );
						}
					}
					break;
				case 'cc':
					$result['cc'] = array_merge( $result['cc'], self::addresses( $content ) );
					break;
				case 'bcc':
					$result['bcc'] = array_merge( $result['bcc'], self::addresses( $content ) );
					break;
				case 'reply-to':
					$result['reply_to'] = array_merge( $result['reply_to'], self::addresses( $content ) );
					break;
				case 'mime-version':
				case 'content-transfer-encoding':
				case 'to':
				case 'subject':
				case 'date':
				case 'message-id':
				case 'x-mailer':
					break;
				default:
					if ( preg_match( '/^[A-Za-z0-9-]+$/', $name ) ) {
						$result['custom'][ $name ] = $content;
					}
			}
		}
		return $result;
	}

	/**
	 * Reads attachments into base64. Array keys that are strings are used as file names (WordPress 6.2+).
	 *
	 * @param string|string[] $attachments File paths.
	 * @return array<int, array<string, string>>|WP_Error
	 */
	private static function attachments( $attachments ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}
		$out   = array();
		$total = 0;
		foreach ( $attachments as $name => $path ) {
			$path = trim( (string) $path );
			if ( '' === $path ) {
				continue;
			}
			if ( ! is_readable( $path ) ) {
				/* translators: %s: file path. */
				return new WP_Error( 'voozin_attachment_missing', sprintf( __( 'Attachment not readable: %s', 'voozin' ), $path ) );
			}
			$total += (int) filesize( $path );
			if ( $total > self::MAX_ATTACHMENT_BYTES ) {
				return new WP_Error( 'voozin_attachments_too_large', __( 'Attachments exceed 20 MB.', 'voozin' ) );
			}
			$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$type    = wp_check_filetype( $path );
			$item    = array(
				'filename' => is_string( $name ) ? $name : wp_basename( $path ),
				'content'  => base64_encode( (string) $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			);
			if ( ! empty( $type['type'] ) ) {
				$item['content_type'] = $type['type'];
			}
			$out[] = $item;
		}
		return $out;
	}

	/**
	 * A plain-text version of an HTML body.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_to_text( $html ) {
		$html = preg_replace( '#<(script|style|head)[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '$2 ($1)', $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</(p|div|h[1-6]|li|tr|table|blockquote)>#i', "\n\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n\s*\n\s*\n+/", "\n\n", $text );
		return trim( $text );
	}
}
