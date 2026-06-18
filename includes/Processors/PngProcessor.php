<?php
/**
 * Procesador PNG: compresión por cuantización de color conservando el
 * canal alfa (transparencia). Opcionalmente convierte a WebP/AVIF.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Processors;

use FasterFy\Processors\Contracts\Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Comprime PNG (estándar TinyPNG-like) o lo convierte si así se configura.
 */
final class PngProcessor implements Processor {

	/**
	 * Motor de imagen.
	 *
	 * @var ImageEngine
	 */
	private ImageEngine $engine;

	/**
	 * Constructor.
	 *
	 * @param ImageEngine $engine Motor de imagen.
	 */
	public function __construct( ImageEngine $engine ) {
		$this->engine = $engine;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( string $mime ): bool {
		return 'image/png' === $mime || 'image/x-png' === $mime;
	}

	/**
	 * {@inheritDoc}
	 */
	public function process( string $file, array $options = [] ): ProcessResult {
		if ( ! is_readable( $file ) ) {
			return ProcessResult::fail( __( 'El archivo PNG no es legible.', 'fasterfy' ) );
		}

		$conversion    = $options['conversion'] ?? [];
		$original_size = (int) filesize( $file );

		// Modo 1: convertir PNG a formato de próxima generación.
		$convert_png = ! empty( $conversion['convert_png'] );
		if ( $convert_png ) {
			$target  = $this->resolve_target( (string) ( $conversion['target_format'] ?? 'webp' ) );
			$quality = 'avif' === $target
				? (int) ( $conversion['avif_quality'] ?? 60 )
				: (int) ( $conversion['webp_quality'] ?? 80 );
			$temp    = $this->temp_path( $file, $target );
			$ok      = $this->engine->convert( $file, $temp, $target, $quality, (int) ( $conversion['max_width'] ?? 0 ), (bool) ( $conversion['strip_metadata'] ?? true ) );

			if ( $ok && file_exists( $temp ) ) {
				$output_size = (int) filesize( $temp );
				if ( $output_size > 0 && $output_size < $original_size ) {
					return ProcessResult::ok(
						[
							'original_path'  => $file,
							'output_path'    => $temp,
							'source_mime'    => 'image/png',
							'output_mime'    => 'image/' . $target,
							'original_size'  => $original_size,
							'output_size'    => $output_size,
							'format_changed' => true,
							'meta'           => [ 'mode' => 'convert', 'target' => $target ],
						]
					);
				}
				@unlink( $temp ); // phpcs:ignore
			}
			// Si la conversión falla, caemos a compresión PNG.
		}

		// Modo 2: compresión PNG conservando transparencia (sin cambio de formato).
		$strategy   = (string) ( $conversion['png_strategy'] ?? 'lossy' );
		$max_colors = (int) ( $conversion['png_max_colors'] ?? 256 );
		$max_width  = (int) ( $conversion['max_width'] ?? 0 );

		$temp = $this->temp_path( $file, 'png' );
		$ok   = $this->engine->compress_png( $file, $temp, $strategy, $max_colors, $max_width );

		if ( ! $ok || ! file_exists( $temp ) ) {
			return ProcessResult::fail( __( 'No se pudo comprimir el PNG (motor no disponible).', 'fasterfy' ) );
		}

		$output_size = (int) filesize( $temp );
		if ( $output_size >= $original_size && $output_size > 0 ) {
			@unlink( $temp ); // phpcs:ignore
			return ProcessResult::skip( __( 'El PNG ya estaba optimizado; se conserva el original.', 'fasterfy' ) );
		}

		return ProcessResult::ok(
			[
				'original_path'  => $file,
				'output_path'    => $temp,
				'source_mime'    => 'image/png',
				'output_mime'    => 'image/png',
				'original_size'  => $original_size,
				'output_size'    => $output_size,
				'format_changed' => false,
				'meta'           => [ 'mode' => 'compress', 'strategy' => $strategy ],
			]
		);
	}

	/**
	 * Resuelve el formato objetivo respetando capacidades.
	 *
	 * @param string $target Formato deseado.
	 * @return string
	 */
	private function resolve_target( string $target ): string {
		if ( 'auto' === $target ) {
			return ImageEngine::supports_format( 'avif' ) ? 'avif' : 'webp';
		}
		if ( 'avif' === $target && ! ImageEngine::supports_format( 'avif' ) ) {
			return 'webp';
		}
		return 'png' === $target ? 'webp' : $target;
	}

	/**
	 * Ruta temporal de salida.
	 *
	 * @param string $file Archivo origen.
	 * @param string $ext  Extensión de salida.
	 * @return string
	 */
	private function temp_path( string $file, string $ext ): string {
		$dir  = dirname( $file );
		$name = pathinfo( $file, PATHINFO_FILENAME );
		return trailingslashit( $dir ) . $name . '.fasterfy-tmp.' . $ext;
	}
}
