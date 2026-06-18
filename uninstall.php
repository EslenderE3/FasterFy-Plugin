<?php
/**
 * Rutina de desinstalación de FasterFy.
 *
 * Solo elimina datos si el usuario activó explícitamente la opción
 * "eliminar datos al desinstalar". Por defecto, conserva la información.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

// Solo se ejecuta desde el proceso oficial de desinstalación de WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'fasterfy_settings', [] );
$purge    = is_array( $settings ) && ! empty( $settings['advanced']['delete_data_on_uninstall'] );

if ( ! $purge ) {
	return;
}

global $wpdb;

// Borra opciones.
delete_option( 'fasterfy_settings' );
delete_option( 'fasterfy_db_version' );
delete_option( 'fasterfy_stats' );

// Borra la tabla de logs.
$table = $wpdb->prefix . 'fasterfy_log';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

// Borra postmeta generada por el plugin.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_fasterfy_%'" ); // phpcs:ignore WordPress.DB

// Limpia transitorios.
delete_transient( 'fasterfy_scan_cache' );
