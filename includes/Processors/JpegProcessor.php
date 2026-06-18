<?php
/**
 * Procesador JPG/JPEG: transcodificación a formato de próxima generación
 * (WebP/AVIF) con compresión inteligente con pérdida.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Processors;

use FasterFy\Processors\Contracts\Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Convierte JPEG a WebP o AVIF.
 */
final class JpegProcessor implements Processor {

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
		return in_array( $mime, [ 'image/jpeg', 'image/jpg', 'image/pjpeg' ], true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function process( string $file, array $options = [] ): ProcessResult {
		if ( ! is_readable( $file ) ) {
			return ProcessResult::fail( __( 'El archivo JPEG no es legible.', 'fasterfy' ) );
		}

		$conversion = $options['conversion'] ?? [];
		$target     = $this->resolve_target( (string) ( $conversion['target_format'] ?? 'webp' ) );

		$quality   = 'avif' === $target
			? (int) ( $conversion['avif_quality'] ?? 60 )
			: (int) ( $conversion['webp_quality'] ?? 80 );
		$max_width  = (int) ( $conversion['max_width'] ?? 0 );
		$strip_meta = (bool) ( $conversion['strip_metadata'] ?? true );

		$original_size = (int) filesize( $file );

		$temp = $this->temp_path( $file, $target );
		$ok   = $this->engine->convert( $file, $temp, $target, $quality, $max_width, $strip_meta );

		if ( ! $ok || ! file_exists( $temp ) ) {
			return ProcessResult::fail(
				sprintf(
					/* translators: %s = formato */
					__( 'No se pudo convertir el JPEG a %s (motor no disponible).', 'fasterfy' ),
					strtoupper( $target )
				)
			);
		}

		$output_size = (int) filesize( $temp );

		// Si la conversión no aporta ahorro real, descartamos el resultado.
		if ( $output_size >= $original_size && $output_size > 0 ) {
			@unlink( $temp ); // phpcs:ignore
			return ProcessResult::skip( __( 'La conversión no reduce el tamaño; se conserva el original.', 'fasterfy' ) );
		}

		return ProcessResult::ok(
			[
				'original_path'  => $file,
				'output_path'    => $temp,
				'source_mime'    => 'image/jpeg',
				'output_mime'    => 'image/' . $target,
				'original_size'  => $original_size,
				'output_size'    => $output_size,
				'format_changed' => true,
				'meta'           => [ 'target' => $target, 'quality' => $quality ],
			]
		);
	}

	/**
	 * Resuelve el formato objetivo respetando las capacidades del entorno.
	 *
	 * @param string $target 'webp', 'avif' o 'auto'.
	 * @return string
	 */
	private function resolve_target( string $target ): string {
		if ( 'auto' === $target ) {
			return ImageEngine::supports_format( 'avif' ) ? 'avif' : 'webp';
		}
		if ( 'avif' === $target && ! ImageEngine::supports_format( 'avif' ) ) {
			return 'webp';
		}
		return $target;
	}

	/**
	 * Ruta temporal de salida con la nueva extensión.
	 *
	 * @param string $file   Archivo original.
	 * @param string $target Formato objetivo.
	 * @return string
	 */
	private function temp_path( string $file, string $target ): string {
		$dir  = dirname( $file );
		$name = pathinfo( $file, PATHINFO_FILENAME );
		return trailingslashit( $dir ) . $name . '.fasterfy-tmp.' . $target;
	}
}
