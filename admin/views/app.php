<?php
/**
 * Contenedor de la SPA del panel de FasterFy.
 *
 * @package FasterFy
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap fasterfy-wrap">
	<div id="fasterfy-app" class="fasterfy-app" data-loading="true">
		<div class="ff-boot">
			<div class="ff-boot__logo">
				<svg width="36" height="36" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
					<path d="M24 2 44 13 44 35 24 46 4 35 4 13Z" fill="#33EE33"/>
					<path d="M26 10 L15 27 L22 27 L19 39 L33 21 L25 21 Z" fill="#1F1F1F"/>
					<path d="M33 20.5 L34.5 24.4 L38.4 25.9 L34.5 27.4 L33 31.3 L31.5 27.4 L27.6 25.9 L31.5 24.4 Z" fill="#ffffff"/>
				</svg>
				<span>Faster<i style="color:#33EE33;font-style:italic">Fy</i></span>
			</div>
			<div class="ff-boot__bar"><span></span></div>
			<p class="ff-boot__hint"><?php echo esc_html__( 'Cargando panel…', 'fasterfy' ); ?></p>
			<noscript>
				<p><?php echo esc_html__( 'FasterFy requiere JavaScript para mostrar el panel de control.', 'fasterfy' ); ?></p>
			</noscript>
		</div>
	</div>
</div>
