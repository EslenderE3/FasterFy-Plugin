<?php
/**
 * Contrato de un procesador de activos por tipo MIME.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Processors\Contracts;

use FasterFy\Processors\ProcessResult;

defined( 'ABSPATH' ) || exit;

/**
 * Implementado por cada procesador especializado (JPEG, PNG, SVG...).
 */
interface Processor {

	/**
	 * Indica si este procesador soporta el MIME dado.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public function supports( string $mime ): bool;

	/**
	 * Procesa el archivo y devuelve un resultado.
	 *
	 * @param string               $file    Ruta absoluta del archivo.
	 * @param array<string, mixed> $options Opciones de procesamiento.
	 * @return ProcessResult
	 */
	public function process( string $file, array $options = [] ): ProcessResult;
}
