<?php
/**
 * Detección de eventos de subida: optimiza automáticamente (y opcionalmente
 * aplica IA) a los activos nuevos cargados en la biblioteca de medios,
 * de forma asíncrona para no bloquear la carga del administrador.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Media;

use FasterFy\Contracts\Bootable;
use FasterFy\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Escucha la creación de adjuntos y los encola para procesamiento.
 */
final class UploadInterceptor implements Bootable {

	/**
	 * Núcleo.
	 *
	 * @var Core
	 */
	private Core $core;

	/**
	 * Constructor.
	 *
	 * @param Core $core Núcleo.
	 */
	public function __construct( Core $core ) {
		$this->core = $core;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		// Se dispara tras generar los metadatos del adjunto recién subido.
		add_action( 'add_attachment', [ $this, 'on_new_attachment' ], 20 );
	}

	/**
	 * Encola el procesamiento del adjunto nuevo según la automatización.
	 *
	 * @param int $attachment_id ID.
	 * @return void
	 */
	public function on_new_attachment( int $attachment_id ): void {
		$settings = $this->core->settings();

		$optimize = (bool) $settings->get( 'automation.optimize_on_upload', true );
		$ai       = (bool) $settings->get( 'automation.ai_on_upload', false ) && $this->core->ai()->is_enabled();

		if ( ! $optimize && ! $ai ) {
			return;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! $this->core->processor()->supports_mime( $mime ) ) {
			return;
		}
		if ( $this->core->scanner()->is_excluded( $attachment_id ) ) {
			return;
		}

		$mode = $optimize && $ai ? 'both' : ( $optimize ? 'optimize' : 'ai' );

		// Procesamiento asíncrono para no penalizar la subida.
		$this->core->queue()->schedule_single( $attachment_id, $mode );
	}
}
