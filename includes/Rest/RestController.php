<?php
/**
 * Controlador de la API REST de FasterFy. Expone todos los endpoints que
 * consume el panel de administración (SPA).
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Rest;

use FasterFy\Contracts\Bootable;
use FasterFy\Core;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registra y atiende las rutas REST bajo el namespace fasterfy/v1.
 */
final class RestController implements Bootable {

	public const NAMESPACE = 'fasterfy/v1';

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
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Comprobación de permisos: solo administradores con capacidad de gestión.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Registra todas las rutas REST.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$auth = [ $this, 'can_manage' ];

		$routes = [
			[ 'summary', WP_REST_Server::READABLE, 'get_summary' ],
			[ 'capabilities', WP_REST_Server::READABLE, 'get_capabilities' ],
			[ 'settings', WP_REST_Server::READABLE, 'get_settings' ],
			[ 'settings', WP_REST_Server::EDITABLE, 'update_settings' ],
			[ 'media', WP_REST_Server::READABLE, 'get_media' ],
			[ 'optimize', WP_REST_Server::CREATABLE, 'optimize_item' ],
			[ 'rollback', WP_REST_Server::CREATABLE, 'rollback_item' ],
			[ 'queue/status', WP_REST_Server::READABLE, 'queue_status' ],
			[ 'queue/start', WP_REST_Server::CREATABLE, 'queue_start' ],
			[ 'queue/run', WP_REST_Server::CREATABLE, 'queue_run' ],
			[ 'queue/pause', WP_REST_Server::CREATABLE, 'queue_pause' ],
			[ 'queue/resume', WP_REST_Server::CREATABLE, 'queue_resume' ],
			[ 'queue/cancel', WP_REST_Server::CREATABLE, 'queue_cancel' ],
			[ 'logs', WP_REST_Server::READABLE, 'get_logs' ],
			[ 'logs', WP_REST_Server::DELETABLE, 'clear_logs' ],
			[ 'ai/test', WP_REST_Server::CREATABLE, 'ai_test' ],
			[ 'ai/item', WP_REST_Server::CREATABLE, 'ai_item' ],
		];

