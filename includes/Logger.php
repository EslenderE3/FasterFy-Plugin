<?php
/**
 * Logger persistente de FasterFy (tabla {prefix}fasterfy_log).
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy;

use FasterFy\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

/**
 * Registra eventos de procesamiento, IA y errores.
 */
final class Logger implements Bootable {

	private const LEVELS = [
		'debug'   => 10,
		'info'    => 20,
		'warning' => 30,
		'error'   => 40,
	];

	/**
	 * Ajustes.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registra los hooks: limpieza programada de logs antiguos.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'fasterfy_cleanup_logs', [ $this, 'purge_old' ] );
		if ( ! wp_next_scheduled( 'fasterfy_cleanup_logs' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'fasterfy_cleanup_logs' );
		}
	}

	/**
	 * Nombre completo de la tabla.
	 *
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'fasterfy_log';
	}

	/**
	 * Registra un evento si supera el nivel mínimo configurado.
	 *
	 * @param string               $level         Nivel (debug|info|warning|error).
	 * @param string               $message       Mensaje legible.
	 * @param string               $context       Contexto/categoría.
	 * @param int|null             $attachment_id ID de adjunto relacionado.
	 * @param array<string, mixed> $meta          Metadatos extra.
	 * @return void
	 */
	public function log( string $level, string $message, string $context = 'general', ?int $attachment_id = null, array $meta = [] ): void {
		$min = self::LEVELS[ (string) $this->settings->get( 'advanced.log_level', 'info' ) ] ?? 20;
		$cur = self::LEVELS[ $level ] ?? 20;
		if ( $cur < $min ) {
			return;
		}

		global $wpdb;
		$wpdb->insert(
			$this->table(),
			[
				'attachment_id' => $attachment_id,
				'level'         => $level,
				'context'       => substr( $context, 0, 60 ),
				'message'       => $message,
				'meta'          => empty( $meta ) ? null : wp_json_encode( $meta ),
				'created_at'    => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	public function debug( string $message, string $context = 'general', ?int $attachment_id = null, array $meta = [] ): void {
		$this->log( 'debug', $message, $context, $attachment_id, $meta );
	}

	public function info( string $message, string $context = 'general', ?int $attachment_id = null, array $meta = [] ): void {
		$this->log( 'info', $message, $context, $attachment_id, $meta );
	}

	public function warning( string $message, string $context = 'general', ?int $attachment_id = null, array $meta = [] ): void {
		$this->log( 'warning', $message, $context, $attachment_id, $meta );
	}

	public function error( string $message, string $context = 'general', ?int $attachment_id = null, array $meta = [] ): void {
		$this->log( 'error', $message, $context, $attachment_id, $meta );
	}

	/**
	 * Recupera entradas de log paginadas.
	 *
	 * @param array<string, mixed> $args Argumentos: level, context, attachment_id, per_page, page.
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function query( array $args = [] ): array {
		global $wpdb;

		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 50 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $args['level'] ) ) {
			$where[]  = 'level = %s';
			$params[] = (string) $args['level'];
		}
		if ( ! empty( $args['context'] ) ) {
			$where[]  = 'context = %s';
			$params[] = (string) $args['context'];
		}
		if ( ! empty( $args['attachment_id'] ) ) {
			$where[]  = 'attachment_id = %d';
			$params[] = (int) $args['attachment_id'];
		}

		$where_sql = implode( ' AND ', $where );
		$table     = $this->table();

		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) ); // phpcs:ignore

		$list_sql      = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$list_params   = array_merge( $params, [ $per_page, $offset ] );
		$rows          = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore

		$items = array_map(
			static function ( array $row ): array {
				$row['meta'] = $row['meta'] ? json_decode( (string) $row['meta'], true ) : null;
				return $row;
			},
			$rows ?: []
		);

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Borra todos los registros de log.
	 *
	 * @return void
	 */
	public function clear(): void {
		global $wpdb;
		$table = $this->table();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
	}

	/**
	 * Purga logs más antiguos que la retención configurada.
	 *
	 * @return void
	 */
	public function purge_old(): void {
		$days = (int) $this->settings->get( 'advanced.log_retention_days', 30 );
		if ( $days <= 0 ) {
			return;
		}
		global $wpdb;
		$table  = $this->table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore
	}
}
