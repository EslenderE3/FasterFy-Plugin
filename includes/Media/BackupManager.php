<?php
/**
 * Arquitectura no destructiva: crea respaldos del activo original antes de
 * la mutación nativa y permite revertir (rollback) con un solo clic.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Media;

use FasterFy\Contracts\Bootable;
use FasterFy\Logger;
use FasterFy\Settings;
use FasterFy\Support\DatabaseRewriter;

defined( 'ABSPATH' ) || exit;

/**
 * Gestiona respaldos aislados y restauración de adjuntos.
 */
final class BackupManager implements Bootable {

	private const META_KEY = '_fasterfy_backup';

	/**
	 * Ajustes.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private Logger $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		// Al borrar un adjunto, limpiamos su respaldo asociado.
		add_action( 'delete_attachment', [ $this, 'on_delete_attachment' ] );
	}

	/**
	 * Directorio base de respaldos.
	 *
	 * @return string
	 */
	public function backup_dir(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'fasterfy-backups';
	}

	/**
	 * Crea un respaldo del archivo original (y de sus miniaturas) antes
	 * de la optimización. No sobrescribe un respaldo ya existente.
	 *
	 * @param int $attachment_id ID del adjunto.
	 * @return bool True si existe un respaldo válido tras la llamada.
	 */
	public function backup( int $attachment_id ): bool {
		// Si ya hay respaldo, no duplicamos.
		if ( $this->has_backup( $attachment_id ) ) {
			return true;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			$this->logger->warning( __( 'No se pudo respaldar: archivo inexistente.', 'fasterfy' ), 'backup', $attachment_id );
			return false;
		}

		$dest_dir = trailingslashit( $this->backup_dir() ) . $attachment_id;
		if ( ! wp_mkdir_p( $dest_dir ) ) {
			$this->logger->error( __( 'No se pudo crear el directorio de respaldo.', 'fasterfy' ), 'backup', $attachment_id );
			return false;
		}

		$basename = wp_basename( $file );
		$dest     = trailingslashit( $dest_dir ) . $basename;

		if ( ! @copy( $file, $dest ) ) { // phpcs:ignore
			$this->logger->error( __( 'Falló la copia de respaldo del original.', 'fasterfy' ), 'backup', $attachment_id );
			return false;
		}

		$record = [
			'original_file'     => $file,
			'original_basename' => $basename,
			'backup_path'       => $dest,
			'mime'              => (string) get_post_mime_type( $attachment_id ),
			'metadata'          => wp_get_attachment_metadata( $attachment_id ),
			'attached_file'     => get_post_meta( $attachment_id, '_wp_attached_file', true ),
			'alt'               => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'created_at'        => current_time( 'mysql', true ),
			'size'              => (int) filesize( $file ),
		];

		update_post_meta( $attachment_id, self::META_KEY, $record );
		$this->logger->info( __( 'Respaldo del original creado.', 'fasterfy' ), 'backup', $attachment_id );

		return true;
	}

	/**
	 * Indica si el adjunto tiene un respaldo válido.
	 *
	 * @param int $attachment_id ID.
	 * @return bool
	 */
	public function has_backup( int $attachment_id ): bool {
		$record = get_post_meta( $attachment_id, self::META_KEY, true );
		return is_array( $record ) && ! empty( $record['backup_path'] ) && file_exists( $record['backup_path'] );
	}

	/**
	 * Restaura el archivo original desde el respaldo y revierte la BD.
	 *
	 * @param int $attachment_id ID.
	 * @return bool
	 */
	public function rollback( int $attachment_id ): bool {
		$record = get_post_meta( $attachment_id, self::META_KEY, true );
		if ( ! is_array( $record ) || empty( $record['backup_path'] ) || ! file_exists( $record['backup_path'] ) ) {
			$this->logger->warning( __( 'No hay respaldo para restaurar.', 'fasterfy' ), 'rollback', $attachment_id );
			return false;
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$uploads          = wp_upload_dir();
		$current_file     = get_attached_file( $attachment_id );
		$original_file    = (string) $record['original_file'];
		$current_metadata = wp_get_attachment_metadata( $attachment_id );

		// URL actual (post-optimización) para reescribir hacia la original.
		$current_url  = $current_file ? trailingslashit( $uploads['baseurl'] ) . _wp_relative_upload_path( $current_file ) : '';
		$original_url = trailingslashit( $uploads['baseurl'] ) . _wp_relative_upload_path( $original_file );

		// Elimina miniaturas actuales.
		if ( is_array( $current_metadata ) && ! empty( $current_metadata['sizes'] ) ) {
			$dir = trailingslashit( dirname( (string) $current_file ) );
			foreach ( $current_metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) && file_exists( $dir . $size['file'] ) ) {
					@unlink( $dir . $size['file'] ); // phpcs:ignore
				}
			}
		}

