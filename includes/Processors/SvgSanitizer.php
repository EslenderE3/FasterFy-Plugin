<?php
/**
 * Procesador SVG: NO aplica compresión de mapa de bits. Sanitiza el XML
 * eliminando metadatos de software de diseño, comentarios y vectores de
 * ataque (scripts, eventos, hrefs peligrosos).
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Processors;

use FasterFy\Processors\Contracts\Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Limpia y minimiza SVG de forma segura.
 */
final class SvgSanitizer implements Processor {

	/**
	 * Elementos peligrosos que se eliminan por completo.
	 *
	 * @var string[]
	 */
	private const FORBIDDEN_TAGS = [
		'script', 'foreignObject', 'iframe', 'embed', 'object',
		'audio', 'video', 'animate', 'set', 'handler',
	];

	/**
	 * Etiquetas de metadatos de software de diseño a remover.
	 *
	 * @var string[]
	 */
	private const METADATA_TAGS = [ 'metadata', 'sodipodi:namedview', 'inkscape:perspective' ];

	/**
	 * {@inheritDoc}
	 */
	public function supports( string $mime ): bool {
		return in_array( $mime, [ 'image/svg+xml', 'image/svg' ], true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function process( string $file, array $options = [] ): ProcessResult {
		if ( ! is_readable( $file ) ) {
			return ProcessResult::fail( __( 'El archivo SVG no es legible.', 'fasterfy' ) );
		}

		$conversion = $options['conversion'] ?? [];
		if ( empty( $conversion['sanitize_svg'] ) ) {
			return ProcessResult::skip( __( 'Sanitización SVG desactivada.', 'fasterfy' ) );
		}

		$original = (string) file_get_contents( $file ); // phpcs:ignore
		if ( '' === trim( $original ) ) {
			return ProcessResult::fail( __( 'El SVG está vacío o corrupto.', 'fasterfy' ) );
		}

		$original_size = strlen( $original );
		$clean         = $this->sanitize( $original );

		if ( '' === $clean ) {
			return ProcessResult::fail( __( 'No se pudo sanear el SVG (XML inválido).', 'fasterfy' ) );
		}

		$temp = trailingslashit( dirname( $file ) ) . pathinfo( $file, PATHINFO_FILENAME ) . '.fasterfy-tmp.svg';
		if ( false === file_put_contents( $temp, $clean ) ) { // phpcs:ignore
			return ProcessResult::fail( __( 'No se pudo escribir el SVG saneado.', 'fasterfy' ) );
		}

		$output_size = strlen( $clean );

		return ProcessResult::ok(
			[
				'original_path'  => $file,
				'output_path'    => $temp,
				'source_mime'    => 'image/svg+xml',
				'output_mime'    => 'image/svg+xml',
				'original_size'  => $original_size,
				'output_size'    => $output_size,
				'format_changed' => false,
				'meta'           => [ 'mode' => 'sanitize' ],
			]
		);
	}

	/**
	 * Sanea el contenido SVG eliminando metadatos y vectores de ataque.
	 *
	 * @param string $svg Contenido XML.
	 * @return string SVG limpio, o cadena vacía si es inválido.
	 */
	private function sanitize( string $svg ): string {
		// Elimina la declaración de DOCTYPE (previene XXE).
		$svg = preg_replace( '/<!DOCTYPE[^>]*>/i', '', $svg ) ?? $svg;
		// Elimina comentarios.
		$svg = preg_replace( '/<!--.*?-->/s', '', $svg ) ?? $svg;

		$prev = libxml_use_internal_errors( true );
		$prev_entity_loader = null;
		if ( function_exists( 'libxml_disable_entity_loader' ) && PHP_VERSION_ID < 80000 ) {
			$prev_entity_loader = libxml_disable_entity_loader( true ); // phpcs:ignore
		}

		$dom                     = new \DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput       = false;

		$loaded = $dom->loadXML( $svg, LIBXML_NONET | LIBXML_NOENT | LIBXML_NOERROR | LIBXML_NOWARNING );

		if ( ! $loaded ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $prev );
			return '';
		}

		$this->strip_nodes( $dom );
		$this->strip_attributes( $dom );

		$output = (string) $dom->saveXML( $dom->documentElement );

		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( null !== $prev_entity_loader && function_exists( 'libxml_disable_entity_loader' ) ) {
			libxml_disable_entity_loader( $prev_entity_loader ); // phpcs:ignore
		}

		// Cabecera XML mínima.
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . trim( $output );
	}

	/**
	 * Elimina nodos prohibidos y de metadatos.
	 *
	 * @param \DOMDocument $dom Documento.
	 * @return void
	 */
	private function strip_nodes( \DOMDocument $dom ): void {
		$remove = array_merge( self::FORBIDDEN_TAGS, self::METADATA_TAGS );
		foreach ( $remove as $tag ) {
			$nodes = $dom->getElementsByTagName( $this->local_name( $tag ) );
			// Iteramos en reversa porque la lista es viva.
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item( $i );
				if ( $node && $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	/**
	 * Elimina atributos peligrosos (eventos on*, hrefs javascript:, etc.).
	 *
	 * @param \DOMDocument $dom Documento.
	 * @return void
	 */
	private function strip_attributes( \DOMDocument $dom ): void {
		$xpath = new \DOMXPath( $dom );
		$nodes = $xpath->query( '//*' );
		if ( ! $nodes ) {
			return;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement || ! $node->hasAttributes() ) {
				continue;
			}
			$to_remove = [];
			foreach ( iterator_to_array( $node->attributes ) as $attr ) {
				$name  = strtolower( $attr->nodeName );
				$value = strtolower( trim( (string) $attr->nodeValue ) );

				// Manejadores de eventos (onclick, onload...).
				if ( str_starts_with( $name, 'on' ) ) {
					$to_remove[] = $attr->nodeName;
					continue;
				}
				// URLs peligrosas en href/xlink:href/src.
				if ( in_array( $name, [ 'href', 'xlink:href', 'src' ], true ) ) {
					if ( str_starts_with( $value, 'javascript:' ) || str_starts_with( $value, 'data:text/html' ) ) {
						$to_remove[] = $attr->nodeName;
					}
				}
				// Atributos de software de diseño.
				if ( str_starts_with( $name, 'sodipodi:' ) || str_starts_with( $name, 'inkscape:' ) ) {
					$to_remove[] = $attr->nodeName;
				}
			}
			foreach ( $to_remove as $attr_name ) {
				$node->removeAttribute( $attr_name );
			}
		}
	}

	/**
	 * Devuelve el nombre local de una etiqueta (sin prefijo de namespace).
	 *
	 * @param string $tag Etiqueta.
	 * @return string
	 */
	private function local_name( string $tag ): string {
		$pos = strpos( $tag, ':' );
		return false === $pos ? $tag : substr( $tag, $pos + 1 );
	}
}
