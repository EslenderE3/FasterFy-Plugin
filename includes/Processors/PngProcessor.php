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
		$max_width     = (int) ( $conversion['max_width'] ?? 0 );
		$strip         = (bool) ( $conversion['strip_metadata'] ?? true );

		$candidates = [];

		// Candidato A: compresión PNG conservando transparencia (mismo formato).
		$strategy   = (string) ( $conversion['png_strategy'] ?? 'lossy' );
		$max_colors = (int) ( $conversion['png_max_colors'] ?? 256 );
		$temp_png   = $this->temp_path( $file, 'png' );
		if ( $this->engine->compress_png( $file, $temp_png, $strategy, $max_colors, $max_width ) && file_exists( $temp_png ) ) {
			$candidates[] = [
				'path' => $temp_png,
				'size' => (int) filesize( $temp_png ),
				'mime' => 'image/png',
				'fmt'  => false,
				'mode' => 'compress',
			];
		}

		// Candidato B: conversión a WebP/AVIF (suele ahorrar mucho más, sobre todo
		// en servidores con GD donde la compresión PNG nativa es limitada).
		// Conserva el canal alfa (transparencia).
		$png_to_webp = ! array_key_exists( 'png_to_webp', $conversion ) || ! empty( $conversion['png_to_webp'] );
		if ( $png_to_webp ) {
			$target  = $this->resolve_target( (string) ( $conversion['target_format'] ?? 'webp' ) );
			$quality = 'avif' === $target
				? (int) ( $conversion['avif_quality'] ?? 60 )
				: (int) ( $conversion['webp_quality'] ?? 80 );
			$temp_x  = $this->temp_path( $file, $target );
			if ( $this->engine->convert( $file, $temp_x, $target, $quality, $max_width, $strip ) && file_exists( $temp_x ) ) {
				$candidates[] = [
					'path' => $temp_x,
					'size' => (int) filesize( $temp_x ),
					'mime' => 'image/' . $target,
					'fmt'  => true,
					'mode' => 'convert',
				];
			}
		}

		if ( empty( $candidates ) ) {
			return ProcessResult::fail( __( 'No se pudo comprimir el PNG (motor no disponible).', 'fasterfy' ) );
		}

		// Elige el resultado más pequeño y descarta el resto.
		usort( $candidates, static fn( array $a, array $b ): int => $a['size'] <=> $b['size'] );
		$best = $candidates[0];
		foreach ( $candidates as $c ) {
			if ( $c['path'] !== $best['path'] && file_exists( $c['path'] ) ) {
				@unlink( $c['path'] ); // phpcs:ignore
			}
		}

		if ( $best['size'] <= 0 || $best['size'] >= $original_size ) {
			@unlink( $best['path'] ); // phpcs:ignore
			return ProcessResult::skip( __( 'El PNG ya estaba optimizado; se conserva el original.', 'fasterfy' ) );
		}

		return ProcessResult::ok(
			[
				'original_path'  => $file,
				'output_path'    => $best['path'],
				'source_mime'    => 'image/png',
				'output_mime'    => $best['mime'],
				'original_size'  => $original_size,
				'output_size'    => $best['size'],
				'format_changed' => $best['fmt'],
				'meta'           => [ 'mode' => $best['mode'] ],
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
