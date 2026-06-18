<?php
/**
 * Reescritura controlada de URLs en la base de datos cuando un activo
 * cambia de nombre o de formato (mutación nativa retroactiva).
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Reemplaza ocurrencias de una URL por otra en contenido y metadatos,
 * respetando datos serializados.
 */
final class DatabaseRewriter {

	/**
	 * Reemplaza una URL por otra en post_content y postmeta.
	 *
	 * @param string $from URL original.
	 * @param string $to   URL nueva.
	 * @return int Número de filas afectadas (aproximado).
	 */
	public static function replace_url( string $from, string $to ): int {
		if ( '' === $from || $from === $to ) {
			return 0;
		}

		global $wpdb;
		$affected = 0;

		// 1) post_content (incluye también variantes con miniaturas -150x150, etc.).
		$variants = self::url_variants( $from, $to );

		foreach ( $variants as $pair ) {
			$affected += (int) $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
					$pair[0],
					$pair[1],
					'%' . $wpdb->esc_like( $pair[0] ) . '%'
				)
			); // phpcs:ignore
		}

		// 2) postmeta: solo filas no serializadas para evitar corromper estructuras.
		foreach ( $variants as $pair ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s",
					'%' . $wpdb->esc_like( $pair[0] ) . '%'
				),
				ARRAY_A
			); // phpcs:ignore

			foreach ( $rows ?: [] as $row ) {
				$value = $row['meta_value'];
				if ( is_serialized( $value ) ) {
					$updated = self::replace_in_serialized( $value, $pair[0], $pair[1] );
				} else {
					$updated = str_replace( $pair[0], $pair[1], $value );
				}
				if ( $updated !== $value ) {
					$wpdb->update(
						$wpdb->postmeta,
						[ 'meta_value' => $updated ],
						[ 'meta_id' => (int) $row['meta_id'] ],
						[ '%s' ],
						[ '%d' ]
					); // phpcs:ignore
					++$affected;
				}
			}
		}

		return $affected;
	}

	/**
	 * Genera pares de variantes de URL (full + extensión base) para cubrir
	 * miniaturas que comparten el mismo nombre base.
	 *
	 * @param string $from URL origen.
	 * @param string $to   URL destino.
	 * @return array<int, array{0:string,1:string}>
	 */
	private static function url_variants( string $from, string $to ): array {
		$pairs = [ [ $from, $to ] ];

		// Variante sin extensión: cubre las miniaturas -WxH.ext.
		$from_base = preg_replace( '/\.[a-z0-9]+$/i', '', $from );
		$to_base   = preg_replace( '/\.[a-z0-9]+$/i', '', $to );
		if ( $from_base && $to_base && $from_base !== $from ) {
			$pairs[] = [ $from_base . '-', $to_base . '-' ];
		}

		return $pairs;
	}

	/**
	 * Reemplaza dentro de un valor serializado de forma segura.
	 *
	 * @param string $serialized Valor serializado.
	 * @param string $from       Texto a buscar.
	 * @param string $to         Texto de reemplazo.
	 * @return string
	 */
	private static function replace_in_serialized( string $serialized, string $from, string $to ): string {
		$data = @unserialize( $serialized ); // phpcs:ignore
		if ( false === $data && 'b:0;' !== $serialized ) {
			return $serialized;
		}
		$data = self::recursive_replace( $data, $from, $to );
		return serialize( $data ); // phpcs:ignore
	}

	/**
	 * Reemplazo recursivo en estructuras de datos.
	 *
	 * @param mixed  $data Datos.
	 * @param string $from Buscar.
	 * @param string $to   Reemplazar.
	 * @return mixed
	 */
	private static function recursive_replace( $data, string $from, string $to ) {
		if ( is_string( $data ) ) {
			return str_replace( $from, $to, $data );
		}
		if ( is_array( $data ) ) {
			$out = [];
			foreach ( $data as $k => $v ) {
				$out[ $k ] = self::recursive_replace( $v, $from, $to );
			}
			return $out;
		}
		if ( is_object( $data ) ) {
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->{$k} = self::recursive_replace( $v, $from, $to );
			}
			return $data;
		}
		return $data;
	}
}
