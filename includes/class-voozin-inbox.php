<?php
/**
 * Inbound messages stored as a private post type, grouped into conversations.
 *
 * @package Voozin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Each message is a voozin_message post. The first message of a conversation has post_parent 0;
 * replies (received and sent) point to it.
 */
class Voozin_Inbox {

	const POST_TYPE = 'voozin_message';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * A private post type with no front end and no default admin screens.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Inbox', 'voozin' ),
					'singular_name' => __( 'Message', 'voozin' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ),
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Wraps a Message-ID in angle brackets.
	 *
	 * @param string $id Message-ID.
	 * @return string
	 */
	public static function bracket( $id ) {
		$id = trim( (string) $id );
		return '<' === substr( $id, 0, 1 ) ? $id : '<' . $id . '>';
	}

	/**
	 * Strips angle brackets from a Message-ID.
	 *
	 * @param string $id Message-ID.
	 * @return string
	 */
	private static function bare( $id ) {
		return trim( (string) $id, " \t<>" );
	}

	/**
	 * The conversation an incoming message belongs to, found through In-Reply-To and References.
	 *
	 * @param array<string, mixed> $data inbound.received data.
	 * @return int Root post id, or 0 for a new conversation.
	 */
	private static function find_thread( array $data ) {
		$ids = array();
		if ( ! empty( $data['in_reply_to'] ) ) {
			$ids[] = self::bare( $data['in_reply_to'] );
		}
		if ( ! empty( $data['references'] ) && is_array( $data['references'] ) ) {
			foreach ( array_reverse( $data['references'] ) as $ref ) {
				$ids[] = self::bare( $ref );
			}
		}
		foreach ( array_unique( array_filter( $ids ) ) as $message_id ) {
			$posts = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'private',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_key'       => '_voozin_message_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'     => $message_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( $posts ) {
				$parent = (int) wp_get_post_parent_id( $posts[0] );
				return $parent ? $parent : (int) $posts[0];
			}
		}
		return 0;
	}

	/**
	 * Stores an inbound.received event.
	 *
	 * @param array<string, mixed> $data Event data.
	 * @return int Post id.
	 */
	public static function store_inbound( array $data ) {
		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_voozin_inbound_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $data['id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		if ( $existing ) {
			return (int) $existing[0];
		}

		$thread  = self::find_thread( $data );
		$subject = isset( $data['subject'] ) && '' !== (string) $data['subject'] ? (string) $data['subject'] : __( '(no subject)', 'voozin' );
		$from    = isset( $data['from'] ) && is_array( $data['from'] ) ? $data['from'] : array(
			'name'    => null,
			'address' => isset( $data['mail_from'] ) ? $data['mail_from'] : '',
		);
		$date    = ! empty( $data['received_at'] ) ? strtotime( (string) $data['received_at'] ) : time();

		$post_id = wp_insert_post(
			array(
				'post_type'     => self::POST_TYPE,
				'post_status'   => 'private',
				'post_title'    => wp_slash( wp_strip_all_tags( $subject ) ),
				'post_parent'   => $thread,
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $date ),
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $date ) ),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		$meta = array(
			'_voozin_direction'   => 'in',
			'_voozin_inbound_id'  => (string) $data['id'],
			'_voozin_message_id'  => isset( $data['message_id'] ) ? self::bare( $data['message_id'] ) : '',
			'_voozin_references'  => isset( $data['references'] ) && is_array( $data['references'] ) ? array_map( array( __CLASS__, 'bare' ), $data['references'] ) : array(),
			'_voozin_from'        => $from,
			'_voozin_reply_to'    => isset( $data['reply_to'] ) && is_array( $data['reply_to'] ) ? $data['reply_to'] : array(),
			'_voozin_recipients'  => isset( $data['recipients'] ) && is_array( $data['recipients'] ) ? $data['recipients'] : array(),
			'_voozin_text'        => isset( $data['text'] ) ? (string) $data['text'] : '',
			'_voozin_html'        => isset( $data['html'] ) ? (string) $data['html'] : '',
			'_voozin_attachments' => isset( $data['attachments'] ) && is_array( $data['attachments'] ) ? $data['attachments'] : array(),
			'_voozin_auth'        => isset( $data['auth'] ) && is_array( $data['auth'] ) ? $data['auth'] : array(),
			'_voozin_read'        => 0,
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, wp_slash( $value ) );
		}
		self::touch( $thread ? $thread : $post_id, $date, true );

		/**
		 * Fires when an inbound email has been stored.
		 *
		 * @param int   $post_id The voozin_message post.
		 * @param array $data    The inbound.received data.
		 */
		do_action( 'voozin_inbound_received', $post_id, $data );
		return $post_id;
	}

	/**
	 * Updates a conversation's last activity and unread flag.
	 *
	 * @param int  $root   Root post id.
	 * @param int  $time   Unix time.
	 * @param bool $unread Mark unread.
	 */
	private static function touch( $root, $time, $unread ) {
		update_post_meta( $root, '_voozin_last_at', $time );
		update_post_meta( $root, '_voozin_unread', $unread ? 1 : 0 );
	}

	/**
	 * Conversations, newest activity first.
	 *
	 * @param int $page     1-based page.
	 * @param int $per_page Per page.
	 * @return WP_Query
	 */
	public static function threads( $page, $per_page ) {
		return new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'post_parent'    => 0,
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'meta_key'       => '_voozin_last_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
			)
		);
	}

	/**
	 * All messages of a conversation, oldest first.
	 *
	 * @param int $root Root post id.
	 * @return WP_Post[]
	 */
	public static function messages( $root ) {
		$replies = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'post_parent'    => $root,
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);
		$first   = get_post( $root );
		return $first ? array_merge( array( $first ), $replies ) : $replies;
	}

	/**
	 * Marks a conversation read.
	 *
	 * @param int $root Root post id.
	 */
	public static function mark_read( $root ) {
		update_post_meta( $root, '_voozin_unread', 0 );
	}

	/**
	 * Number of conversations with unread messages.
	 *
	 * @return int
	 */
	public static function unread_count() {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'private',
				'post_parent'    => 0,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_voozin_unread', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 1, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Replies to the latest received message of a conversation and stores the reply.
	 *
	 * @param int    $root Root post id.
	 * @param string $text Reply text.
	 * @return true|WP_Error
	 */
	public static function reply( $root, $text ) {
		$messages = self::messages( $root );
		$incoming = array_values(
			array_filter(
				$messages,
				static function ( $post ) {
					return 'in' === get_post_meta( $post->ID, '_voozin_direction', true );
				}
			)
		);
		$last     = end( $incoming );
		if ( ! $last ) {
			return new WP_Error( 'voozin_no_message', __( 'There is no received message to reply to.', 'voozin' ) );
		}

		$reply_to = (array) get_post_meta( $last->ID, '_voozin_reply_to', true );
		$from     = (array) get_post_meta( $last->ID, '_voozin_from', true );
		$to       = array();
		foreach ( $reply_to as $address ) {
			if ( ! empty( $address['address'] ) ) {
				$to[] = $address['address'];
			}
		}
		if ( ! $to && ! empty( $from['address'] ) ) {
			$to[] = $from['address'];
		}
		if ( ! $to ) {
			return new WP_Error( 'voozin_no_recipient', __( 'The message has no address to reply to.', 'voozin' ) );
		}

		$recipients = array_values( array_filter( (array) get_post_meta( $last->ID, '_voozin_recipients', true ), 'is_email' ) );
		$sender     = $recipients ? (string) $recipients[0] : (string) Voozin_Settings::get( 'from_email' );
		$name       = (string) Voozin_Settings::get( 'from_name' );
		$subject    = (string) get_post_field( 'post_title', $root );
		if ( ! preg_match( '/^re:/i', $subject ) ) {
			$subject = 'Re: ' . $subject;
		}

		$headers    = array();
		$message_id = (string) get_post_meta( $last->ID, '_voozin_message_id', true );
		if ( '' !== $message_id ) {
			$references             = (array) get_post_meta( $last->ID, '_voozin_references', true );
			$references[]           = $message_id;
			$headers['In-Reply-To'] = self::bracket( $message_id );
			$headers['References']  = implode( ' ', array_map( array( __CLASS__, 'bracket' ), array_filter( $references ) ) );
		}

		$quoted = self::quote( $last );
		$body   = $text . ( '' !== $quoted ? "\n\n" . $quoted : '' );
		$email  = array(
			'from'    => Voozin_Mailer::format_address( '' !== $name ? $name : get_bloginfo( 'name' ), $sender ),
			'to'      => $to,
			'subject' => $subject,
			'text'    => $body,
			'html'    => wpautop( esc_html( $text ) ) . ( '' !== $quoted ? '<blockquote style="margin:0 0 0 .8ex;border-left:1px solid #ccc;padding-left:1ex">' . nl2br( esc_html( $quoted ) ) . '</blockquote>' : '' ),
			'tags'    => array( 'source' => 'wordpress-inbox' ),
		);
		if ( $headers ) {
			$email['headers'] = $headers;
		}

		// A resubmitted form reuses the key, so the API sends once; don't store the reply twice either.
		$key  = 'wp-reply-' . $root . '-' . md5( $text );
		$sent = 'voozin_' . md5( $key );
		if ( get_transient( $sent ) ) {
			return true;
		}
		$response = Voozin_API::request( 'POST', '/v1/emails', $email, array( 'idempotency_key' => $key ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		set_transient( $sent, 1, 10 * MINUTE_IN_SECONDS );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'private',
				'post_title'  => wp_slash( $subject ),
				'post_parent' => $root,
			),
			true
		);
		if ( ! is_wp_error( $post_id ) ) {
			update_post_meta( $post_id, '_voozin_direction', 'out' );
			update_post_meta( $post_id, '_voozin_email_id', isset( $response['id'] ) ? (string) $response['id'] : '' );
			update_post_meta( $post_id, '_voozin_from', array( 'name' => $name, 'address' => $sender ) );
			update_post_meta( $post_id, '_voozin_to', $to );
			update_post_meta( $post_id, '_voozin_text', wp_slash( $text ) );
			update_post_meta( $post_id, '_voozin_author', get_current_user_id() );
		}
		Voozin_Log::add( $to, $subject, 'sent', isset( $response['id'] ) ? (string) $response['id'] : '' );
		self::touch( $root, time(), false );
		return true;
	}

	/**
	 * The text of a message quoted for a reply.
	 *
	 * @param WP_Post $post Message.
	 * @return string
	 */
	private static function quote( $post ) {
		$text = (string) get_post_meta( $post->ID, '_voozin_text', true );
		if ( '' === trim( $text ) ) {
			$text = Voozin_Mailer::html_to_text( (string) get_post_meta( $post->ID, '_voozin_html', true ) );
		}
		if ( '' === trim( $text ) ) {
			return '';
		}
		$from = (array) get_post_meta( $post->ID, '_voozin_from', true );
		$who  = ! empty( $from['name'] ) ? $from['name'] : ( isset( $from['address'] ) ? $from['address'] : '' );
		/* translators: 1: date, 2: sender. */
		$intro = sprintf( __( 'On %1$s, %2$s wrote:', 'voozin' ), get_the_date( 'j M Y H:i', $post ), $who );
		$lines = explode( "\n", str_replace( "\r\n", "\n", trim( $text ) ) );
		return $intro . "\n" . implode(
			"\n",
			array_map(
				static function ( $line ) {
					return '> ' . $line;
				},
				$lines
			)
		);
	}

	/**
	 * Downloads an attachment through the API (signed links in the payload expire after 24 hours).
	 *
	 * @param int $post_id Message post.
	 * @param int $index   Attachment index.
	 * @return array{body: string, type: string, filename: string}|WP_Error
	 */
	public static function attachment( $post_id, $index ) {
		$inbound_id = (string) get_post_meta( $post_id, '_voozin_inbound_id', true );
		if ( '' === $inbound_id ) {
			return new WP_Error( 'voozin_not_found', __( 'Attachment not found.', 'voozin' ) );
		}
		$response = Voozin_API::request( 'GET', '/v1/inbound/' . rawurlencode( $inbound_id ) . '/attachments/' . (int) $index, null, array( 'raw' => true ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$filename    = 'attachment-' . (int) $index;
		$attachments = (array) get_post_meta( $post_id, '_voozin_attachments', true );
		foreach ( $attachments as $item ) {
			if ( isset( $item['index'] ) && (int) $item['index'] === (int) $index && ! empty( $item['filename'] ) ) {
				$filename = (string) $item['filename'];
			}
		}
		return array(
			'body'     => (string) wp_remote_retrieve_body( $response ),
			'type'     => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			'filename' => $filename,
		);
	}
}
