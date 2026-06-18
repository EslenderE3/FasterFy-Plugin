<?php
/**
 * Contrato de un proveedor de IA multimodal.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\AI\Contracts;

use FasterFy\AI\VisionResult;

defined( 'ABSPATH' ) || exit;

/**
 * Implementado por cada proveedor (OpenAI-compatible, FasterFy Cloud...).
 */
interface AIProvider {

	/**
	 * Identificador del proveedor.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Comprueba la conectividad/credenciales con el proveedor.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function health(): array;

	/**
	 * Analiza una imagen y devuelve descripción semántica + keywords.
	 *
	 * @param string               $image_path Ruta absoluta de la imagen.
	 * @param array<string, mixed> $context    Contexto (idioma, longitud, taxonomías...).
	 * @return VisionResult
	 */
	public function analyze( string $image_path, array $context = [] ): VisionResult;
}
