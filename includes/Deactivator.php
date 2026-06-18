<?php
/**
 * Lógica de desactivación del plugin.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy;

defined( 'ABSPATH' ) || exit;

/**
 * Se ejecuta al desactivar el plugin. No destruye datos del usuario.
 */
final class Deactivator {

	/**
	 * Acción de desactivación: limpia tareas programadas y caches transitorias.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Cancela las tareas de Action Scheduler propias, si existen.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', [], 'fasterfy' );
		}

		// Limpia el cron de respaldo.
		$timestamp = wp_next_scheduled( 'fasterfy_process_batch' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'fasterfy_process_batch' );
		}
		wp_clear_scheduled_hook( 'fasterfy_process_batch' );

		delete_transient( 'fasterfy_scan_cache' );

		flush_rewrite_rules();
	}
}
