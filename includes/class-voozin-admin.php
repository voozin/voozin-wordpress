<?php
/**
 * wp-admin screens: settings, email log and inbox.
 *
 * @package Voozin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin menu, pages and form handlers. Every action requires manage_options and a nonce.
 */
class Voozin_Admin {

	const CAP = 'manage_options';

	/** Events the plugin's webhook endpoint subscribes to. */
	const EVENTS = array( 'inbound.received', 'email.delivered', 'email.bounced', 'email.complained', 'email.delivery_delayed' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VOOZIN_FILE ), array( __CLASS__, 'action_links' ) );
		foreach ( array( 'save_settings', 'test_email', 'refresh_domains', 'connect', 'add_route', 'remove_route', 'disconnect', 'reply', 'attachment' ) as $action ) {
			add_action( 'admin_post_voozin_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_action( 'admin_notices', array( __CLASS__, 'setup_notice' ) );
	}

	/**
	 * Menu entries.
	 */
	public static function menu() {
		// Opening a conversation marks it read before the badge is counted.
		$page   = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$thread = isset( $_GET['thread'] ) ? absint( $_GET['thread'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'voozin-inbox' === $page && $thread && current_user_can( self::CAP ) && Voozin_Inbox::POST_TYPE === get_post_type( $thread ) ) {
			Voozin_Inbox::mark_read( $thread );
		}
		$unread = Voozin_Inbox::unread_count();
		$badge  = $unread ? ' <span class="awaiting-mod">' . (int) $unread . '</span>' : '';
		add_menu_page( 'Voozin', 'Voozin' . $badge, self::CAP, 'voozin', array( __CLASS__, 'page_settings' ), 'dashicons-email-alt', 81 );
		add_submenu_page( 'voozin', __( 'Voozin settings', 'voozin' ), __( 'Settings', 'voozin' ), self::CAP, 'voozin', array( __CLASS__, 'page_settings' ) );
		add_submenu_page( 'voozin', __( 'Email log', 'voozin' ), __( 'Email log', 'voozin' ), self::CAP, 'voozin-log', array( __CLASS__, 'page_log' ) );
		add_submenu_page( 'voozin', __( 'Inbox', 'voozin' ), __( 'Inbox', 'voozin' ) . $badge, self::CAP, 'voozin-inbox', array( __CLASS__, 'page_inbox' ) );
	}

	/**
	 * Styles for the plugin's pages.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function assets( $hook ) {
		if ( false !== strpos( $hook, 'voozin' ) ) {
			wp_enqueue_style( 'voozin-admin', VOOZIN_URL . 'assets/admin.css', array(), VOOZIN_VERSION );
		}
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=voozin' ) ) . '">' . esc_html__( 'Settings', 'voozin' ) . '</a>' );
		return $links;
	}

	/**
	 * Reminds administrators to add an API key.
	 */
	public static function setup_notice() {
		if ( ! current_user_can( self::CAP ) || '' !== Voozin_Settings::api_key() ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && false !== strpos( (string) $screen->id, 'voozin' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Voozin is installed but WordPress still sends email with PHP mail.', 'voozin' ),
			esc_url( admin_url( 'admin.php?page=voozin' ) ),
			esc_html__( 'Add your API key', 'voozin' )
		);
	}

	/**
	 * Checks capability and nonce for a form action.
	 *
	 * @param string $action Action name.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voozin' ), 403 );
		}
		check_admin_referer( 'voozin_' . $action );
	}

	/**
	 * Redirects back with a notice.
	 *
	 * @param string               $page    Admin page slug.
	 * @param string               $message Notice text.
	 * @param string               $type    success or error.
	 * @param array<string, mixed> $args    Extra query args.
	 */
	private static function back( $page, $message, $type = 'success', array $args = array() ) {
		set_transient(
			'voozin_notice_' . get_current_user_id(),
			array(
				'message' => $message,
				'type'    => $type,
			),
			60
		);
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Shows and clears the pending notice.
	 */
	private static function notice() {
		$key    = 'voozin_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ), esc_html( $notice['message'] ) );
	}

	/**
	 * A form posting to admin-post.php.
	 *
	 * @param string $action Action name.
	 * @param string $inner  Already-escaped inner HTML.
	 * @param string $class  CSS class.
	 */
	private static function form( $action, $inner, $class = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'voozin_' . $action ) . '">';
		wp_nonce_field( 'voozin_' . $action );
		echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</form>';
	}

	/**
	 * Settings page.
	 */
	public static function page_settings() {
		$settings = Voozin_Settings::all();
		$has_key  = '' !== Voozin_Settings::api_key();
		$domains  = $has_key ? Voozin_API::domains() : array();
		$from     = (string) $settings['from_email'];
		$at       = strrpos( $from, '@' );
		$local    = false === $at ? 'wordpress' : substr( $from, 0, $at );
		$domain   = false === $at ? '' : substr( $from, $at + 1 );
		?>
		<div class="wrap voozin">
			<h1><?php esc_html_e( 'Voozin', 'voozin' ); ?></h1>
			<?php self::notice(); ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: link to the documentation. */
					esc_html__( 'Send WordPress email through Voozin and receive email in WordPress. %s', 'voozin' ),
					'<a href="https://voozin.com/docs/wordpress" target="_blank" rel="noopener">' . esc_html__( 'Documentation', 'voozin' ) . '</a>'
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="voozin_save_settings">
				<?php wp_nonce_field( 'voozin_save_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="voozin-api-key"><?php esc_html_e( 'API key', 'voozin' ); ?></label></th>
						<td>
							<?php if ( Voozin_Settings::key_from_constant() ) : ?>
								<p><?php esc_html_e( 'Set by the VOOZIN_API_KEY constant in wp-config.php.', 'voozin' ); ?></p>
							<?php else : ?>
								<input type="password" id="voozin-api-key" name="api_key" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $settings['api_key'] ? __( 'Saved. Enter a new key to replace it.', 'voozin' ) : 'ic_live_…' ); ?>">
								<p class="description">
									<?php esc_html_e( 'Create a key in Voozin under Developers → API keys with the scopes emails:send and domains:read (add webhooks and inbound for the inbox). Or define VOOZIN_API_KEY in wp-config.php.', 'voozin' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="voozin-from-local"><?php esc_html_e( 'From address', 'voozin' ); ?></label></th>
						<td>
							<?php if ( is_wp_error( $domains ) ) : ?>
								<p class="voozin-error"><?php echo esc_html( $domains->get_error_message() ); ?></p>
								<input type="email" name="from_email" class="regular-text" value="<?php echo esc_attr( $from ); ?>">
							<?php elseif ( $domains ) : ?>
								<input type="text" id="voozin-from-local" name="from_local" value="<?php echo esc_attr( $local ); ?>" class="voozin-local"> @
								<select name="from_domain">
									<?php foreach ( $domains as $entry ) : ?>
										<option value="<?php echo esc_attr( $entry['name'] ); ?>" <?php selected( $domain, $entry['name'] ); ?>><?php echo esc_html( $entry['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input type="email" name="from_email" class="regular-text" value="<?php echo esc_attr( $from ); ?>">
								<?php if ( $has_key ) : ?>
									<p class="description"><?php esc_html_e( 'No verified domains yet. Verify a domain in Voozin first.', 'voozin' ); ?></p>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ( $has_key && ! is_wp_error( $domains ) ) : ?>
								<button type="submit" form="voozin-refresh" class="button-link"><?php esc_html_e( 'Refresh domains', 'voozin' ); ?></button>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="voozin-from-name"><?php esc_html_e( 'From name', 'voozin' ); ?></label></th>
						<td><input type="text" id="voozin-from-name" name="from_name" class="regular-text" value="<?php echo esc_attr( $settings['from_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sending', 'voozin' ); ?></th>
						<td>
							<label><input type="checkbox" name="force_from" value="1" <?php checked( $settings['force_from'] ); ?>> <?php esc_html_e( 'Always use this From address, even when a plugin sets another one', 'voozin' ); ?></label><br>
							<label><input type="checkbox" name="fallback" value="1" <?php checked( $settings['fallback'] ); ?>> <?php esc_html_e( 'If Voozin cannot be reached or rejects an email, send it with PHP mail instead', 'voozin' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tracking', 'voozin' ); ?></th>
						<td>
							<?php
							foreach ( array(
								'track_opens'  => __( 'Opens', 'voozin' ),
								'track_clicks' => __( 'Clicks', 'voozin' ),
							) as $field => $label ) :
								?>
								<label><?php echo esc_html( $label ); ?>
									<select name="<?php echo esc_attr( $field ); ?>">
										<option value="" <?php selected( $settings[ $field ], '' ); ?>><?php esc_html_e( 'Domain default', 'voozin' ); ?></option>
										<option value="1" <?php selected( $settings[ $field ], '1' ); ?>><?php esc_html_e( 'On', 'voozin' ); ?></option>
										<option value="0" <?php selected( $settings[ $field ], '0' ); ?>><?php esc_html_e( 'Off', 'voozin' ); ?></option>
									</select>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="voozin-log-days"><?php esc_html_e( 'Keep email log for', 'voozin' ); ?></label></th>
						<td><input type="number" id="voozin-log-days" name="log_days" min="1" max="365" value="<?php echo esc_attr( (string) $settings['log_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days', 'voozin' ); ?></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<form id="voozin-refresh" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="voozin_refresh_domains">
				<?php wp_nonce_field( 'voozin_refresh_domains' ); ?>
			</form>

			<?php if ( $has_key ) : ?>
				<h2><?php esc_html_e( 'Send a test email', 'voozin' ); ?></h2>
				<?php
				$user = wp_get_current_user();
				self::form(
					'test_email',
					'<p><input type="email" name="to" class="regular-text" required value="' . esc_attr( $user->user_email ) . '"> ' .
					'<button class="button">' . esc_html__( 'Send test email', 'voozin' ) . '</button></p>'
				);
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Saves settings.
	 */
	public static function handle_save_settings() {
		self::guard( 'save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$values = array(
			'from_name'    => sanitize_text_field( wp_unslash( isset( $_POST['from_name'] ) ? $_POST['from_name'] : '' ) ),
			'force_from'   => ! empty( $_POST['force_from'] ),
			'fallback'     => ! empty( $_POST['fallback'] ),
			'track_opens'  => in_array( isset( $_POST['track_opens'] ) ? $_POST['track_opens'] : '', array( '', '0', '1' ), true ) ? sanitize_text_field( wp_unslash( $_POST['track_opens'] ) ) : '',
			'track_clicks' => in_array( isset( $_POST['track_clicks'] ) ? $_POST['track_clicks'] : '', array( '', '0', '1' ), true ) ? sanitize_text_field( wp_unslash( $_POST['track_clicks'] ) ) : '',
			'log_days'     => min( 365, max( 1, isset( $_POST['log_days'] ) ? absint( $_POST['log_days'] ) : 30 ) ),
		);
		if ( isset( $_POST['from_local'], $_POST['from_domain'] ) ) {
			$local  = sanitize_text_field( wp_unslash( $_POST['from_local'] ) );
			$domain = sanitize_text_field( wp_unslash( $_POST['from_domain'] ) );
			$email  = sanitize_email( $local . '@' . $domain );
			if ( '' === $email ) {
				self::back( 'voozin', __( 'The From address is not valid.', 'voozin' ), 'error' );
			}
			$values['from_email'] = $email;
		} elseif ( isset( $_POST['from_email'] ) ) {
			$values['from_email'] = sanitize_email( wp_unslash( $_POST['from_email'] ) );
		}
		$new_key = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		// phpcs:enable
		if ( '' !== $new_key ) {
			$values['api_key'] = $new_key;
			delete_transient( 'voozin_domains' );
		}
		Voozin_Settings::update( $values );
		self::back( 'voozin', __( 'Settings saved.', 'voozin' ) );
	}

	/**
	 * Reloads the domain list.
	 */
	public static function handle_refresh_domains() {
		self::guard( 'refresh_domains' );
		$domains = Voozin_API::domains( true );
		if ( is_wp_error( $domains ) ) {
			self::back( 'voozin', $domains->get_error_message(), 'error' );
		}
		self::back( 'voozin', __( 'Domains refreshed.', 'voozin' ) );
	}

	/**
	 * Sends a test email through wp_mail(), so it takes the same path as every other email.
	 */
	public static function handle_test_email() {
		self::guard( 'test_email' );
		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $to ) {
			self::back( 'voozin', __( 'Enter a valid email address.', 'voozin' ), 'error' );
		}
		$error = null;
		$catch = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error;
		};
		add_action( 'wp_mail_failed', $catch );
		$sent = wp_mail(
			$to,
			__( 'Voozin test email', 'voozin' ),
			'<p>' . esc_html__( 'This test email was sent from WordPress through Voozin.', 'voozin' ) . '</p><p>' . esc_html( home_url() ) . '</p>',
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
		remove_action( 'wp_mail_failed', $catch );
		if ( ! $sent ) {
			$message = $error instanceof WP_Error ? $error->get_error_message() : __( 'The email could not be sent.', 'voozin' );
			self::back( 'voozin', $message, 'error' );
		}
		$page = Voozin_Log::page( 1, 1 );
		$last = $page['rows'] ? $page['rows'][0] : null;
		if ( $last && 'php-mail' === $last->via ) {
			/* translators: %s: error message. */
			self::back( 'voozin', sprintf( __( 'Voozin failed (%s); the email was sent with PHP mail instead.', 'voozin' ), (string) $last->error ), 'error' );
		}
		/* translators: %s: email address. */
		self::back( 'voozin', sprintf( __( 'Test email sent to %s through Voozin.', 'voozin' ), $to ) );
	}

	/**
	 * Email log page.
	 */
	public static function page_log() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$per    = 30;
		$result = Voozin_Log::page( $paged, $per, $status );
		$pages  = (int) ceil( $result['total'] / $per );
		$labels = array(
			''           => __( 'All', 'voozin' ),
			'sent'       => __( 'Sent', 'voozin' ),
			'delivered'  => __( 'Delivered', 'voozin' ),
			'delayed'    => __( 'Delayed', 'voozin' ),
			'bounced'    => __( 'Bounced', 'voozin' ),
			'complained' => __( 'Complained', 'voozin' ),
			'failed'     => __( 'Failed', 'voozin' ),
		);
		?>
		<div class="wrap voozin">
			<h1><?php esc_html_e( 'Email log', 'voozin' ); ?></h1>
			<?php if ( '' === (string) Voozin_Settings::get( 'endpoint_id' ) ) : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to the inbox page. */
						esc_html__( 'Statuses stay at "Sent" until you connect the webhook on the %s page; then deliveries and bounces update here.', 'voozin' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=voozin-inbox' ) ) . '">' . esc_html__( 'Inbox', 'voozin' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>
			<ul class="subsubsub">
				<?php
				$links = array();
				foreach ( $labels as $key => $label ) {
					$url     = add_query_arg(
						array(
							'page'   => 'voozin-log',
							'status' => $key,
						),
						admin_url( 'admin.php' )
					);
					$links[] = '<li><a href="' . esc_url( $url ) . '"' . ( $key === $status ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
				}
				echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</ul>
			<table class="widefat striped voozin-log">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'voozin' ); ?></th>
						<th><?php esc_html_e( 'To', 'voozin' ); ?></th>
						<th><?php esc_html_e( 'Subject', 'voozin' ); ?></th>
						<th><?php esc_html_e( 'Status', 'voozin' ); ?></th>
						<th><?php esc_html_e( 'Details', 'voozin' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( ! $result['rows'] ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No emails yet.', 'voozin' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $result['rows'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $row->created_at, 'Y-m-d H:i' ) ); ?></td>
							<td><?php echo esc_html( $row->recipients ); ?></td>
							<td><?php echo esc_html( $row->subject ); ?></td>
							<td><span class="voozin-status voozin-status-<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( isset( $labels[ $row->status ] ) ? $labels[ $row->status ] : $row->status ); ?></span></td>
							<td>
								<?php
								if ( 'php-mail' === $row->via ) {
									esc_html_e( 'Sent with PHP mail (fallback).', 'voozin' );
									echo ' ';
								}
								echo esc_html( (string) $row->error );
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php self::pagination( $paged, $pages, array( 'page' => 'voozin-log', 'status' => $status ) ); ?>
		</div>
		<?php
	}

	/**
	 * Page links.
	 *
	 * @param int                  $current Current page.
	 * @param int                  $total   Total pages.
	 * @param array<string, mixed> $args    Query args.
	 */
	private static function pagination( $current, $total, array $args ) {
		if ( $total < 2 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'base'    => add_query_arg( array_merge( $args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
				'format'  => '',
				'current' => $current,
				'total'   => $total,
			)
		);
		echo '</div></div>';
	}

	/**
	 * Inbox page: connection, conversation list or one conversation.
	 */
	public static function page_inbox() {
		$thread = isset( $_GET['thread'] ) ? absint( $_GET['thread'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="wrap voozin">';
		if ( $thread ) {
			self::render_thread( $thread );
		} else {
			echo '<h1>' . esc_html__( 'Inbox', 'voozin' ) . '</h1>';
			self::notice();
			self::render_connection();
			self::render_threads();
		}
		echo '</div>';
	}

	/**
	 * Webhook and inbound route setup.
	 */
	private static function render_connection() {
		if ( '' === Voozin_Settings::api_key() ) {
			echo '<p>' . esc_html__( 'Add your API key on the Settings page first.', 'voozin' ) . '</p>';
			return;
		}
		$endpoint = (string) Voozin_Settings::get( 'endpoint_id' );
		$routes   = (array) Voozin_Settings::get( 'routes' );
		echo '<div class="voozin-card">';
		if ( '' === $endpoint ) {
			echo '<h2>' . esc_html__( 'Connect this site', 'voozin' ) . '</h2>';
			echo '<p>' . esc_html__( 'Creates a webhook in Voozin that sends received email and delivery updates to this site:', 'voozin' ) . ' <code>' . esc_html( Voozin_Webhook::url() ) . '</code></p>';
			if ( 0 !== strpos( Voozin_Webhook::url(), 'https://' ) ) {
				echo '<p class="voozin-error">' . esc_html__( 'This site does not use HTTPS. Voozin only delivers webhooks to public HTTPS addresses.', 'voozin' ) . '</p>';
			}
			self::form( 'connect', '<p><button class="button button-primary">' . esc_html__( 'Connect', 'voozin' ) . '</button></p>' );
			echo '</div>';
			return;
		}

		echo '<h2>' . esc_html__( 'Receiving addresses', 'voozin' ) . '</h2>';
		if ( $routes ) {
			echo '<ul class="voozin-routes">';
			foreach ( $routes as $route ) {
				$remove = '<input type="hidden" name="route_id" value="' . esc_attr( $route['id'] ) . '"><button class="button-link voozin-danger">' . esc_html__( 'Remove', 'voozin' ) . '</button>';
				echo '<li><code>' . esc_html( $route['address'] ) . '</code> ';
				self::form( 'remove_route', $remove, 'voozin-inline' );
				echo '</li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'Add an address to start receiving email here. Its domain needs an MX record pointing to Voozin.', 'voozin' ) . '</p>';
		}

		$domains = Voozin_API::domains();
		if ( is_wp_error( $domains ) ) {
			echo '<p class="voozin-error">' . esc_html( $domains->get_error_message() ) . '</p>';
		} elseif ( $domains ) {
			$options = '';
			foreach ( $domains as $entry ) {
				$options .= '<option value="' . esc_attr( $entry['id'] . '|' . $entry['name'] ) . '">' . esc_html( $entry['name'] ) . '</option>';
			}
			self::form(
				'add_route',
				'<p><input type="text" name="pattern" value="support" class="voozin-local" required> @ <select name="domain">' . $options . '</select> ' .
				'<button class="button">' . esc_html__( 'Add address', 'voozin' ) . '</button></p>' .
				'<p class="description">' . esc_html__( 'Use an address without a mailbox in Voozin, a prefix like reply+* or * for every address without a mailbox.', 'voozin' ) . '</p>'
			);
		}
		self::form( 'disconnect', '<p><button class="button-link voozin-danger">' . esc_html__( 'Disconnect this site', 'voozin' ) . '</button></p>' );
		echo '</div>';
	}

	/**
	 * Conversation list.
	 */
	private static function render_threads() {
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$query = Voozin_Inbox::threads( $paged, 25 );
		echo '<table class="widefat voozin-threads"><tbody>';
		if ( ! $query->have_posts() ) {
			echo '<tr><td>' . esc_html__( 'No messages yet.', 'voozin' ) . '</td></tr>';
		}
		foreach ( $query->posts as $post ) {
			$from   = (array) get_post_meta( $post->ID, '_voozin_from', true );
			$who    = ! empty( $from['name'] ) ? $from['name'] : ( isset( $from['address'] ) ? $from['address'] : '' );
			$unread = (int) get_post_meta( $post->ID, '_voozin_unread', true );
			$count  = count( Voozin_Inbox::messages( $post->ID ) );
			$last   = (int) get_post_meta( $post->ID, '_voozin_last_at', true );
			$url    = add_query_arg(
				array(
					'page'   => 'voozin-inbox',
					'thread' => $post->ID,
				),
				admin_url( 'admin.php' )
			);
			printf(
				'<tr class="%s"><td class="voozin-who">%s</td><td><a href="%s">%s</a>%s</td><td class="voozin-when">%s</td></tr>',
				$unread ? 'voozin-unread' : '',
				esc_html( $who ),
				esc_url( $url ),
				esc_html( $post->post_title ),
				$count > 1 ? ' <span class="voozin-count">' . (int) $count . '</span>' : '',
				esc_html( $last ? wp_date( 'j M H:i', $last ) : '' )
			);
		}
		echo '</tbody></table>';
		self::pagination( $paged, (int) $query->max_num_pages, array( 'page' => 'voozin-inbox' ) );
	}

	/**
	 * One conversation with a reply form.
	 *
	 * @param int $root Root post id.
	 */
	private static function render_thread( $root ) {
		$post = get_post( $root );
		if ( ! $post || Voozin_Inbox::POST_TYPE !== $post->post_type ) {
			echo '<p>' . esc_html__( 'Conversation not found.', 'voozin' ) . '</p>';
			return;
		}
		Voozin_Inbox::mark_read( $root );
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=voozin-inbox' ) ) . '">← ' . esc_html__( 'Inbox', 'voozin' ) . '</a></p>';
		echo '<h1>' . esc_html( $post->post_title ) . '</h1>';
		self::notice();

		foreach ( Voozin_Inbox::messages( $root ) as $message ) {
			$direction = get_post_meta( $message->ID, '_voozin_direction', true );
			$from      = (array) get_post_meta( $message->ID, '_voozin_from', true );
			$who       = trim( ( ! empty( $from['name'] ) ? $from['name'] . ' ' : '' ) . ( ! empty( $from['address'] ) ? '<' . $from['address'] . '>' : '' ) );
			if ( 'out' === $direction ) {
				$author = get_userdata( (int) get_post_meta( $message->ID, '_voozin_author', true ) );
				/* translators: %s: WordPress user name. */
				$who = $author ? sprintf( __( '%s (reply)', 'voozin' ), $author->display_name ) : __( 'Reply', 'voozin' );
			}
			echo '<div class="voozin-message voozin-' . esc_attr( (string) $direction ) . '">';
			echo '<div class="voozin-meta"><strong>' . esc_html( $who ) . '</strong> <span>' . esc_html( get_the_date( 'j M Y H:i', $message ) ) . '</span></div>';

			$auth = (array) get_post_meta( $message->ID, '_voozin_auth', true );
			if ( 'in' === $direction && isset( $auth['aligned'] ) && ! $auth['aligned'] ) {
				echo '<p class="voozin-warning">' . esc_html__( 'This message failed sender authentication (SPF/DKIM/DMARC). The sender may be forged.', 'voozin' ) . '</p>';
			}

			$html = (string) get_post_meta( $message->ID, '_voozin_html', true );
			if ( 'in' === $direction && '' !== $html ) {
				// Sandboxed without scripts, forms or same-origin access.
				echo '<iframe class="voozin-body-html" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" srcdoc="' . esc_attr( '<base target="_blank">' . $html ) . '"></iframe>';
			} else {
				echo '<div class="voozin-body-text">' . nl2br( esc_html( (string) get_post_meta( $message->ID, '_voozin_text', true ) ) ) . '</div>';
			}

			$attachments = get_post_meta( $message->ID, '_voozin_attachments', true );
			$attachments = is_array( $attachments ) ? array_filter( $attachments, 'is_array' ) : array();
			if ( $attachments ) {
				echo '<ul class="voozin-attachments">';
				foreach ( $attachments as $item ) {
					$url = wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'voozin_attachment',
								'post'   => $message->ID,
								'index'  => (int) $item['index'],
							),
							admin_url( 'admin-post.php' )
						),
						'voozin_attachment'
					);
					$size = isset( $item['size'] ) ? size_format( (int) $item['size'] ) : '';
					echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( ! empty( $item['filename'] ) ? $item['filename'] : __( 'attachment', 'voozin' ) ) . '</a> <span>' . esc_html( (string) $size ) . '</span></li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}

		self::form(
			'reply',
			'<input type="hidden" name="thread" value="' . esc_attr( (string) $root ) . '">' .
			'<p><label for="voozin-reply" class="screen-reader-text">' . esc_html__( 'Reply', 'voozin' ) . '</label>' .
			'<textarea id="voozin-reply" name="body" rows="8" class="large-text" required placeholder="' . esc_attr__( 'Write a reply…', 'voozin' ) . '"></textarea></p>' .
			'<p><button class="button button-primary">' . esc_html__( 'Send reply', 'voozin' ) . '</button></p>',
			'voozin-reply'
		);
	}

	/**
	 * Creates the webhook endpoint in Voozin.
	 */
	public static function handle_connect() {
		self::guard( 'connect' );
		$response = Voozin_API::request(
			'POST',
			'/v1/webhooks',
			array(
				'url'         => Voozin_Webhook::url(),
				'events'      => self::EVENTS,
				/* translators: %s: site name. */
				'description' => sprintf( __( 'WordPress: %s', 'voozin' ), get_bloginfo( 'name' ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::back( 'voozin-inbox', $response->get_error_message(), 'error' );
		}
		Voozin_Settings::set_webhook_secret( (string) $response['secret'] );
		Voozin_Settings::update( array( 'endpoint_id' => (string) $response['id'] ) );
		self::back( 'voozin-inbox', __( 'Connected. Delivery updates now appear in the email log. Add an address to receive email.', 'voozin' ) );
	}

	/**
	 * Creates an inbound route to the site's endpoint.
	 */
	public static function handle_add_route() {
		self::guard( 'add_route' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$pattern = isset( $_POST['pattern'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['pattern'] ) ) ) ) : '';
		$domain  = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		// phpcs:enable
		list( $domain_id, $domain_name ) = array_pad( explode( '|', $domain, 2 ), 2, '' );
		if ( '' === $pattern || '' === $domain_id ) {
			self::back( 'voozin-inbox', __( 'Choose an address and a domain.', 'voozin' ), 'error' );
		}
		$response = Voozin_API::request(
			'POST',
			'/v1/inbound-routes',
			array(
				'domain_id'   => $domain_id,
				'pattern'     => $pattern,
				'endpoint_id' => (string) Voozin_Settings::get( 'endpoint_id' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::back( 'voozin-inbox', $response->get_error_message(), 'error' );
		}
		$routes   = (array) Voozin_Settings::get( 'routes' );
		$routes[] = array(
			'id'      => (string) $response['id'],
			'address' => isset( $response['address'] ) ? (string) $response['address'] : $pattern . '@' . $domain_name,
		);
		Voozin_Settings::update( array( 'routes' => $routes ) );
		/* translators: %s: email address. */
		self::back( 'voozin-inbox', sprintf( __( 'Email to %s now arrives here.', 'voozin' ), $pattern . '@' . $domain_name ) );
	}

	/**
	 * Deletes an inbound route.
	 */
	public static function handle_remove_route() {
		self::guard( 'remove_route' );
		$route_id = isset( $_POST['route_id'] ) ? sanitize_text_field( wp_unslash( $_POST['route_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$response = Voozin_API::request( 'DELETE', '/v1/inbound-routes/' . rawurlencode( $route_id ) );
		$status = is_wp_error( $response ) ? $response->get_error_data() : null;
		if ( is_wp_error( $response ) && ! ( is_array( $status ) && isset( $status['status'] ) && 404 === (int) $status['status'] ) ) {
			self::back( 'voozin-inbox', $response->get_error_message(), 'error' );
		}
		$routes = array_values(
			array_filter(
				(array) Voozin_Settings::get( 'routes' ),
				static function ( $route ) use ( $route_id ) {
					return $route['id'] !== $route_id;
				}
			)
		);
		Voozin_Settings::update( array( 'routes' => $routes ) );
		self::back( 'voozin-inbox', __( 'Address removed.', 'voozin' ) );
	}

	/**
	 * Deletes the routes and the webhook endpoint in Voozin. Stored messages stay.
	 */
	public static function handle_disconnect() {
		self::guard( 'disconnect' );
		self::disconnect_remote();
		self::back( 'voozin-inbox', __( 'Disconnected.', 'voozin' ) );
	}

	/**
	 * Best-effort removal of the site's routes and endpoint in Voozin.
	 */
	public static function disconnect_remote() {
		foreach ( (array) Voozin_Settings::get( 'routes' ) as $route ) {
			Voozin_API::request( 'DELETE', '/v1/inbound-routes/' . rawurlencode( $route['id'] ) );
		}
		$endpoint = (string) Voozin_Settings::get( 'endpoint_id' );
		if ( '' !== $endpoint ) {
			Voozin_API::request( 'DELETE', '/v1/webhooks/' . rawurlencode( $endpoint ) );
		}
		Voozin_Settings::update(
			array(
				'endpoint_id' => '',
				'routes'      => array(),
			)
		);
		Voozin_Settings::set_webhook_secret( '' );
	}

	/**
	 * Sends a reply from a conversation.
	 */
	public static function handle_reply() {
		self::guard( 'reply' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$root = isset( $_POST['thread'] ) ? absint( $_POST['thread'] ) : 0;
		$body = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		// phpcs:enable
		if ( '' === trim( $body ) ) {
			self::back( 'voozin-inbox', __( 'The reply is empty.', 'voozin' ), 'error', array( 'thread' => $root ) );
		}
		$result = Voozin_Inbox::reply( $root, $body );
		if ( is_wp_error( $result ) ) {
			self::back( 'voozin-inbox', $result->get_error_message(), 'error', array( 'thread' => $root ) );
		}
		self::back( 'voozin-inbox', __( 'Reply sent.', 'voozin' ), 'success', array( 'thread' => $root ) );
	}

	/**
	 * Streams an attachment.
	 */
	public static function handle_attachment() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voozin' ), 403 );
		}
		check_admin_referer( 'voozin_attachment' );
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$index   = isset( $_GET['index'] ) ? absint( $_GET['index'] ) : 0;
		$file    = Voozin_Inbox::attachment( $post_id, $index );
		if ( is_wp_error( $file ) ) {
			wp_die( esc_html( $file->get_error_message() ) );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( array( '"', "\r", "\n" ), '', sanitize_file_name( $file['filename'] ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $file['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
