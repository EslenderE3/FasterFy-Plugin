<?php
/**
 * Orquestador de procesamiento a nivel de adjunto: selecciona el procesador
 * por MIME, ejecuta la transformación y realiza la mutación nativa
 * (reemplazo de archivo, regeneración de miniaturas y reescritura de
 * referencias en la base de datos).
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Processors;

use FasterFy\Logger;
use FasterFy\Settings;
use FasterFy\Support\DatabaseRewriter;

defined( 'ABSPATH' ) || exit;

/**
 * Punto de entrada de alto nivel para optimizar un adjunto.
 */
final class ProcessorFactory {

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
	 * Procesadores registrados.
	 *
	 * @var Contracts\Processor[]
	 */
	private array $processors;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;

		$engine           = new ImageEngine();
		$this->processors = [
			new JpegProcessor( $engine ),
			new PngProcessor( $engine ),
			new SvgSanitizer(),
		];
	}

	/**
	 * Devuelve las capacidades del entorno de imagen.
	 *
	 * @return array<string, bool>
	 */
	public function capabilities(): array {
		return ImageEngine::capabilities();
	}

	/**
	 * Lista de MIME types soportados.
	 *
	 * @return string[]
	 */
	public function supported_mimes(): array {
		return [ 'image/jpeg', 'image/jpg', 'image/png', 'image/svg+xml' ];
	}

	/**
	 * Indica si un MIME es procesable.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public function supports_mime( string $mime ): bool {
		foreach ( $this->processors as $p ) {
			if ( $p->supports( $mime ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Optimiza un adjunto completo, aplicando la mutación nativa.
	 *
	 * @param int                  $attachment_id ID del adjunto.
	 * @param array<string, mixed> $overrides     Sobrescrituras de opciones de conversión.
	 * @return ProcessResult
	 */
	public function process_attachment( int $attachment_id, array $overrides = [] ): ProcessResult {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return ProcessResult::fail( __( 'No se encontró el archivo del adjunto.', 'fasterfy' ) );
		}

		$mime = (string) get_post_mime_type( $attachment_id );

		$processor = $this->resolve_processor( $mime );
		if ( ! $processor ) {
			return ProcessResult::skip(
				sprintf(
					/* translators: %s = MIME type */
					__( 'Tipo de archivo no soportado: %s', 'fasterfy' ),
					$mime
				)
			);
		}

		$options = [
			'conversion' => array_merge( (array) $this->settings->get( 'conversion', [] ), $overrides ),
		];

		$result = $processor->process( $file, $options );

		if ( ! $result->success || $result->skipped ) {
			return $result;
		}

		// Confirma (commit) la mutación nativa en disco y base de datos.
		$committed = $this->commit( $attachment_id, $result );

		if ( $committed->success && ! $committed->skipped ) {
			$this->record_meta( $attachment_id, $committed );
			$this->logger->info(
				sprintf(
					/* translators: 1: origen, 2: destino, 3: ahorro % */
					__( 'Optimizado %1$s → %2$s (%3$s%% de ahorro).', 'fasterfy' ),
					$committed->source_mime,
					$committed->output_mime,
					$committed->savings_percent()
				),
				'processor',
				$attachment_id,
				$committed->to_array()
			);
		}

		return $committed;
	}

	/**
	 * Resuelve el procesador adecuado para un MIME.
	 *
	 * @param string $mime MIME type.
	 * @return Contracts\Processor|null
	 */
	private function resolve_processor( string $mime ): ?Contracts\Processor {
		foreach ( $this->processors as $p ) {
			if ( $p->supports( $mime ) ) {
				return $p;
			}
		}
		return null;
	}

	/**
	 * Aplica el resultado al sistema de archivos y a la base de datos.
	 *
	 * @param int           $attachment_id ID del adjunto.
	 * @param ProcessResult $result        Resultado del procesador.
	 * @return ProcessResult
	 */
	private function commit( int $attachment_id, ProcessResult $result ): ProcessResult {
		$original = $result->original_path;
		$temp     = $result->output_path;

		if ( ! file_exists( $temp ) ) {
			return ProcessResult::fail( __( 'El archivo temporal de salida no existe.', 'fasterfy' ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$old_metadata = wp_get_attachment_metadata( $attachment_id );
		$uploads      = wp_upload_dir();

		if ( $result->format_changed ) {
			// --- Cambio de formato (p.ej. jpg -> webp) ---
			$dir          = dirname( $original );
			$base         = pathinfo( $original, PATHINFO_FILENAME );
			$new_ext      = $this->ext_from_mime( $result->output_mime );
			$new_basename = wp_unique_filename( $dir, $base . '.' . $new_ext );
			$new_file     = trailingslashit( $dir ) . $new_basename;

			if ( ! @rename( $temp, $new_file ) ) { // phpcs:ignore
				@unlink( $temp ); // phpcs:ignore
				return ProcessResult::fail( __( 'No se pudo mover el archivo convertido.', 'fasterfy' ) );
			}

			// Elimina miniaturas antiguas del formato anterior.
			$this->delete_old_sizes( $original, $old_metadata, $uploads );
			// Elimina el archivo original (existe respaldo previo).
			if ( file_exists( $original ) ) {
				@unlink( $original ); // phpcs:ignore
			}

			$old_url = trailingslashit( $uploads['baseurl'] ) . _wp_relative_upload_path( $original );
			$new_url = trailingslashit( $uploads['baseurl'] ) . _wp_relative_upload_path( $new_file );

			// Actualiza punteros del adjunto.
			update_attached_file( $attachment_id, $new_file );
			wp_update_post(
				[
					'ID'             => $attachment_id,
					'post_mime_type' => $result->output_mime,
					'guid'           => $new_url,
				]
			);

			// Regenera miniaturas en el nuevo formato.
			$meta = wp_generate_attachment_metadata( $attachment_id, $new_file );
			if ( is_array( $meta ) ) {
				wp_update_attachment_metadata( $attachment_id, $meta );
			}

			// Reescribe referencias en contenido y metadatos.
			DatabaseRewriter::replace_url( $old_url, $new_url );

			$result->output_path = $new_file;
			$result->replaced    = true;
		} else {
			// --- Mismo formato (compresión PNG / sanitización SVG) ---
			if ( ! @rename( $temp, $original ) ) { // phpcs:ignore
				// Fallback: copia y borra.
				if ( ! @copy( $temp, $original ) ) { // phpcs:ignore
					@unlink( $temp ); // phpcs:ignore
					return ProcessResult::fail( __( 'No se pudo reemplazar el archivo original.', 'fasterfy' ) );
				}
				@unlink( $temp ); // phpcs:ignore
			}

			// Regenera miniaturas a partir del archivo optimizado (solo imágenes rasterizadas).
			if ( 'image/svg+xml' !== $result->output_mime ) {
				$meta = wp_generate_attachment_metadata( $attachment_id, $original );
				if ( is_array( $meta ) ) {
					wp_update_attachment_metadata( $attachment_id, $meta );
				}
			}

			$result->output_path = $original;
			$result->replaced    = true;
		}

		clean_post_cache( $attachment_id );
		return $result;
	}

	/**
	 * Elimina los archivos de miniaturas asociados a los metadatos antiguos.
	 *
	 * @param string                   $original     Ruta del archivo original.
	 * @param array<string, mixed>|false $metadata   Metadatos antiguos.
	 * @param array<string, mixed>     $uploads      wp_upload_dir().
	 * @return void
	 */
	private function delete_old_sizes( string $original, $metadata, array $uploads ): void {
		$dir = trailingslashit( dirname( $original ) );
		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$thumb = $dir . $size['file'];
					if ( file_exists( $thumb ) ) {
						@unlink( $thumb ); // phpcs:ignore
					}
				}
			}
		}
	}

	/**
	 * Deriva la extensión de archivo a partir del MIME.
	 *
	 * @param string $mime MIME type.
	 * @return string
	 */
	private function ext_from_mime( string $mime ): string {
		$map = [
			'image/webp'    => 'webp',
			'image/avif'    => 'avif',
			'image/jpeg'    => 'jpg',
			'image/png'     => 'png',
			'image/svg+xml' => 'svg',
		];
		return $map[ $mime ] ?? 'webp';
	}

	/**
	 * Guarda metadatos de optimización en el adjunto y actualiza estadísticas.
	 *
	 * @param int           $attachment_id ID.
	 * @param ProcessResult $result        Resultado.
	 * @return void
	 */
	private function record_meta( int $attachment_id, ProcessResult $result ): void {
		update_post_meta( $attachment_id, '_fasterfy_status', 'optimized' );
		update_post_meta( $attachment_id, '_fasterfy_original_size', $result->original_size );
		update_post_meta( $attachment_id, '_fasterfy_optimized_size', $result->output_size );
		update_post_meta( $attachment_id, '_fasterfy_saved_bytes', $result->bytes_saved() );
		update_post_meta( $attachment_id, '_fasterfy_format_from', $result->source_mime );
		update_post_meta( $attachment_id, '_fasterfy_format_to', $result->output_mime );
		update_post_meta( $attachment_id, '_fasterfy_optimized_at', current_time( 'mysql', true ) );

		// Actualiza estadísticas globales.
		$stats = get_option( 'fasterfy_stats', [] );
		$stats = is_array( $stats ) ? $stats : [];

		$stats['total_optimized'] = (int) ( $stats['total_optimized'] ?? 0 ) + 1;
		$stats['total_saved']     = (int) ( $stats['total_saved'] ?? 0 ) + $result->bytes_saved();
		$stats['last_run']        = current_time( 'mysql', true );

		update_option( 'fasterfy_stats', $stats, false );
	}
}
