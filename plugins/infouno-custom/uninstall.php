<?php
/**
 * Ejecutado por WordPress cuando el plugin se desinstala desde el panel.
 * Solo elimina datos si WP_UNINSTALL_PLUGIN está definido (evita ejecución directa).
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'infouno_messages',
    $wpdb->prefix . 'infouno_conversations',
    $wpdb->prefix . 'infouno_bots',
    $wpdb->prefix . 'infouno_tenants',
];

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

delete_option( 'infouno_db_version' );
