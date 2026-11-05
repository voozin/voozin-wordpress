<?php
/**
 * Removes everything the plugin stored: settings, the email log, received messages and the
 * webhook and inbound routes it created in Voozin.
 *
 * @package Voozin
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-voozin-settings.php';
require_once __DIR__ . '/includes/class-voozin-api.php';
require_once __DIR__ . '/includes/class-voozin-log.php';
require_once __DIR__ . '/includes/class-voozin-webhook.php';
require_once __DIR__ . '/includes/class-voozin-inbox.php';
require_once __DIR__ . '/includes/class-voozin-admin.php';

if ( ! defined( 'VOOZIN_VERSION' ) ) {
	define( 'VOOZIN_VERSION', 'uninstall' );
}

Voozin_Admin::disconnect_remote();

global $wpdb;
$voozin_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", Voozin_Inbox::POST_TYPE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
foreach ( $voozin_ids as $voozin_id ) {
	wp_delete_post( (int) $voozin_id, true );
}

$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', Voozin_Log::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_voozin\\_%' OR option_name LIKE '\\_transient\\_timeout\\_voozin\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

delete_option( Voozin_Settings::OPTION );
delete_option( Voozin_Settings::SECRET_OPTION );
delete_option( 'voozin_db_version' );
wp_clear_scheduled_hook( Voozin_Log::CRON_HOOK );
