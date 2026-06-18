<?php
/**
 * Contrato para servicios que registran hooks de WordPress.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Implementado por todo servicio que necesita engancharse a WordPress.
 */
interface Bootable {

	/**
	 * Registra los hooks (actions/filters) del servicio.
	 *
	 * @return void
	 */
	public function register_hooks(): void;
}