		foreach ( $routes as [$path, $methods, $callback] ) {
			register_rest_route(
				self::NAMESPACE,
				'/' . $path,
				[
					'methods'             => $methods,
					'callback'            => [ $this, $callback ],
					'permission_callback' => $auth,
				]
			);
		}
	}

	/* ----------------------------------------------------------------
	 * Lectura
	 * ---------------------------------------------------------------- */

	/**
	 * Resumen de la biblioteca + estado de cola + estadísticas.
	 *
	 * @return WP_REST_Response
	 */
	public function get_summary(): WP_REST_Response {
		$summary = $this->core->scanner()->summary();
		$state   = $this->core->queue()->get_state();
		$quota   = (array) $this->core->settings()->get( 'quota', [] );

		return $this->ok(
			[
				'library'      => $summary,
				'queue'        => $state,
				'quota'        => $quota,
				'ai_enabled'   => $this->core->ai()->is_enabled(),
				'backups_size' => $this->core->backup()->total_backup_size(),
			]
		);
	}

	/**
	 * Capacidades del entorno de imagen (motores y formatos).
	 *
	 * @return WP_REST_Response
	 */
	public function get_capabilities(): WP_REST_Response {
		return $this->ok(
			[
				'image'         => $this->core->processor()->capabilities(),
				'queue_engine'  => $this->core->queue()->engine_label(),
				'supported'     => $this->core->processor()->supported_mimes(),
			]
		);
	}

	/**
	 * Devuelve la configuración (sin secretos).
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings(): WP_REST_Response {
		return $this->ok( [ 'settings' => $this->core->settings()->for_frontend() ] );
	}

	/**
	 * Listado paginado de medios.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public function get_media( WP_REST_Request $request ): WP_REST_Response {
		$listing = $this->core->scanner()->listing(
			[
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
				'status'   => (string) $request->get_param( 'status' ),
				'search'   => (string) $request->get_param( 'search' ),
				'orderby'  => (string) $request->get_param( 'orderby' ),
			]
		);
		return $this->ok( $listing );
	}

	/**
	 * Logs paginados.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public function get_logs( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->core->logger()->query(
			[
				'level'    => (string) $request->get_param( 'level' ),
				'context'  => (string) $request->get_param( 'context' ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
			]
		);
		return $this->ok( $result );
	}

	/* ----------------------------------------------------------------
	 * Escritura
	 * ---------------------------------------------------------------- */

	/**
	 * Actualiza la configuración.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ): WP_REST_Response {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			$input = (array) $request->get_param( 'settings' );
		}
		if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
			$input = $input['settings'];
		}

		$this->core->settings()->update( $input );
		return $this->ok( [ 'settings' => $this->core->settings()->for_frontend() ] );
	}

	/**
	 * Optimiza un único adjunto (modo manual desde la galería/panel).
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public function optimize_item( WP_REST_Request $request ): WP_REST_Response {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return $this->error( __( 'Adjunto inválido.', 'fasterfy' ), 400 );
		}

		$mode = (string) ( $request->get_param( 'mode' ) ?: 'both' );
		$this->core->backup()->backup( $id );
		$outcome = $this->core->queue()->process_item( $id, $mode );

		return $this->ok(
			[
				'id'      => $id,
				'outcome' => $outcome,
				'item'    => $this->item_snapshot( $id ),
			]
		);
	}

	/**
	 * Revierte (rollback) un adjunto a su estado original.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public function rollback_item( WP_REST_Request $request ): WP_REST_Response {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! $id ) {
			return $this->error( __( 'Adjunto inválido.', 'fasterfy' ), 400 );
		}
		$ok = $this->core->backup()->rollback( $id );
		if ( ! $ok ) {
			return $this->error( __( 'No se pudo revertir (sin respaldo disponible).', 'fasterfy' ), 409 );
		}
		return $this->ok( [ 'id' => $id, 'item' => $this->item_snapshot( $id ) ] );
	}

	/* ----------------------------------------------------------------
	 * Cola
	 * ---------------------------------------------------------------- */

	public function queue_status(): WP_REST_Response {
		return $this->ok( [ 'queue' => $this->core->queue()->get_state(), 'library' => $this->core->scanner()->summary() ] );
	}

	public function queue_start( WP_REST_Request $request ): WP_REST_Response {
		$mode  = (string) ( $request->get_param( 'mode' ) ?: 'both' );
		$state = $this->core->queue()->start( [ 'mode' => $mode ] );
		return $this->ok( [ 'queue' => $state ] );
	}

	/**
	 * Procesa un lote de forma síncrona (conducido por el navegador).
	 *
	 * @return WP_REST_Response
	 */
	public function queue_run(): WP_REST_Response {
		$state = $this->core->queue()->run_batch();
		return $this->ok( [ 'queue' => $state, 'library' => $this->core->scanner()->summary() ] );
	}

	public function queue_pause(): WP_REST_Response {
		return $this->ok( [ 'queue' => $this->core->queue()->pause() ] );
	}

	public function queue_resume(): WP_REST_Response {
		return $this->ok( [ 'queue' => $this->core->queue()->resume() ] );
	}

	public function queue_cancel(): WP_REST_Response {
		return $this->ok( [ 'queue' => $this->core->queue()->cancel() ] );
	}

	/* ----------------------------------------------------------------
	 * IA
	 * ---------------------------------------------------------------- */

	/**
	 * Prueba la conexión con el proveedor de IA.
	 *
	 * @return WP_REST_Response
	 */
	public function ai_test(): WP_REST_Response {
		return $this->ok( [ 'health' => $this->core->ai()->health() ] );
	}

	/**
	 * Aplica IA a un único adjunto.
	 *
	 * @param WP_REST_Request $request Petición.
	 * @return WP_REST_Response
	 */
	public function ai_item( WP_REST_Request $request ): WP_REST_Response {
		$id = absint( $request->get_param( 'id' ) );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return $this->error( __( 'Adjunto inválido.', 'fasterfy' ), 400 );
		}
		$result = $this->core->ai()->process_attachment( $id );
		return $this->ok( [ 'id' => $id, 'result' => $result, 'item' => $this->item_snapshot( $id ) ] );
	}

	/**
	 * Limpia los logs.
	 *
	 * @return WP_REST_Response
	 */
	public function clear_logs(): WP_REST_Response {
		$this->core->logger()->clear();
		return $this->ok( [ 'cleared' => true ] );
	}

	/* ----------------------------------------------------------------
	 * Utilidades
	 * ---------------------------------------------------------------- */

	/**
	 * Snapshot de un adjunto para la UI.
	 *
	 * @param int $id ID.
	 * @return array<string, mixed>
	 */
	private function item_snapshot( int $id ): array {
		return [
			'id'          => $id,
			'thumb'       => wp_get_attachment_image_url( $id, 'thumbnail' ),
			'mime'        => get_post_mime_type( $id ),
			'status'      => get_post_meta( $id, '_fasterfy_status', true ) ?: 'pending',
			'ai_status'   => get_post_meta( $id, '_fasterfy_ai_status', true ) ?: '',
			'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'saved_bytes' => (int) get_post_meta( $id, '_fasterfy_saved_bytes', true ),
			'format_to'   => get_post_meta( $id, '_fasterfy_format_to', true ),
			'has_backup'  => (bool) get_post_meta( $id, '_fasterfy_backup', true ),
		];
	}

	/**
	 * Respuesta de éxito.
	 *
	 * @param array<string, mixed> $data Datos.
	 * @return WP_REST_Response
	 */
	private function ok( array $data ): WP_REST_Response {
		return new WP_REST_Response( array_merge( [ 'ok' => true ], $data ), 200 );
	}

	/**
	 * Respuesta de error.
	 *
	 * @param string $message Mensaje.
	 * @param int    $status  Código HTTP.
	 * @return WP_REST_Response
	 */
	private function error( string $message, int $status = 400 ): WP_REST_Response {
		return new WP_REST_Response( [ 'ok' => false, 'message' => $message ], $status );
	}
}
