<?php
/**
 * Orquestador de IA: gobierna la moderación de contenido, el análisis de
 * visión y la inyección nativa de metadatos SEO (_wp_attachment_image_alt),
 * con gestión de excepciones para contenido sensible.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\AI;

use FasterFy\AI\Contracts\AIProvider;
use FasterFy\Contracts\Bootable;
use FasterFy\Logger;
use FasterFy\Processors\SemanticRenamer;
use FasterFy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Punto de entrada de alto nivel del subsistema de IA.
 */
final class AIManager implements Bootable {

	private Settings $settings;
	private Logger $logger;
	private ?AIProvider $provider = null;
	private ?Moderation $moderation = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Ajustes.
	 * @param Logger   $logger   Logger.
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		// El subsistema de IA se invoca bajo demanda; no requiere hooks propios.
	}

	/**
	 * Resuelve el proveedor configurado.
	 *
	 * @return AIProvider
	 */
	public function provider(): AIProvider {
		if ( null === $this->provider ) {
			// Tanto 'openai' como 'fasterfy_cloud' usan el protocolo OpenAI-compatible,
			// diferenciados por la URL base configurada.
			$this->provider = new OpenAIProvider( $this->settings );
			/**
			 * Permite registrar un proveedor de IA personalizado.
			 *
			 * @param AIProvider $provider Proveedor por defecto.
			 * @param Settings   $settings Ajustes.
			 */
			$this->provider = apply_filters( 'fasterfy_ai_provider', $this->provider, $this->settings );
		}
		return $this->provider;
	}

	/**
	 * Instancia perezosa de la moderación.
	 *
	 * @return Moderation
	 */
	public function moderation(): Moderation {
		if ( null === $this->moderation ) {
			$this->moderation = new Moderation( $this->settings );
		}
		return $this->moderation;
	}

	/**
	 * Indica si la IA está habilitada y configurada.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return (bool) $this->settings->get( 'ai.enabled', false ) && $this->settings->has_api_key();
	}

	/**
	 * Verifica la conexión con el proveedor.
	 *
	 * @return array{ok: bool, message: string}
	 */
	public function health(): array {
		return $this->provider()->health();
	}

	/**
	 * Procesa un adjunto con IA: modera, analiza e inyecta metadatos SEO.
	 *
	 * @param int $attachment_id ID del adjunto.
	 * @return array{success: bool, blocked: bool, alt: string, message: string}
	 */
	public function process_attachment( int $attachment_id ): array {
		$fail = [ 'success' => false, 'blocked' => false, 'alt' => '', 'message' => '' ];

		if ( ! $this->is_enabled() ) {
			$fail['message'] = __( 'La IA no está habilitada.', 'fasterfy' );
			return $fail;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			$this->bump_ai_attempts( $attachment_id );
			update_post_meta( $attachment_id, '_fasterfy_ai_status', 'error' );
			$fail['message'] = __( 'No se encontró el archivo del adjunto.', 'fasterfy' );
			return $fail;
		}

		// 1) Moderación previa: protege las cuentas de API corporativas.
		$verdict = $this->moderation()->evaluate( $file );
		if ( ! $verdict['safe'] && (bool) $this->settings->get( 'moderation.block_generative', true ) ) {
			return $this->handle_blocked( $attachment_id, $verdict );
		}

		// 2) Análisis de visión.
		$context = [
			'language'       => $this->settings->get( 'ai.language', 'es' ),
			'alt_max_length' => $this->settings->get( 'ai.alt_max_length', 125 ),
			'taxonomies'     => $this->context_taxonomies( $attachment_id ),
		];

		$result = $this->provider()->analyze( $file, $context );

		if ( ! $result->success ) {
			$this->bump_ai_attempts( $attachment_id );
			update_post_meta( $attachment_id, '_fasterfy_ai_status', 'error' );
			$this->logger->error( $result->message, 'ai', $attachment_id );
			$fail['message'] = $result->message;
			return $fail;
		}

		// 3) Inyección nativa de metadatos SEO.
		$injected_alt = '';
		if ( (bool) $this->settings->get( 'ai.generate_alt', true ) && '' !== $result->alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $result->alt );
			$injected_alt = $result->alt;
		}

		if ( (bool) $this->settings->get( 'ai.generate_title', false ) && '' !== $result->title ) {
			$title = (bool) $this->settings->get( 'ai.hyphenate_title', false )
				? $this->hyphenate( $result->title )
				: $result->title;
			wp_update_post(
				[
					'ID'           => $attachment_id,
					'post_title'   => $title,
					'post_excerpt' => $result->alt, // leyenda.
				]
			);
		}

		// Descripción del adjunto (post_content). Usa la descripción detallada,
		// con respaldo al alt si el modelo no la devolvió.
		if ( (bool) $this->settings->get( 'ai.generate_description', false ) ) {
			$description = '' !== $result->description ? $result->description : $result->alt;
			if ( '' !== $description ) {
				wp_update_post(
					[
						'ID'           => $attachment_id,
						'post_content' => $description,
					]
				);
			}
		}

		// 4) Renombrado semántico opcional.
		if ( (bool) $this->settings->get( 'ai.semantic_rename', false ) ) {
			$keywords = '' !== $result->keywords ? $result->keywords : $result->alt;
			if ( '' !== $keywords ) {
				SemanticRenamer::rename( $attachment_id, $keywords );
			}
		}

		update_post_meta( $attachment_id, '_fasterfy_ai_status', 'done' );
		update_post_meta( $attachment_id, '_fasterfy_ai_at', current_time( 'mysql', true ) );

		$this->logger->info(
			sprintf( __( 'IA aplicada: alt="%s".', 'fasterfy' ), $injected_alt ),
			'ai',
			$attachment_id,
			[ 'keywords' => $result->keywords ]
		);

		return [
			'success' => true,
			'blocked' => false,
			'alt'     => $injected_alt,
			'message' => __( 'Metadatos de IA aplicados.', 'fasterfy' ),
		];
	}

	/**
	 * Gestiona un activo bloqueado por moderación: NO se envía al modelo
	 * generativo, pero se le asigna un alt genérico y se registra el evento.
	 *
	 * @param int                  $attachment_id ID.
	 * @param array<string, mixed> $verdict       Veredicto de moderación.
	 * @return array{success: bool, blocked: bool, alt: string, message: string}
	 */
	private function handle_blocked( int $attachment_id, array $verdict ): array {
		$fallback = (string) $this->settings->get( 'moderation.fallback_alt', '' );
		if ( '' === $fallback ) {
			$taxonomies = $this->context_taxonomies( $attachment_id );
			$fallback   = $taxonomies
				? implode( ', ', array_slice( $taxonomies, 0, 3 ) )
				: get_bloginfo( 'name' );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $fallback );
		update_post_meta( $attachment_id, '_fasterfy_ai_status', 'blocked' );

		$this->logger->warning(
			sprintf(
				/* translators: %s = puntuación */
				__( 'Activo bloqueado por moderación (score %s). Se omitió la IA generativa.', 'fasterfy' ),
				number_format( (float) ( $verdict['score'] ?? 0 ), 2 )
			),
			'moderation',
			$attachment_id,
			[ 'categories' => $verdict['categories'] ?? [] ]
		);

		return [
			'success' => true,
			'blocked' => true,
			'alt'     => $fallback,
			'message' => __( 'Contenido sensible: optimización técnica aplicada, IA generativa omitida.', 'fasterfy' ),
		];
	}

	/**
	 * Incrementa el contador de intentos de IA de un adjunto.
	 *
	 * @param int $attachment_id ID.
	 * @return void
	 */
	private function bump_ai_attempts( int $attachment_id ): void {
		$attempts = (int) get_post_meta( $attachment_id, '_fasterfy_ai_attempts', true );
		update_post_meta( $attachment_id, '_fasterfy_ai_attempts', $attempts + 1 );
	}

	/**
	 * Convierte un título en formato con guiones (SEO), conservando acentos
	 * y mayúsculas. Ej.: "Retrato de hombre joven" -> "Retrato-de-hombre-joven".
	 *
	 * @param string $text Título original.
	 * @return string
	 */
	private function hyphenate( string $text ): string {
		$text = wp_strip_all_tags( $text );
		// Elimina signos de puntuación habituales.
		$text = preg_replace( '/[\.,;:!\?¿¡"\'()]+/u', '', $text ) ?? $text;
		// Sustituye secuencias de espacios por un único guión.
		$text = preg_replace( '/\s+/u', '-', trim( $text ) ) ?? $text;
		return trim( $text, '-' );
	}

	/**
	 * Recopila taxonomías/contexto del sitio para guiar a la IA.
	 *
	 * @param int $attachment_id ID.
	 * @return string[]
	 */
	private function context_taxonomies( int $attachment_id ): array {
		$terms  = [];
		$parent = (int) wp_get_post_parent_id( $attachment_id );

		if ( $parent > 0 ) {
			$taxonomies = get_object_taxonomies( get_post_type( $parent ) ?: 'post' );
			foreach ( $taxonomies as $tax ) {
				$post_terms = get_the_terms( $parent, $tax );
				if ( is_array( $post_terms ) ) {
					foreach ( $post_terms as $term ) {
						$terms[] = $term->name;
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $terms ) ) );
	}
}
