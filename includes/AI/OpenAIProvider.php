<?php
/**
 * Proveedor de IA compatible con la API de OpenAI (Chat Completions con
 * visión). Las credenciales viven en el servidor y se transmiten por
 * cabecera Bearer. Soporta endpoints autoalojados o de FasterFy Cloud
 * cambiando la URL base.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\AI;

use FasterFy\AI\Contracts\AIProvider;
use FasterFy\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Cliente multimodal OpenAI-compatible.
 */
final class OpenAIProvider implements AIProvider {

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
	 * {@inheritDoc}
	 */
	public function id(): string {
		return 'openai';
	}

	/**
	 * {@inheritDoc}
	 */
	public function health(): array {
		if ( ! $this->settings->has_api_key() ) {
			return [
				'ok'      => false,
				'message' => __( 'No hay API Key configurada.', 'fasterfy' ),
			];
		}

		$response = wp_remote_get(
			$this->endpoint( '/models' ),
			[
				'timeout' => 15,
				'headers' => $this->headers(),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'message' => $response->get_error_message() ];
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return [ 'ok' => true, 'message' => __( 'Conexión correcta con el proveedor de IA.', 'fasterfy' ) ];
		}

		return [
			'ok'      => false,
			'message' => sprintf(
				/* translators: %d = código HTTP */
				__( 'El proveedor respondió con el código HTTP %d.', 'fasterfy' ),
				$code
			),
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function analyze( string $image_path, array $context = [] ): VisionResult {
		if ( ! $this->settings->has_api_key() ) {
			return VisionResult::fail( __( 'No hay API Key configurada para el análisis de IA.', 'fasterfy' ) );
		}
		if ( ! is_readable( $image_path ) ) {
			return VisionResult::fail( __( 'La imagen no es legible para el análisis.', 'fasterfy' ) );
		}

		$data_uri = $this->to_data_uri( $image_path );
		if ( '' === $data_uri ) {
			return VisionResult::fail( __( 'No se pudo preparar la imagen para el análisis.', 'fasterfy' ) );
		}

		$language    = (string) ( $context['language'] ?? $this->settings->get( 'ai.language', 'es' ) );
		$max_length  = (int) ( $context['alt_max_length'] ?? $this->settings->get( 'ai.alt_max_length', 125 ) );
		$temperature = (float) $this->settings->get( 'ai.temperature', 0.1 );
		$model       = (string) $this->settings->get( 'ai.vision_model', 'gpt-4o-mini' );

		$system = $this->system_prompt( $language, $max_length );
		$user   = $this->user_prompt( $language, $context );

		$body = [
			'model'       => $model,
			'temperature' => $temperature,
			'max_tokens'  => 400,
			'messages'    => [
				[
					'role'    => 'system',
					'content' => $system,
				],
				[
					'role'    => 'user',
					'content' => [
						[
							'type' => 'text',
							'text' => $user,
						],
						[
							'type'      => 'image_url',
							'image_url' => [ 'url' => $data_uri ],
						],
					],
				],
			],
			// Fuerza salida JSON estructurada cuando el modelo lo soporta.
			'response_format' => [ 'type' => 'json_object' ],
		];

		$response = wp_remote_post(
			$this->endpoint( '/chat/completions' ),
			[
				'timeout' => 45,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return VisionResult::fail( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = $raw['error']['message'] ?? sprintf( 'HTTP %d', $code );
			return VisionResult::fail( (string) $msg );
		}

		$content = $raw['choices'][0]['message']['content'] ?? '';
		$parsed  = $this->parse_content( (string) $content );

		if ( '' === $parsed['alt'] ) {
			return VisionResult::fail( __( 'El modelo no devolvió una descripción utilizable.', 'fasterfy' ) );
		}

		return VisionResult::ok(
			[
				'alt'      => $this->truncate( $parsed['alt'], $max_length ),
				'title'    => $parsed['title'],
				'keywords' => $parsed['keywords'],
				'raw'      => [ 'model' => $model, 'usage' => $raw['usage'] ?? null ],
			]
		);
	}

	/* ----------------------------------------------------------------
	 * Prompts (gobierno de IA / anti-alucinación)
	 * ---------------------------------------------------------------- */

	/**
	 * Prompt de sistema: descripción denotativa, fáctica, sin invención.
	 *
	 * @param string $language   Idioma.
	 * @param int    $max_length Longitud máxima del alt.
	 * @return string
	 */
	private function system_prompt( string $language, int $max_length ): string {
		return sprintf(
			'Eres un sistema de visión artificial para SEO y accesibilidad web. '
			. 'Describe ÚNICAMENTE lo que se observa de forma objetiva y denotativa en la imagen. '
			. 'Prohibido inventar elementos, marcas, nombres propios o emociones que no sean evidentes. '
			. 'Responde EXCLUSIVAMENTE en formato JSON con las claves: '
			. '"alt" (texto alternativo conciso en %1$s, máx. %2$d caracteres), '
			. '"title" (título corto en %1$s), '
			. '"keywords" (3 a 6 términos clave separados por espacios, en %1$s, sin signos de puntuación).',
			$language,
			$max_length
		);
	}

	/**
	 * Prompt de usuario con contexto opcional (taxonomías del sitio).
	 *
	 * @param string               $language Idioma.
	 * @param array<string, mixed> $context  Contexto.
	 * @return string
	 */
	private function user_prompt( string $language, array $context ): string {
		$hint = '';
		if ( ! empty( $context['taxonomies'] ) && is_array( $context['taxonomies'] ) ) {
			$hint = ' Contexto del sitio (úsalo solo si es coherente con la imagen): '
				. implode( ', ', array_map( 'sanitize_text_field', $context['taxonomies'] ) ) . '.';
		}
		return 'Analiza esta imagen y genera el JSON solicitado.' . $hint;
	}

	/* ----------------------------------------------------------------
	 * Utilidades HTTP / parsing
	 * ---------------------------------------------------------------- */

	/**
	 * Cabeceras de autenticación.
	 *
	 * @return array<string, string>
	 */
	private function headers(): array {
		return [
			'Authorization' => 'Bearer ' . $this->settings->get_api_key(),
			'Content-Type'  => 'application/json',
		];
	}

	/**
	 * Construye la URL completa del endpoint.
	 *
	 * @param string $path Ruta relativa (con barra inicial).
	 * @return string
	 */
	private function endpoint( string $path ): string {
		$base = untrailingslashit( (string) $this->settings->get( 'ai.api_base', 'https://api.openai.com/v1' ) );
		return $base . $path;
	}

	/**
	 * Convierte la imagen a un data URI base64 (reescalando si es enorme).
	 *
	 * @param string $image_path Ruta.
	 * @return string
	 */
	private function to_data_uri( string $image_path ): string {
		$mime = (string) ( wp_check_filetype( $image_path )['type'] ?? '' );
		if ( '' === $mime ) {
			$mime = 'image/jpeg';
		}

		// Para SVG enviamos el texto tal cual.
		if ( 'image/svg+xml' === $mime ) {
			$svg = (string) file_get_contents( $image_path ); // phpcs:ignore
			return 'data:image/svg+xml;base64,' . base64_encode( $svg ); // phpcs:ignore
		}

		$binary = file_get_contents( $image_path ); // phpcs:ignore
		if ( false === $binary ) {
			return '';
		}
		return 'data:' . $mime . ';base64,' . base64_encode( $binary ); // phpcs:ignore
	}

	/**
	 * Interpreta el contenido devuelto por el modelo (JSON o texto plano).
	 *
	 * @param string $content Contenido.
	 * @return array{alt: string, title: string, keywords: string}
	 */
	private function parse_content( string $content ): array {
		$out = [ 'alt' => '', 'title' => '', 'keywords' => '' ];

		$json = json_decode( $content, true );
		if ( ! is_array( $json ) ) {
			// Intenta extraer un bloque JSON embebido.
			if ( preg_match( '/\{.*\}/s', $content, $m ) ) {
				$json = json_decode( $m[0], true );
			}
		}

		if ( is_array( $json ) ) {
			$out['alt']      = sanitize_text_field( (string) ( $json['alt'] ?? '' ) );
			$out['title']    = sanitize_text_field( (string) ( $json['title'] ?? '' ) );
			$out['keywords'] = sanitize_text_field( (string) ( $json['keywords'] ?? '' ) );
		} else {
			// Respaldo: usa el texto como alt.
			$out['alt'] = sanitize_text_field( $content );
		}

		return $out;
	}

	/**
	 * Trunca respetando palabras.
	 *
	 * @param string $text   Texto.
	 * @param int    $length Longitud.
	 * @return string
	 */
	private function truncate( string $text, int $length ): string {
		$text = trim( $text );
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $length );
		$pos = mb_strrpos( $cut, ' ' );
		return false !== $pos ? rtrim( mb_substr( $cut, 0, $pos ) ) : $cut;
	}
}
