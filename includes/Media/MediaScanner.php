<?php
/**
 * Escáner e indexador de la biblioteca histórica de medios.
 * Cataloga el estado de los adjuntos (wp_posts.post_type = 'attachment')
 * y filtra según exclusiones, MIME soportados y estado de optimización.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Media;

use FasterFy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Realiza el escaneo de la base de datos de adjuntos.
 */
final class MediaScanner {

	/**
	 * MIME types que FasterFy puede procesar.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_MIMES = [
		'image/jpeg',
		'image/png',
		'image/svg+xml',
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
	 * MIME types soportados.
	 *
	 * @return string[]
	 */
	public function supported_mimes(): array {
		return self::SUPPORTED_MIMES;
	}

	/**
	 * Devuelve un resumen del estado de la biblioteca.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		global $wpdb;

		$mime_in   = $this->mime_in_clause();
		$total_sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ({$mime_in})";
		$total     = (int) $wpdb->get_var( $total_sql ); // phpcs:ignore

		// Optimizados = tienen postmeta _fasterfy_status = 'optimized'.
		$optimized_sql = "
			SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_fasterfy_status' AND m.meta_value = 'optimized'
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN ({$mime_in})
		";
		$optimized = (int) $wpdb->get_var( $optimized_sql ); // phpcs:ignore

		$pending = max( 0, $total - $optimized );

		// Desglose por tipo.
		$by_type_rows = $wpdb->get_results(
			"SELECT post_mime_type AS mime, COUNT(*) AS n
			 FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_mime_type IN ({$mime_in})
			 GROUP BY post_mime_type",
			ARRAY_A
		); // phpcs:ignore

		$by_type = [];
		foreach ( $by_type_rows ?: [] as $row ) {
			$by_type[ (string) $row['mime'] ] = (int) $row['n'];
		}

		$stats = get_option( 'fasterfy_stats', [] );
		$stats = is_array( $stats ) ? $stats : [];

		return [
			'total'           => $total,
			'optimized'       => $optimized,
			'pending'         => $pending,
			'by_type'         => $by_type,
			'total_saved'     => (int) ( $stats['total_saved'] ?? 0 ),
			'total_optimized' => (int) ( $stats['total_optimized'] ?? 0 ),
			'last_run'        => $stats['last_run'] ?? null,
		];
	}

	/**
	 * Obtiene IDs de adjuntos pendientes de optimizar (respetando exclusiones).
	 *
	 * @param int $limit  Máximo de IDs a devolver.
	 * @param int $offset Desplazamiento.
	 * @return int[]
	 */
	public function pending_ids( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$mime_in = $this->mime_in_clause();
		$limit   = max( 1, $limit );
		$offset  = max( 0, $offset );

		$sql = $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m
			   ON m.post_id = p.ID AND m.meta_key = '_fasterfy_status'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type IN ({$mime_in})
			   AND ( m.meta_value IS NULL OR m.meta_value <> 'optimized' )
			 ORDER BY p.ID ASC
			 LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		$ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore

		// Filtra por exclusiones definidas por el usuario.
		return array_values( array_filter( $ids, fn( int $id ): bool => ! $this->is_excluded( $id ) ) );
	}

	/**
	 * Cuenta los adjuntos pendientes (sin exclusiones, para estimación).
	 *
	 * @return int
	 */
	public function count_pending(): int {
		$summary = $this->summary();
		return (int) $summary['pending'];
	}

	/**
	 * Determina si un adjunto está excluido por la configuración o su estado.
	 *
	 * @param int $attachment_id ID.
	 * @return bool
	 */
	public function is_excluded( int $attachment_id ): bool {
		$exclusions = (array) $this->settings->get( 'exclusions', [] );

		// Exclusión explícita por ID.
		$ids = array_map( 'intval', (array) ( $exclusions['attachment_ids'] ?? [] ) );
		if ( in_array( $attachment_id, $ids, true ) ) {
			return true;
		}

		// Exclusión por MIME.
		$mime          = (string) get_post_mime_type( $attachment_id );
		$excluded_mime = (array) ( $exclusions['mime_types'] ?? [] );
		if ( in_array( $mime, $excluded_mime, true ) ) {
			return true;
		}
		if ( ! in_array( $mime, self::SUPPORTED_MIMES, true ) ) {
			return true;
		}

		// Exclusión por directorio (relativo a uploads).
		$dirs = array_filter( (array) ( $exclusions['directories'] ?? [] ) );
		if ( $dirs ) {
			$relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			foreach ( $dirs as $dir ) {
				$dir = trim( (string) $dir, '/' );
				if ( '' !== $dir && str_starts_with( ltrim( $relative, '/' ), $dir ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Devuelve filas detalladas de adjuntos para la UI (paginadas).
	 *
	 * @param array<string, mixed> $args page, per_page, status (all|pending|optimized).
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function listing( array $args = [] ): array {
		global $wpdb;

		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;
		$status   = (string) ( $args['status'] ?? 'all' );
		$mime_in  = $this->mime_in_clause();

		$status_join  = "LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_fasterfy_status'";
		$status_where = '';
		if ( 'pending' === $status ) {
			$status_where = "AND ( m.meta_value IS NULL OR m.meta_value <> 'optimized' )";
		} elseif ( 'optimized' === $status ) {
			$status_where = "AND m.meta_value = 'optimized'";
		}

		$count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p {$status_join}
			WHERE p.post_type = 'attachment' AND p.post_mime_type IN ({$mime_in}) {$status_where}";
		$total     = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore

		$list_sql = $wpdb->prepare(
			"SELECT DISTINCT p.ID, p.post_title, p.post_mime_type
			 FROM {$wpdb->posts} p {$status_join}
			 WHERE p.post_type = 'attachment' AND p.post_mime_type IN ({$mime_in}) {$status_where}
			 ORDER BY p.ID DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);
		$rows     = $wpdb->get_results( $list_sql, ARRAY_A ); // phpcs:ignore

		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$id      = (int) $row['ID'];
			$items[] = [
				'id'             => $id,
				'title'          => $row['post_title'],
				'mime'           => $row['post_mime_type'],
				'thumb'          => wp_get_attachment_image_url( $id, 'thumbnail' ),
				'status'         => get_post_meta( $id, '_fasterfy_status', true ) ?: 'pending',
				'alt'            => get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'saved_bytes'    => (int) get_post_meta( $id, '_fasterfy_saved_bytes', true ),
				'format_to'      => get_post_meta( $id, '_fasterfy_format_to', true ),
				'has_backup'     => (bool) get_post_meta( $id, '_fasterfy_backup', true ),
				'excluded'       => $this->is_excluded( $id ),
			];
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Construye la cláusula IN(...) con los MIME soportados ya escapados.
	 *
	 * @return string
	 */
	private function mime_in_clause(): string {
		$quoted = array_map(
			static fn( string $m ): string => "'" . esc_sql( $m ) . "'",
			self::SUPPORTED_MIMES
		);
		// Incluye variantes comunes de jpeg.
		$quoted[] = "'image/jpg'";
		return implode( ',', $quoted );
	}
}
