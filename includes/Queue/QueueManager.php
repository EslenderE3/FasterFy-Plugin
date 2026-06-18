<?php
/**
 * Gestor de colas asíncronas no bloqueantes. Procesa la biblioteca histórica
 * en lotes controlados en segundo plano usando Action Scheduler cuando está
 * disponible, con respaldo en WP-Cron. Aplica throttling y control de cuotas.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Queue;

use FasterFy\AI\AIManager;
use FasterFy\Contracts\Bootable;
use FasterFy\Logger;
use FasterFy\Media\BackupManager;
use FasterFy\Media\MediaScanner;
use FasterFy\Processors\ProcessorFactory;
use FasterFy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Orquesta el procesamiento masivo por lotes.
 */
final class QueueManager implements Bootable {

	public const BATCH_HOOK   = 'fasterfy_process_batch';
	public const ITEM_HOOK    = 'fasterfy_process_item';
	public const STATE_OPTION = 'fasterfy_queue_state';
	public const AS_GROUP     = 'fasterfy';

	private Settings $settings;
	private Logger $logger;
	private MediaScanner $scanner;
	private ProcessorFactory $processor;
	private BackupManager $backup;
	private AIManager $ai;

	/**
	 * Constructor.
	 *
	 * @param Settings         $settings  Ajustes.
	 * @param Logger           $logger    Logger.
	 * @param MediaScanner     $scanner   Escáner.
	 * @param ProcessorFactory $processor Procesador.
	 * @param BackupManager    $backup    Respaldos.
	 * @param AIManager        $ai        IA.
	 */
	public function __construct(
		Settings $settings,
		Logger $logger,
		MediaScanner $scanner,
		ProcessorFactory $processor,
		BackupManager $backup,
		AIManager $ai
	) {
		$this->settings  = $settings;
		$this->logger    = $logger;
		$this->scanner   = $scanner;
		$this->processor = $processor;
		$this->backup    = $backup;
		$this->ai        = $ai;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( self::BATCH_HOOK, [ $this, 'process_batch' ] );
		// Procesamiento asíncrono de un único adjunto (subidas nuevas).
		add_action( self::ITEM_HOOK, [ $this, 'process_item_async' ], 10, 2 );
	}

	/**
	 * Programa el procesamiento asíncrono de un único adjunto.
	 *
	 * @param int    $attachment_id ID.
	 * @param string $mode          optimize|ai|both.
	 * @return void
	 */
	public function schedule_single( int $attachment_id, string $mode = 'both' ): void {
		if ( $this->use_action_scheduler() ) {
			as_enqueue_async_action( self::ITEM_HOOK, [ $attachment_id, $mode ], self::AS_GROUP );
			return;
		}
		wp_schedule_single_event( time() + 5, self::ITEM_HOOK, [ $attachment_id, $mode ] );
	}

	/**
	 * Callback asíncrono para procesar un único adjunto.
	 *
	 * @param int    $attachment_id ID.
	 * @param string $mode          Modo.
	 * @return void
	 */
	public function process_item_async( $attachment_id, $mode = 'both' ): void {
		$this->process_item( (int) $attachment_id, (string) $mode );
	}

	/* ----------------------------------------------------------------
	 * Estado de la cola
	 * ---------------------------------------------------------------- */

	/**
	 * Estado actual de la cola.
	 *
	 * @return array<string, mixed>
	 */
	public function get_state(): array {
		$default = [
			'status'     => 'idle', // idle|running|paused|completed|exhausted.
			'mode'       => 'optimize', // optimize|ai|both.
			'total'      => 0,
			'processed'  => 0,
			'succeeded'  => 0,
			'failed'     => 0,
			'skipped'    => 0,
			'overrides'  => [],
			'started_at' => null,
			'updated_at' => null,
			'engine'     => $this->engine_label(),
		];
		$state = get_option( self::STATE_OPTION, [] );
		return is_array( $state ) ? array_merge( $default, $state ) : $default;
	}

	/**
	 * Persiste cambios parciales del estado.
	 *
	 * @param array<string, mixed> $patch Cambios.
	 * @return array<string, mixed> Estado resultante.
	 */
	private function set_state( array $patch ): array {
		$state               = array_merge( $this->get_state(), $patch );
		$state['updated_at'] = current_time( 'mysql', true );
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}

	/**
	 * Indica si la cola está activa.
	 *
	 * @return bool
	 */
	public function is_running(): bool {
		return 'running' === $this->get_state()['status'];
	}

	/* ----------------------------------------------------------------
	 * Control de la cola
	 * ---------------------------------------------------------------- */

