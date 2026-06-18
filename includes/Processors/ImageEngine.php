<?php
/**
 * Motor de imagen de bajo nivel: detección de capacidades, conversión
 * de formatos (WebP/AVIF) y compresión/cuantización, usando Imagick
 * cuando está disponible y GD como respaldo.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Processors;

defined( 'ABSPATH' ) || exit;

/**
 * Abstrae las operaciones de codificación de imágenes.
 */
final class ImageEngine {

	/**
	 * Cache de capacidades detectadas.
	 *
	 * @var array<string, bool>|null
	 */
	private static ?array $caps = null;

	/**
	 * Detecta las capacidades del entorno (motores y formatos).
	 *
	 * @return array<string, bool>
	 */
	public static function capabilities(): array {
		if ( null !== self::$caps ) {
			return self::$caps;
		}

		$caps = [
			'imagick'      => false,
			'gd'           => false,
			'webp'         => false,
			'avif'         => false,
			'png_quantize' => false,
		];

		if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
			$caps['imagick'] = true;
			try {
				$formats = array_map( 'strtoupper', \Imagick::queryFormats() );
				$caps['webp'] = $caps['webp'] || in_array( 'WEBP', $formats, true );
				$caps['avif'] = $caps['avif'] || in_array( 'AVIF', $formats, true );
				// Imagick puede cuantizar color de forma nativa.
				$caps['png_quantize'] = true;
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		if ( extension_loaded( 'gd' ) && function_exists( 'gd_info' ) ) {
			$caps['gd'] = true;
			$info       = gd_info();
			$caps['webp'] = $caps['webp'] || ! empty( $info['WebP Support'] );
			$caps['avif'] = $caps['avif'] || ! empty( $info['AVIF Support'] );
			// GD permite reducir a paleta (cuantización aproximada).
			$caps['png_quantize'] = $caps['png_quantize'] || function_exists( 'imagetruecolortopalette' );
		}

		self::$caps = $caps;
		return $caps;
	}

	/**
	 * Indica si un formato objetivo es soportado por el entorno.
	 *
	 * @param string $format 'webp' o 'avif'.
	 * @return bool
	 */
	public static function supports_format( string $format ): bool {
		$caps = self::capabilities();
		return ! empty( $caps[ $format ] );
	}

	/**
	 * Convierte una imagen a WebP/AVIF.
	 *
	 * @param string $source      Ruta del archivo origen.
	 * @param string $destination Ruta del archivo destino.
	 * @param string $format      'webp' o 'avif'.
	 * @param int    $quality     Calidad 1-100.
	 * @param int    $max_width   Ancho máximo (0 = sin límite).
	 * @param bool   $strip_meta  Eliminar metadatos.
	 * @return bool
	 */
	public function convert( string $source, string $destination, string $format, int $quality, int $max_width = 0, bool $strip_meta = true ): bool {
		$caps = self::capabilities();

		if ( $caps['imagick'] ) {
			$ok = $this->convert_imagick( $source, $destination, $format, $quality, $max_width, $strip_meta );
			if ( $ok ) {
				return true;
			}
		}

		if ( $caps['gd'] ) {
			return $this->convert_gd( $source, $destination, $format, $quality, $max_width );
		}

		return false;
	}

	/**
	 * Comprime un PNG (lossless o lossy mediante cuantización de color),
	 * conservando el canal alfa.
	 *
	 * @param string $source      Ruta origen.
	 * @param string $destination Ruta destino.
	 * @param string $strategy    'lossless' o 'lossy'.
	 * @param int    $max_colors  Colores objetivo (lossy).
	 * @param int    $max_width   Ancho máximo (0 = sin límite).
	 * @return bool
	 */
	public function compress_png( string $source, string $destination, string $strategy = 'lossy', int $max_colors = 256, int $max_width = 0 ): bool {
		$caps = self::capabilities();

		if ( $caps['imagick'] ) {
			$ok = $this->compress_png_imagick( $source, $destination, $strategy, $max_colors, $max_width );
			if ( $ok ) {
				return true;
			}
		}

		if ( $caps['gd'] ) {
			return $this->compress_png_gd( $source, $destination, $strategy, $max_colors, $max_width );
		}

		return false;
	}

	/* ----------------------------------------------------------------
	 * Imagick
	 * ---------------------------------------------------------------- */

	/**
	 * Conversión mediante Imagick.
	 */
	private function convert_imagick( string $source, string $destination, string $format, int $quality, int $max_width, bool $strip_meta ): bool {
		try {
			$img = new \Imagick( $source );

			// Aplanar GIF/animaciones tomando el primer frame coherente.
			if ( $img->getNumberImages() > 1 ) {
				$img = $img->coalesceImages();
			}

			if ( $strip_meta ) {
				$profiles = $img->getImageProfiles( 'icc', true );
				$img->stripImage();
				// Reinyecta perfil ICC para conservar fidelidad de color.
				if ( ! empty( $profiles['icc'] ) ) {
					$img->profileImage( 'icc', $profiles['icc'] );
				}
			}

			$this->maybe_resize_imagick( $img, $max_width );

			$img->setImageFormat( $format );
			$img->setImageCompressionQuality( $quality );

			if ( 'webp' === $format ) {
				$img->setOption( 'webp:method', '6' );
				$img->setOption( 'webp:low-memory', 'true' );
			} elseif ( 'avif' === $format ) {
				$img->setOption( 'heic:speed', '4' );
			}

			$written = $img->writeImage( $destination );
			$img->clear();
			$img->destroy();
			return (bool) $written && file_exists( $destination );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Compresión PNG mediante Imagick.
	 */
	private function compress_png_imagick( string $source, string $destination, string $strategy, int $max_colors, int $max_width ): bool {
		try {
			$img = new \Imagick( $source );
			$img->setImageFormat( 'png' );

			$this->maybe_resize_imagick( $img, $max_width );

			if ( 'lossy' === $strategy ) {
				$colors = max( 2, min( 256, $max_colors ) );
				// Cuantización conservando transparencia.
				$img->quantizeImage( $colors, \Imagick::COLORSPACE_SRGB, 0, false, false );
				$img->setImageType( \Imagick::IMGTYPE_PALETTEMATTE );
			}

			// Compresión PNG máxima sin pérdida del canal alfa.
			$img->setImageCompression( \Imagick::COMPRESSION_ZIP );
			$img->setImageCompressionQuality( 95 ); // PNG: nivel/filtro, no pérdida real.
			$img->setOption( 'png:compression-level', '9' );

			$written = $img->writeImage( $destination );
			$img->clear();
			$img->destroy();
			return (bool) $written && file_exists( $destination );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Redimensiona con Imagick si excede el ancho máximo.
	 */
	private function maybe_resize_imagick( \Imagick $img, int $max_width ): void {
		if ( $max_width <= 0 ) {
			return;
		}
		$w = $img->getImageWidth();
		if ( $w > $max_width ) {
			$img->resizeImage( $max_width, 0, \Imagick::FILTER_LANCZOS, 1 );
		}
	}

	/* ----------------------------------------------------------------
	 * GD (respaldo)
	 * ---------------------------------------------------------------- */

	/**
	 * Conversión mediante GD.
	 */
	private function convert_gd( string $source, string $destination, string $format, int $quality, int $max_width ): bool {
		$img = $this->gd_load( $source );
		if ( ! $img ) {
			return false;
		}

		$img = $this->gd_maybe_resize( $img, $max_width );

		imagealphablending( $img, false );
		imagesavealpha( $img, true );

		$ok = false;
		if ( 'webp' === $format && function_exists( 'imagewebp' ) ) {
			$ok = imagewebp( $img, $destination, $quality );
		} elseif ( 'avif' === $format && function_exists( 'imageavif' ) ) {
			$ok = imageavif( $img, $destination, $quality );
		}

		imagedestroy( $img );
		return $ok && file_exists( $destination );
	}

	/**
	 * Compresión PNG mediante GD.
	 */
	private function compress_png_gd( string $source, string $destination, string $strategy, int $max_colors, int $max_width ): bool {
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return false;
		}
		$img = imagecreatefrompng( $source );
		if ( ! $img ) {
			return false;
		}

		$img = $this->gd_maybe_resize( $img, $max_width );

		imagealphablending( $img, false );
		imagesavealpha( $img, true );

		if ( 'lossy' === $strategy && function_exists( 'imagetruecolortopalette' ) ) {
			$colors = max( 2, min( 256, $max_colors ) );
			imagetruecolortopalette( $img, true, $colors );
		}

		// Nivel de compresión PNG máximo (0-9).
		$ok = imagepng( $img, $destination, 9 );
		imagedestroy( $img );
		return $ok && file_exists( $destination );
	}

	/**
	 * Carga una imagen con GD según su tipo.
	 *
	 * @param string $source Ruta.
	 * @return \GdImage|false
	 */
	private function gd_load( string $source ) {
		$info = @getimagesize( $source ); // phpcs:ignore
		if ( ! $info ) {
			return false;
		}
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				return function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $source ) : false; // phpcs:ignore
			case IMAGETYPE_PNG:
				return function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $source ) : false; // phpcs:ignore
			case IMAGETYPE_GIF:
				return function_exists( 'imagecreatefromgif' ) ? @imagecreatefromgif( $source ) : false; // phpcs:ignore
			case IMAGETYPE_WEBP:
				return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $source ) : false; // phpcs:ignore
			default:
				return false;
		}
	}

	/**
	 * Redimensiona un recurso GD si excede el ancho máximo.
	 *
	 * @param \GdImage $img       Recurso.
	 * @param int      $max_width Ancho máximo.
	 * @return \GdImage
	 */
	private function gd_maybe_resize( $img, int $max_width ) {
		if ( $max_width <= 0 ) {
			return $img;
		}
		$w = imagesx( $img );
		$h = imagesy( $img );
		if ( $w <= $max_width ) {
			return $img;
		}
		$new_w  = $max_width;
		$new_h  = (int) round( $h * ( $max_width / $w ) );
		$resized = imagecreatetruecolor( $new_w, $new_h );
		imagealphablending( $resized, false );
		imagesavealpha( $resized, true );
		imagecopyresampled( $resized, $img, 0, 0, 0, 0, $new_w, $new_h, $w, $h );
		imagedestroy( $img );
		return $resized;
	}
}
