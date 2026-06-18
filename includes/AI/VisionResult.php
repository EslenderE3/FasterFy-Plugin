<?php
/**
 * Resultado del análisis de visión de una imagen.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Value object con la salida del modelo multimodal.
 */
final class VisionResult {

	public bool $success = false;
	public string $message = '';

	/** Texto alternativo (alt) denotativo y fáctico. */
	public string $alt = '';

	/** Título / leyenda sugeridos. */
	public string $title = '';

	/** Términos clave para el renombrado semántico. */
	public string $keywords = '';

	/** Datos crudos / depuración. */
	public array $raw = [];

	/**
	 * Crea un resultado de éxito.
	 *
	 * @param array<string, mixed> $props Propiedades.
	 * @return self
	 */
	public static function ok( array $props = [] ): self {
		$r          = new self();
		$r->success = true;
		foreach ( $props as $k => $v ) {
			if ( property_exists( $r, $k ) ) {
				$r->{$k} = $v;
			}
		}
		return $r;
	}

	/**
	 * Crea un resultado de error.
	 *
	 * @param string $message Mensaje.
	 * @return self
	 */
	public static function fail( string $message ): self {
		$r          = new self();
		$r->success = false;
		$r->message = $message;
		return $r;
	}
}