	/**
	 * Inicia el procesamiento masivo de la biblioteca histórica.
	 *
	 * @param array<string, mixed> $args mode (optimize|ai|both), overrides.
	 * @return array<string, mixed> Estado.
	 */
	public function start( array $args = [] ): array {
		$mode      = in_array( $args['mode'] ?? 'optimize', [ 'optimize', 'ai', 'both' ], true ) ? $args['mode'] : 'optimize';
		$overrides = (array) ( $args['overrides'] ?? [] );

		$total = $this->scanner->count_pending();

		$state = $this->set_state(
			[
				'status'     => 'running',
				'mode'       => $mode,
				'total'      => $total,
				'processed'  => 0,
				'succeeded'  => 0,
				'failed'     => 0,
				'skipped'    => 0,
				'overrides'  => $overrides,
				'started_at' => current_time( 'mysql', true ),
			]
		);

		$this->logger->info(
			sprintf(
				/* translators: 1: total, 2: modo */
				__( 'Cola iniciada: %1$d activos pendientes (modo: %2$s).', 'fasterfy' ),
				$total,
				$mode
			),
			'queue'
		);

		$this->enqueue_next_batch( 0 );
		return $state;
	}

	/**
	 * Pausa la cola.
	 *
	 * @return array<string, mixed>
	 */
	public function pause(): array {
		$this->cancel_scheduled();
		return $this->set_state( [ 'status' => 'paused' ] );
	}

	/**
	 * Reanuda la cola pausada.
	 *
	 * @return array<string, mixed>
	 */
	public function resume(): array {
		$state = $this->set_state( [ 'status' => 'running' ] );
		$this->enqueue_next_batch( 0 );
		return $state;
	}

	/**
	 * Cancela la cola y limpia el estado.
	 *
	 * @return array<string, mixed>
	 */
	public function cancel(): array {
		$this->cancel_scheduled();
		return $this->set_state(
			[
				'status' => 'idle',
				'total'  => 0,
			]
		);
	}

	/* ----------------------------------------------------------------
	 * Programación de lotes (Action Scheduler / WP-Cron)
	 * ---------------------------------------------------------------- */

