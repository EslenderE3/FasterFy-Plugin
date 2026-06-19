<?php
/**
 * Escáner e indexador de la biblioteca histórica de medios.
 *
 * Distingue dos conjuntos de tipos MIME:
 *  - DISPLAY_MIMES: lo que se MUESTRA en la biblioteca del plugin (incluye
 *    WebP/AVIF para que las imágenes ya convertidas sigan visibles y se les
 *    pueda aplicar IA o revertir).
 *  - OPTIMIZABLE_MIMES: lo que se puede OPTIMIZAR técnicamente (JPEG/PNG/SVG).
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
	 * MIME visibles en la biblioteca del plugin (incluye formatos ya optimizados).
	 *
	 * @var string[]
	 */
	private const DISPLAY_MIMES = [
		'image/jpeg',
		'image/png',
		'image/svg+xml',
		'image/webp',
		'image/avif',
	];

	/**
	 * MIME que FasterFy puede optimizar técnicamente.
	 *
	 * @var string[]
	 */
	private const OPTIMIZABLE_MIMES = [
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
	 * MIME visibles.
	 *
	 * @return string[]
	 */
	public function supported_mimes(): array {
		return self::DISPLAY_MIMES;
	}

	/**
	 * Devuelve un resumen del estado de la biblioteca.
	 *
	 * @return array<string, mixed>
	 */
	public function summary(): array {
		global $wpdb;

		$display_in = $this->display_in_clause();

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ({$display_in})"
		); // phpcs:ignore

		$optimized = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_fasterfy_status' AND m.meta_value = 'optimized'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type IN ({$display_in})"
		); // phpcs:ignore

		$by_type_rows = $wpdb->get_results(
			"SELECT post_mime_type AS mime, COUNT(*) AS n
			 FROM {$wpdb->posts}
			 WHERE post_type = 'attachment' AND post_mime_type IN ({$display_in})
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
			'pending'         => $this->count_pending(),
			'ai_pending'      => $this->count_ai_pending(),
			'by_type'         => $by_type,
			'total_saved'     => (int) ( $stats['total_saved'] ?? 0 ),
			'total_optimized' => (int) ( $stats['total_optimized'] ?? 0 ),
			'last_run'        => $stats['last_run'] ?? null,
		];
	}

	/**
	 * Obtiene IDs de adjuntos pendientes de OPTIMIZAR (respetando exclusiones).
	 *
	 * @param int $limit  Máximo de IDs a devolver.
	 * @param int $offset Desplazamiento.
	 * @return int[]
	 */
	public function pending_ids( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$optim_in = $this->optimizable_in_clause();
		$limit    = max( 1, $limit );
		$offset   = max( 0, $offset );

		$sql = $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m
			   ON m.post_id = p.ID AND m.meta_key = '_fasterfy_status'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type IN ({$optim_in})
			   AND ( m.meta_value IS NULL OR m.meta_value <> 'optimized' )
			 ORDER BY p.ID ASC
			 LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		$ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore

		return array_values( array_filter( $ids, fn( int $id ): bool => ! $this->is_excluded( $id ) ) );
	}

	/**
	 * Cuenta los adjuntos pendientes de optimizar.
	 *
	 * @return int
	 */
	public function count_pending(): int {
		global $wpdb;

		$optim_in = $this->optimizable_in_clause();

		return (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_fasterfy_status'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type IN ({$optim_in})
			   AND ( m.meta_value IS NULL OR m.meta_value <> 'optimized' )"
		); // phpcs:ignore
	}

	/**
	 * Obtiene IDs de adjuntos pendientes de IA (sin alt text aplicado).
	 * Incluye formatos ya optimizados (WebP/AVIF) y los que fallaron (para
	 * reintentar hasta un máximo), y excluye 'done' y 'blocked'.
	 *
	 * @param int $limit        Máximo de IDs a devolver.
	 * @param int $max_attempts Máximo de reintentos por activo.
	 * @return int[]
	 */
	public function ai_pending_ids( int $limit = 50, int $max_attempts = 3 ): array {
		global $wpdb;

		$display_in   = $this->display_in_clause();
		$limit        = max( 1, $limit );
		$max_attempts = max( 1, $max_attempts );

		$sql = $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_fasterfy_ai_status'
			 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_fasterfy_ai_attempts'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type IN ({$display_in})
			   AND ( s.meta_value IS NULL OR s.meta_value NOT IN ( 'done', 'blocked' ) )
			   AND ( a.meta_value IS NULL OR CAST( a.meta_value AS UNSIGNED ) < %d )
			 ORDER BY p.ID ASC
			 LIMIT %d",
			$max_attempts,
			$limit
		);

		$ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore

		return array_values( array_filter( $ids, fn( int $id ): bool => ! $this->is_excluded( $id ) ) );
	}

	/**
	 * Cuenta los adjuntos pendientes de IA.
	 *
	 * @param int $max_attempts Máximo de reintentos por activo.
	 * @return int
	 */
	public function count_ai_pending( int $max_attempts = 3 ): int {
		global $wpdb;

		$display_in   = $this->display_in_clause();
		$max_attempts = max( 1, $max_attempts );

		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_fasterfy_ai_status'
			 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_fasterfy_ai_attempts'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type IN ({$display_in})
			   AND ( s.meta_value IS NULL OR s.meta_value NOT IN ( 'done', 'blocked' ) )
			   AND ( a.meta_value IS NULL OR CAST( a.meta_value AS UNSIGNED ) < %d )",
			$max_attempts
		);

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore
	}

	/**
	 * IDs pendientes para el modo combinado ("ambas"): necesitan optimización
	 * O bien texto de IA. Así el botón "Optimizar + IA" también genera textos
	 * en imágenes que ya estaban optimizadas.
	 *
	 * @param int $limit        Máximo de IDs.
	 * @param int $max_attempts Máximo de reintentos de IA.
	 * @return int[]
	 */
	public function both_pending_ids( int $limit = 50, int $max_attempts = 3 ): array {
		global $wpdb;

		$display_in   = $this->display_in_clause();
		$optim_in     = $this->optimizable_in_clause();
		$limit        = max( 1, $limit );
		$max_attempts = max( 1, $max_attempts );

		$sql = $wpdb->prepare(
			"SELECT p.ID
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} st  ON st.post_id  = p.ID AND st.meta_key  = '_fasterfy_status'
			 LEFT JOIN {$wpdb->postmeta} ais ON ais.post_id = p.ID AND ais.meta_key = '_fasterfy_ai_status'
			 LEFT JOIN {$wpdb->postmeta} aia ON aia.post_id = p.ID AND aia.meta_key = '_fasterfy_ai_attempts'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type IN ({$display_in})
			   AND (
			     ( p.post_mime_type IN ({$optim_in}) AND ( st.meta_value IS NULL OR st.meta_value <> 'optimized' ) )
			     OR
			     ( ( ais.meta_value IS NULL OR ais.meta_value NOT IN ( 'done', 'blocked' ) )
			       AND ( aia.meta_value IS NULL OR CAST( aia.meta_value AS UNSIGNED ) < %d ) )
			   )
			 ORDER BY p.ID ASC
			 LIMIT %d",
			$max_attempts,
			$limit
		);

		$ids = array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore

		return array_values( array_filter( $ids, fn( int $id ): bool => ! $this->is_excluded( $id ) ) );
	}

	/**
	 * Cuenta los pendientes del modo combinado.
	 *
	 * @param int $max_attempts Máximo de reintentos de IA.
	 * @return int
	 */
	public function count_both_pending( int $max_attempts = 3 ): int {
		global $wpdb;

		$display_in   = $this->display_in_clause();
		$optim_in     = $this->optimizable_in_clause();
		$max_attempts = max( 1, $max_attempts );

		$sql = $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} st  ON st.post_id  = p.ID AND st.meta_key  = '_fasterfy_status'
			 LEFT JOIN {$wpdb->postmeta} ais ON ais.post_id = p.ID AND ais.meta_key = '_fasterfy_ai_status'
			 LEFT JOIN {$wpdb->postmeta} aia ON aia.post_id = p.ID AND aia.meta_key = '_fasterfy_ai_attempts'
			 WHERE p.post_type = 'attachment'
			   AND p.post_mime_type IN ({$display_in})
			   AND (
			     ( p.post_mime_type IN ({$optim_in}) AND ( st.meta_value IS NULL OR st.meta_value <> 'optimized' ) )
			     OR
			     ( ( ais.meta_value IS NULL OR ais.meta_value NOT IN ( 'done', 'blocked' ) )
			       AND ( aia.meta_value IS NULL OR CAST( aia.meta_value AS UNSIGNED ) < %d ) )
			   )",
			$max_attempts
		);

		return (int) $wpdb->get_var( $sql ); // phpcs:ignore
	}

	/**
	 * Determina si un adjunto está excluido por la configuración o su tipo.
	 *
	 * @param int $attachment_id ID.
	 * @return bool
	 */
	public function is_excluded( int $attachment_id ): bool {
		$exclusions = (array) $this->settings->get( 'exclusions', [] );

		$ids = array_map( 'intval', (array) ( $exclusions['attachment_ids'] ?? [] ) );
		if ( in_array( $attachment_id, $ids, true ) ) {
			return true;
		}

		$mime          = (string) get_post_mime_type( $attachment_id );
		$excluded_mime = (array) ( $exclusions['mime_types'] ?? [] );
		if ( in_array( $mime, $excluded_mime, true ) ) {
			return true;
		}
		// 'image/jpg' es una variante válida de jpeg.
		if ( 'image/jpg' !== $mime && ! in_array( $mime, self::DISPLAY_MIMES, true ) ) {
			return true;
		}

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

		$per_page   = max( 1, min( 100, (int) ( $args['per_page'] ?? 24 ) ) );
		$page       = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset     = ( $page - 1 ) * $per_page;
		$status     = (string) ( $args['status'] ?? 'all' );
		$search     = trim( (string) ( $args['search'] ?? '' ) );
		$orderby    = (string) ( $args['orderby'] ?? 'recent' );
		$display_in = $this->display_in_clause();

		$joins  = "LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_fasterfy_status'";
		$where  = "p.post_type = 'attachment' AND p.post_mime_type IN ({$display_in})";
		$params = [];

		if ( 'pending' === $status ) {
			$where .= " AND ( m.meta_value IS NULL OR m.meta_value <> 'optimized' )";
		} elseif ( 'optimized' === $status ) {
			$where .= " AND m.meta_value = 'optimized'";
		}

		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( p.post_title LIKE %s OR p.guid LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}

		// Ordenamiento inteligente.
		$order_join = '';
		$order_sql  = 'p.ID DESC';
		if ( 'savings' === $orderby ) {
			$order_join = "LEFT JOIN {$wpdb->postmeta} sv ON sv.post_id = p.ID AND sv.meta_key = '_fasterfy_saved_bytes'";
			$order_sql  = 'CAST( COALESCE( sv.meta_value, 0 ) AS UNSIGNED ) DESC';
		} elseif ( 'title' === $orderby ) {
			$order_sql = 'p.post_title ASC';
		} elseif ( 'oldest' === $orderby ) {
			$order_sql = 'p.ID ASC';
		}

		$count_sql = "SELECT COUNT(*) FROM {$wpdb->posts} p {$joins} WHERE {$where}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) // phpcs:ignore
			: $wpdb->get_var( $count_sql ) ); // phpcs:ignore

		$list_sql    = "SELECT p.ID, p.post_title, p.post_mime_type
			 FROM {$wpdb->posts} p {$joins} {$order_join}
			 WHERE {$where}
			 ORDER BY {$order_sql} LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, [ $per_page, $offset ] );
		$rows        = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A ); // phpcs:ignore

		$items = [];
		foreach ( $rows ?: [] as $row ) {
			$id      = (int) $row['ID'];
			$items[] = [
				'id'          => $id,
				'title'       => $row['post_title'],
				'mime'        => $row['post_mime_type'],
				'thumb'       => wp_get_attachment_image_url( $id, 'thumbnail' ),
				'status'      => get_post_meta( $id, '_fasterfy_status', true ) ?: 'pending',
				'ai_status'   => get_post_meta( $id, '_fasterfy_ai_status', true ) ?: '',
				'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
				'saved_bytes' => (int) get_post_meta( $id, '_fasterfy_saved_bytes', true ),
				'format_to'   => get_post_meta( $id, '_fasterfy_format_to', true ),
				'has_backup'  => (bool) get_post_meta( $id, '_fasterfy_backup', true ),
				'excluded'    => $this->is_excluded( $id ),
			];
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * Cláusula IN(...) de los MIME visibles (incluye variante image/jpg).
	 *
	 * @return string
	 */
	private function display_in_clause(): string {
		return $this->in_clause( array_merge( self::DISPLAY_MIMES, [ 'image/jpg' ] ) );
	}

	/**
	 * Cláusula IN(...) de los MIME optimizables (incluye variante image/jpg).
	 *
	 * @return string
	 */
	private function optimizable_in_clause(): string {
		return $this->in_clause( array_merge( self::OPTIMIZABLE_MIMES, [ 'image/jpg' ] ) );
	}

	/**
	 * Construye una cláusula IN(...) escapada a partir de una lista de MIME.
	 *
	 * @param string[] $mimes MIME types.
	 * @return string
	 */
	private function in_clause( array $mimes ): string {
		$quoted = array_map(
			static fn( string $m ): string => "'" . esc_sql( $m ) . "'",
			array_values( array_unique( $mimes ) )
		);
		return implode( ',', $quoted );
	}
}
