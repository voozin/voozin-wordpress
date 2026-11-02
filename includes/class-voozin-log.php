<?php
/**
 * Email log.
 *
 * @package Voozin
 */

defined( 'ABSPATH' ) || exit;

/**
 * One row per wp_mail() call, updated by delivery webhooks. Rows older than the configured number of days are purged daily.
 */
class Voozin_Log {

	const DB_VERSION = '1';
	const CRON_HOOK  = 'voozin_purge_log';

	/** Later statuses never go back to earlier ones (a bounce is not overwritten by a delayed retry). */
	const STATUS_RANK = array(
		'sent'      => 1,
		'delayed'   => 2,
		'delivered' => 3,
		'bounced'   => 4,
		'complained' => 5,
		'failed'    => 6,
	);

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'voozin_log';
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'purge' ) );
		if ( get_option( 'voozin_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Creates the table and schedules the purge.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				recipients text NOT NULL,
				subject varchar(255) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL,
				email_id varchar(64) NOT NULL DEFAULT '',
				via varchar(20) NOT NULL DEFAULT 'voozin',
				error text NULL,
				PRIMARY KEY  (id),
				KEY email_id (email_id),
				KEY created_at (created_at)
			) {$charset};"
		);
		update_option( 'voozin_db_version', self::DB_VERSION, true );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Removes the cron event on deactivation.
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Adds a row.
	 *
	 * @param string[] $recipients Recipients.
	 * @param string   $subject    Subject.
	 * @param string   $status     sent or failed.
	 * @param string   $email_id   Voozin email id.
	 * @param string   $error      Error message.
	 * @param string   $via        voozin or php-mail (fallback).
	 * @return int Row id.
	 */
	public static function add( array $recipients, $subject, $status, $email_id = '', $error = '', $via = 'voozin' ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'created_at' => $now,
				'updated_at' => $now,
				'recipients' => implode( ', ', $recipients ),
				'subject'    => mb_substr( (string) $subject, 0, 255 ),
				'status'     => $status,
				'email_id'   => $email_id,
				'via'        => $via,
				'error'      => '' === $error ? null : $error,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Applies a delivery event to the row for an email.
	 *
	 * @param string $email_id Voozin email id.
	 * @param string $status   delivered, bounced, complained or delayed.
	 * @param string $detail   Bounce reason or similar.
	 */
	public static function update_status( $email_id, $status, $detail = '' ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE email_id = %s", $email_id ) );
		if ( ! $row ) {
			return;
		}
		$current = isset( self::STATUS_RANK[ $row->status ] ) ? self::STATUS_RANK[ $row->status ] : 0;
		if ( self::STATUS_RANK[ $status ] < $current ) {
			return;
		}
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
				'error'      => '' === $detail ? null : $detail,
			),
			array( 'id' => $row->id )
		);
	}

	/**
	 * Deletes rows older than the configured retention.
	 */
	public static function purge() {
		global $wpdb;
		$days  = max( 1, (int) Voozin_Settings::get( 'log_days' ) );
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * A page of rows, newest first.
	 *
	 * @param int    $page     1-based page.
	 * @param int    $per_page Rows per page.
	 * @param string $status   Optional status filter.
	 * @return array{rows: array<int, object>, total: int}
	 */
	public static function page( $page, $per_page, $status = '' ) {
		global $wpdb;
		$table  = self::table();
		$where  = '' === $status ? '1=1' : $wpdb->prepare( 'status = %s', $status );
		$offset = max( 0, ( $page - 1 ) * $per_page );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ) );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		// phpcs:enable
		return array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}
}