	/**
	 * Indica si se debe usar Action Scheduler.
	 *
	 * @return bool
	 */
	private function use_action_scheduler(): bool {
		return (bool) $this->settings->get( 'advanced.prefer_action_scheduler', true )
			&& function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Etiqueta del motor de cola en uso.
	 *
	 * @return string
	 */
	public function engine_label(): string {
		return $this->use_action_scheduler() ? 'action_scheduler' : 'wp_cron';
	}

	/**
	 * Encola el siguiente lote con un retraso (throttling).
	 *
	 * @param int $delay Segundos de espera.
	 * @return void
	 */
	private function enqueue_next_batch( int $delay = 0 ): void {
		if ( $this->use_action_scheduler() ) {
			if ( $delay > 0 ) {
				as_schedule_single_action( time() + $delay, self::BATCH_HOOK, [], self::AS_GROUP );
			} else {
				as_enqueue_async_action( self::BATCH_HOOK, [], self::AS_GROUP );
			}
			return;
		}

		// Respaldo: WP-Cron.
		if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time() + max( 1, $delay ), self::BATCH_HOOK );
		}
	}

	/**
	 * Cancela cualquier lote programado.
	 *
	 * @return void
	 */
	private function cancel_scheduled(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BATCH_HOOK, [], self::AS_GROUP );
		}
		$ts = wp_next_scheduled( self::BATCH_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::BATCH_HOOK );
			$ts = wp_next_scheduled( self::BATCH_HOOK );
		}
	}

	/* ----------------------------------------------------------------
	 * Worker
	 * ---------------------------------------------------------------- */

	/**
	 * Procesa un lote de adjuntos. Es el callback del hook de cola.
	 *
	 * @return void
	 */
	public function process_batch(): void {
		$state = $this->get_state();

		if ( 'running' !== $state['status'] ) {
			return;
		}

		// Control de cuota: pausa si se agotaron los créditos.
		if ( $this->is_quota_exhausted() ) {
			$this->set_state( [ 'status' => 'exhausted' ] );
			$this->logger->warning( __( 'Cola pausada: cuota de créditos agotada.', 'fasterfy' ), 'quota' );
			return;
		}

		$batch_size = (int) $this->settings->get( 'throttling.batch_size', 10 );
		$ids        = $this->scanner->pending_ids( $batch_size );

		if ( empty( $ids ) ) {
			$this->set_state( [ 'status' => 'completed' ] );
			$this->logger->info( __( 'Procesamiento de la biblioteca completado.', 'fasterfy' ), 'queue' );
			return;
		}

		$mode      = (string) $state['mode'];
		$overrides = (array) $state['overrides'];

		foreach ( $ids as $id ) {
			$outcome = $this->process_item( (int) $id, $mode, $overrides );

			$state['processed'] = (int) $state['processed'] + 1;
			if ( 'success' === $outcome ) {
				$state['succeeded'] = (int) $state['succeeded'] + 1;
			} elseif ( 'skipped' === $outcome ) {
				$state['skipped'] = (int) $state['skipped'] + 1;
			} else {
				$state['failed'] = (int) $state['failed'] + 1;
			}

			// Marca el adjunto como procesado aunque falle, para no reintentar en bucle.
			if ( 'fail' === $outcome ) {
				update_post_meta( (int) $id, '_fasterfy_status', 'error' );
			}
		}

		$this->set_state(
			[
				'processed' => $state['processed'],
				'succeeded' => $state['succeeded'],
				'failed'    => $state['failed'],
				'skipped'   => $state['skipped'],
			]
		);

		// ¿Quedan pendientes? Programa el siguiente lote con cooldown.
		if ( $this->scanner->count_pending() > 0 ) {
			$cooldown = (int) $this->settings->get( 'throttling.cooldown_seconds', 5 );
			$this->enqueue_next_batch( $cooldown );
		} else {
			$this->set_state( [ 'status' => 'completed' ] );
			$this->logger->info( __( 'Procesamiento de la biblioteca completado.', 'fasterfy' ), 'queue' );
		}
	}

	/**
	 * Procesa un único adjunto: respaldo → optimización → IA.
	 *
	 * @param int                  $attachment_id ID.
	 * @param string               $mode          optimize|ai|both.
	 * @param array<string, mixed> $overrides     Sobrescrituras de conversión.
	 * @return string success|skipped|fail
	 */
	public function process_item( int $attachment_id, string $mode = 'both', array $overrides = [] ): string {
		if ( $this->scanner->is_excluded( $attachment_id ) ) {
			return 'skipped';
		}

		$did_optimize = false;
		$did_ai       = false;

		try {
			// 1) Respaldo no destructivo antes de cualquier mutación.
			if ( in_array( $mode, [ 'optimize', 'both' ], true ) ) {
				$this->backup->backup( $attachment_id );

				$result = $this->processor->process_attachment( $attachment_id, $overrides );
				if ( $result->success && ! $result->skipped ) {
					$did_optimize = true;
				} elseif ( ! $result->success ) {
					$this->logger->error( $result->message, 'queue', $attachment_id );
				}
			}

			// 2) IA: reconocimiento + alt text (con moderación).
			if ( in_array( $mode, [ 'ai', 'both' ], true ) && $this->ai->is_enabled() ) {
				$ai_result = $this->ai->process_attachment( $attachment_id );
				if ( $ai_result['success'] ?? false ) {
					$did_ai = true;
					$this->consume_credit();
				}
			}
		} catch ( \Throwable $e ) {
			$this->logger->error(
				sprintf( 'Excepción procesando adjunto: %s', $e->getMessage() ),
				'queue',
				$attachment_id
			);
			return 'fail';
		}

		if ( $did_optimize || $did_ai ) {
			return 'success';
		}

		// Si nada falló pero tampoco hubo cambios, márcalo optimizado para no reprocesar.
		update_post_meta( $attachment_id, '_fasterfy_status', 'optimized' );
		return 'skipped';
	}

	/* ----------------------------------------------------------------
	 * Cuotas / créditos
	 * ---------------------------------------------------------------- */

	/**
	 * Indica si la cuota mensual está agotada.
	 *
	 * @return bool
	 */
	public function is_quota_exhausted(): bool {
		$monthly = (int) $this->settings->get( 'quota.monthly_credits', 0 );
		if ( $monthly <= 0 ) {
			return false; // 0 = ilimitado / autohospedado.
		}
		if ( ! $this->settings->get( 'quota.pause_on_exhausted', true ) ) {
			return false;
		}
		$used = (int) $this->settings->get( 'quota.used_credits', 0 );
		return $used >= $monthly;
	}

	/**
	 * Consume un crédito de la cuota mensual.
	 *
	 * @return void
	 */
	private function consume_credit(): void {
		$monthly = (int) $this->settings->get( 'quota.monthly_credits', 0 );
		if ( $monthly <= 0 ) {
			return;
		}
		$used = (int) $this->settings->get( 'quota.used_credits', 0 ) + 1;
		$this->settings->update( [ 'quota' => [ 'used_credits' => $used ] ] );
	}
}
