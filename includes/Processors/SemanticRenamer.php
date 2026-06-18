<?php
/**
 * Renombrado semántico SEO: reescribe el nombre del archivo en el servidor
 * usando guiones medios y términos clave derivados del análisis de IA
 * (p.ej. DCIM_00923.jpg -> chaqueta-cuero-negra-motociclista.webp) y
 * actualiza todas las referencias en la base de datos.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Processors;

use FasterFy\Support\DatabaseRewriter;

defined( 'ABSPATH' ) || exit;

/**
 * Renombra un adjunto a partir de un slug semántico.
 */
final class SemanticRenamer {

	/**
	 * Renombra el archivo principal del adjunto y sus miniaturas.
	 *
	 * @param int    $attachment_id ID del adjunto.
	 * @param string $keywords      Términos clave (p.ej. "chaqueta cuero negra").
	 * @return bool True si se renombró.
	 */
	public static function rename( int $attachment_id, string $keywords ): bool {
		$slug = self::slugify( $keywords );
		if ( '' === $slug ) {
			return false;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return false;
		}

		$dir = dirname( $file );
		$ext = pathinfo( $file, PATHINFO_EXTENSION );

		// Garantiza unicidad dentro del directorio.
		$new_basename = wp_unique_filename( $dir, $slug . '.' . $ext );
		$new_file     = trailingslashit( $dir ) . $new_basename;

		if ( ! @rename( $file, $new_file ) ) { // phpcs:ignore
			return false;
		}

		$uploads     = wp_upload_dir();
		$old_url     = trailingslashit( $uploads['baseurl'] ) . _wp_relative_upload_path( $file );
		$new_url     = trailingslashit( $uploads['baseurl'] ) . _wp_relative_upload_path( $new_file );

		// Actualiza el puntero del archivo y regenera metadatos/miniaturas.
		update_attached_file( $attachment_id, $new_file );

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$meta = wp_generate_attachment_metadata( $attachment_id, $new_file );
		if ( is_array( $meta ) ) {
			wp_update_attachment_metadata( $attachment_id, $meta );
		}

		// Reescribe referencias del archivo principal en la base de datos.
		DatabaseRewriter::replace_url( $old_url, $new_url );

		update_post_meta( $attachment_id, '_fasterfy_renamed_to', $new_basename );

		return true;
	}

	/**
	 * Convierte una frase de keywords en un slug SEO con guiones medios.
	 *
	 * @param string $keywords Texto.
	 * @return string
	 */
	public static function slugify( string $keywords ): string {
		$keywords = wp_strip_all_tags( $keywords );
		$slug     = sanitize_title( $keywords );
		// Limita a un número razonable de términos.
		$parts = array_filter( explode( '-', $slug ) );
		$parts = array_slice( $parts, 0, 8 );
		return implode( '-', $parts );
	}
}
