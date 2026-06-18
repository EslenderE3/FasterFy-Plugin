<?php
/**
 * Capa de moderación de contenido (NSFW / SafeSearch). Antes de enviar un
 * activo al modelo generativo, se evalúa si contiene material explícito o
 * violento. Si supera el umbral, se bloquea el envío para proteger las
 * cuentas de API corporativas.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\AI;

use FasterFy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Evalúa la seguridad del contenido visual.
 */
final class Moderation {

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
	 * Determina si una imagen es segura para enviarse al modelo generativo.
	 *
	 * @param string $image_path Ruta de la imagen.
	 * @return array{safe: bool, score: float, categories: array<string, float>, message: string}
	 */
	public function evaluate( string $image_path ): array {
		$default = [
			'safe'       => true,
			'score'      => 0.0,
			'categories' => [],
			'message'    => '',
		];

		if ( ! $this->settings->get( 'moderation.enabled', true ) ) {
			return $default;
		}

		/**
		 * Permite sustituir la moderación por un servicio externo
		 * (p.ej. Google Cloud Vision SafeSearch). Si el filtro devuelve
		 * un array con 'safe', se respeta su veredicto.
		 *
		 * @param array|null $verdict    Veredicto externo o null.
		 * @param string     $image_path Ruta de la imagen.
		 */
		$external = apply_filters( 'fasterfy_moderation_verdict', null, $image_path );
		if ( is_array( $external ) && isset( $external['safe'] ) ) {
			return wp_parse_args( $external, $default );
		}

		// Moderación nativa vía endpoint OpenAI-compatible (omni-moderation).
		if ( $this->settings->has_api_key() ) {
			$verdict = $this->evaluate_openai( $image_path );
			if ( null !== $verdict ) {
				return $verdict;
			}
		}

		// Sin servicio de moderación disponible: por seguridad, se considera segura
		// pero se registra que no hubo verificación.
		$default['message'] = __( 'Moderación no verificada (sin servicio disponible).', 'fasterfy' );
		return $default;
	}

	/**
	 * Evalúa usando el endpoint de moderación multimodal de OpenAI.
	 *
	 * @param string $image_path Ruta.
	 * @return array{safe: bool, score: float, categories: array<string, float>, message: string}|null
	 */
	private function evaluate_openai( string $image_path ): ?array {
		$mime   = (string) ( wp_check_filetype( $image_path )['type'] ?? 'image/jpeg' );
		$binary = @file_get_contents( $image_path ); // phpcs:ignore
		if ( false === $binary ) {
			return null;
		}
		$data_uri = 'data:' . $mime . ';base64,' . base64_encode( $binary ); // phpcs:ignore

		$base = untrailingslashit( (string) $this->settings->get( 'ai.api_base', 'https://api.openai.com/v1' ) );

		$response = wp_remote_post(
			$base . '/moderations',
			[
				'timeout' => 30,
				'headers' => [
					'Authorization' => 'Bearer ' . $this->settings->get_api_key(),
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode(
					[
						'model' => 'omni-moderation-latest',
						'input' => [
							[
								'type'      => 'image_url',
								'image_url' => [ 'url' => $data_uri ],
							],
						],
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return null;
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$result = $body['results'][0] ?? null;
		if ( ! is_array( $result ) ) {
			return null;
		}

		$scores    = isset( $result['category_scores'] ) && is_array( $result['category_scores'] ) ? $result['category_scores'] : [];
		$max_score = $scores ? (float) max( $scores ) : 0.0;
		$threshold = (float) $this->settings->get( 'moderation.nsfw_threshold', 0.7 );

		$flagged = ! empty( $result['flagged'] ) || $max_score >= $threshold;

		return [
			'safe'       => ! $flagged,
			'score'      => $max_score,
			'categories' => array_map( 'floatval', $scores ),
			'message'    => $flagged ? __( 'Contenido marcado como sensible por la moderación.', 'fasterfy' ) : '',
		];
	}
}
