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
				<span class="ff-boot__spark"></span>
				FasterFy
			</div>
			<div class="ff-boot__bar"><span></span></div>
			<p class="ff-boot__hint"><?php echo esc_html__( 'Cargando panel…', 'fasterfy' ); ?></p>
			<noscript>
				<p><?php echo esc_html__( 'FasterFy requiere JavaScript para mostrar el panel de control.', 'fasterfy' ); ?></p>
			</noscript>
		</div>
	</div>
</div>