		// Elimina el archivo optimizado actual si difiere del original.
		if ( $current_file && $current_file !== $original_file && file_exists( $current_file ) ) {
			@unlink( $current_file ); // phpcs:ignore
		}

		// Restaura el archivo original desde el respaldo.
		if ( ! @copy( $record['backup_path'], $original_file ) ) { // phpcs:ignore
			$this->logger->error( __( 'Falló la restauración del archivo original.', 'fasterfy' ), 'rollback', $attachment_id );
			return false;
		}

		// Restaura punteros del adjunto.
		update_attached_file( $attachment_id, $original_file );
		wp_update_post(
			[
				'ID'             => $attachment_id,
				'post_mime_type' => (string) $record['mime'],
				'guid'           => $original_url,
			]
		);
		if ( ! empty( $record['attached_file'] ) ) {
			update_post_meta( $attachment_id, '_wp_attached_file', $record['attached_file'] );
		}

		// Regenera o restaura metadatos.
		if ( ! empty( $record['metadata'] ) && is_array( $record['metadata'] ) ) {
			wp_update_attachment_metadata( $attachment_id, $record['metadata'] );
			// Regenera físicamente las miniaturas a partir del original.
			$meta = wp_generate_attachment_metadata( $attachment_id, $original_file );
			if ( is_array( $meta ) ) {
				wp_update_attachment_metadata( $attachment_id, $meta );
			}
		}

		// Reescribe referencias en BD de la URL optimizada a la original.
		if ( $current_url && $current_url !== $original_url ) {
			DatabaseRewriter::replace_url( $current_url, $original_url );
		}

		// Restaura el alt text original (o lo elimina si no existía).
		if ( '' !== (string) ( $record['alt'] ?? '' ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $record['alt'] );
		}

		// Limpia los metadatos de optimización.
		$this->purge_optimization_meta( $attachment_id );

		// Elimina el respaldo del disco y su registro.
		$this->delete_backup_files( $attachment_id );
		delete_post_meta( $attachment_id, self::META_KEY );

		clean_post_cache( $attachment_id );
		$this->logger->info( __( 'Adjunto restaurado a su estado original.', 'fasterfy' ), 'rollback', $attachment_id );

		return true;
	}

	/**
	 * Limpia los metadatos de optimización de un adjunto.
	 *
	 * @param int $attachment_id ID.
	 * @return void
	 */
	private function purge_optimization_meta( int $attachment_id ): void {
		$keys = [
			'_fasterfy_status',
			'_fasterfy_original_size',
			'_fasterfy_optimized_size',
			'_fasterfy_saved_bytes',
			'_fasterfy_format_from',
			'_fasterfy_format_to',
			'_fasterfy_optimized_at',
			'_fasterfy_renamed_to',
			'_fasterfy_ai_status',
			'_fasterfy_ai_attempts',
			'_fasterfy_ai_at',
		];
		foreach ( $keys as $key ) {
			delete_post_meta( $attachment_id, $key );
		}
	}

	/**
	 * Elimina los archivos de respaldo de un adjunto.
	 *
	 * @param int $attachment_id ID.
	 * @return void
	 */
	private function delete_backup_files( int $attachment_id ): void {
		$dir = trailingslashit( $this->backup_dir() ) . $attachment_id;
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = glob( trailingslashit( $dir ) . '*' );
		foreach ( $files ?: [] as $f ) {
			if ( is_file( $f ) ) {
				@unlink( $f ); // phpcs:ignore
			}
		}
		@rmdir( $dir ); // phpcs:ignore
	}

	/**
	 * Limpia el respaldo cuando se elimina el adjunto.
	 *
	 * @param int $attachment_id ID.
	 * @return void
	 */
	public function on_delete_attachment( int $attachment_id ): void {
		$this->delete_backup_files( $attachment_id );
	}

	/**
	 * Calcula el tamaño total ocupado por los respaldos.
	 *
	 * @return int Bytes.
	 */
	public function total_backup_size(): int {
		$total = 0;
		$base  = $this->backup_dir();
		if ( ! is_dir( $base ) ) {
			return 0;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( $file->isFile() ) {
				$total += $file->getSize();
			}
		}
		return $total;
	}
}
