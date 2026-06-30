<?php
/**
 * Plugin Name:       FasterFy — AI Media Optimizer
 * Plugin URI:        https://fasterfy.app
 * Description:       Optimización masiva, inteligente y retroactiva de activos visuales: conversión a WebP/AVIF, compresión PNG, sanitización SVG, reconocimiento de imagen e inyección de Alt Text con IA, colas asíncronas no bloqueantes y arquitectura no destructiva (rollback).
 * Version:           1.0.14
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            FasterFy
 * Author URI:        https://fasterfy.app
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fasterfy
 * Domain Path:       /languages
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy;

// Evita el acceso directo.
defined( 'ABSPATH' ) || exit;

/*
|--------------------------------------------------------------------------
| Constantes del plugin
|--------------------------------------------------------------------------
*/
define( 'FASTERFY_VERSION', '1.0.14' );
define( 'FASTERFY_FILE', __FILE__ );
define( 'FASTERFY_BASENAME', plugin_basename( __FILE__ ) );
define( 'FASTERFY_PATH', plugin_dir_path( __FILE__ ) );
define( 'FASTERFY_URL', plugin_dir_url( __FILE__ ) );
define( 'FASTERFY_INCLUDES', FASTERFY_PATH . 'includes/' );
define( 'FASTERFY_MIN_PHP', '8.0' );

/*
|--------------------------------------------------------------------------
| Autoloader propio (PSR-4 ligero, sin Composer)
|--------------------------------------------------------------------------
| Mapea el namespace raíz "FasterFy\" al directorio /includes.
| Ejemplo: FasterFy\Processors\ImageProcessor => includes/Processors/ImageProcessor.php
*/
spl_autoload_register(
	static function ( string $class ): void {
		$prefix   = 'FasterFy\\';
		$base_dir = FASTERFY_INCLUDES;

		$len = strlen( $prefix );
		if ( 0 !== strncmp( $prefix, $class, $len ) ) {
			return;
		}

		$relative = substr( $class, $len );
		$relative = str_replace( '\\', '/', $relative );
		$file     = $base_dir . $relative . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// Carga opcional del autoloader de Composer si el usuario ejecutó "composer install".
if ( is_readable( FASTERFY_PATH . 'vendor/autoload.php' ) ) {
	require_once FASTERFY_PATH . 'vendor/autoload.php';
}

/*
|--------------------------------------------------------------------------
| Comprobación de versión de PHP
|--------------------------------------------------------------------------
*/
if ( version_compare( PHP_VERSION, FASTERFY_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %1$s = PHP requerido, %2$s = PHP actual */
						__( 'FasterFy requiere PHP %1$s o superior. Tu servidor ejecuta PHP %2$s.', 'fasterfy' ),
						FASTERFY_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

/*
|--------------------------------------------------------------------------
| Hooks de ciclo de vida
|--------------------------------------------------------------------------
*/
register_activation_hook( __FILE__, [ Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ Deactivator::class, 'deactivate' ] );

/*
|--------------------------------------------------------------------------
| Arranque
|--------------------------------------------------------------------------
*/
add_action(
	'plugins_loaded',
	static function (): void {
		Core::instance()->boot();
	},
	5
);

/**
 * Acceso global rápido a la instancia del núcleo.
 *
 * @return Core
 */
function fasterfy(): Core {
	return Core::instance();
}
