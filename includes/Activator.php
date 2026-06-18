<?php
/**
 * Lógica de activación del plugin.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy;

defined( 'ABSPATH' ) || exit;

/**
 * Se ejecuta una sola vez al activar el plugin.
 */
final class Activator {

	/**
	 * Acción de activación: crea opciones por defecto, tablas y directorios.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::seed_default_options();
		self::create_log_table();
		self::prepare_directories();

		// Marca de versión para futuras migraciones.
		update_option( 'fasterfy_db_version', FASTERFY_VERSION );

		flush_rewrite_rules();
	}

	/**
	 * Inserta las opciones por defecto si aún no existen.
	 *
	 * @return void
	 */
	private static function seed_default_options(): void {
		if ( false === get_option( Settings::OPTION_KEY, false ) ) {
			add_option( Settings::OPTION_KEY, Settings::defaults() );
		}
	}

	/**
	 * Crea la tabla de logs del procesamiento.
	 *
	 * @return void
	 */
	private static function create_log_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table           = $wpdb->prefix . 'fasterfy_log';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NULL DEFAULT NULL,
			level VARCHAR(20) NOT NULL DEFAULT 'info',
			context VARCHAR(60) NOT NULL DEFAULT 'general',
			message TEXT NOT NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY attachment_id (attachment_id),
			KEY level (level),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Crea el directorio aislado de respaldos no destructivos.
	 *
	 * @return void
	 */
	private static function prepare_directories(): void {
		$uploads = wp_upload_dir();
		$backup  = trailingslashit( $uploads['basedir'] ) . 'fasterfy-backups';

		if ( ! file_exists( $backup ) ) {
			wp_mkdir_p( $backup );
		}

		// Protege el directorio de accesos directos y listados.
		$htaccess = trailingslashit( $backup ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Options -Indexes\nDeny from all\n" ); // phpcs:ignore
		}
		$index = trailingslashit( $backup ) . 'index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php // Silence is golden." ); // phpcs:ignore
		}
	}
}
