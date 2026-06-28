<?php
/**
 * Capa de configuración del plugin (Options API).
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy;

defined( 'ABSPATH' ) || exit;

/**
 * Gestiona la lectura/escritura de las opciones de FasterFy con
 * mezcla profunda contra los valores por defecto.
 */
final class Settings {

	public const OPTION_KEY = 'fasterfy_settings';

	/**
	 * Cache en memoria de las opciones resueltas.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Valores por defecto del plugin.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			// Modo de interfaz: 'lite' (un clic) o 'pro' (control fino).
			'mode'        => 'lite',

			// Pipeline de conversión / compresión.
			'conversion'  => [
				// Formato objetivo para JPG/PNG: 'webp', 'avif' o 'auto'.
				'target_format'      => 'webp',
				// Mantener el formato original como fallback en el HTML (<picture>).
				'keep_original'      => true,
				// Calidad WebP (0-100, con pérdida).
				'webp_quality'       => 80,
				// Calidad AVIF (0-100).
				'avif_quality'       => 60,
				// Compresión PNG: 'lossless' o 'lossy' (cuantización de color).
				'png_strategy'       => 'lossy',
				// Convertir PNG a WebP/AVIF cuando ahorre más espacio (conserva alfa).
				'png_to_webp'        => true,
				// Número de colores objetivo para la cuantización PNG (lossy).
				'png_max_colors'     => 256,
				// Calidad JPEG de respaldo si no se puede convertir.
				'jpeg_quality'       => 82,
				// Sanitizar SVG (eliminar metadatos y scripts peligrosos).
				'sanitize_svg'       => true,
				// Redimensionar imágenes que excedan este ancho máximo (0 = desactivado).
				'max_width'          => 2560,
				// Preservar metadatos EXIF/ICC.
				'strip_metadata'     => true,
			],

			// Inteligencia Artificial multimodal.
			'ai'          => [
				'enabled'           => false,
				// Proveedor: 'openai' (compatible) o 'fasterfy_cloud'.
				'provider'          => 'openai',
				// Endpoint base de la API (compatible con OpenAI).
				'api_base'          => 'https://api.openai.com/v1',
				// API Key cifrada en reposo (ver Settings::set_api_key()).
				'api_key'           => '',
				// Modelo multimodal de visión.
				'vision_model'      => 'gpt-4o-mini',
				// Temperatura baja para descripciones fácticas (anti-alucinación).
				'temperature'       => 0.1,
				// Generar Alt Text automáticamente.
				'generate_alt'      => true,
				// Generar título / leyenda.
				'generate_title'    => false,
				// Generar descripción del adjunto (post_content).
				'generate_description' => true,
				// Título con palabras separadas por guiones (formato SEO).
				'hyphenate_title'   => true,
				// Renombrado semántico del archivo a partir de keywords de IA.
				'semantic_rename'   => false,
				// Idioma de las descripciones generadas.
				'language'          => 'es',
				// Longitud máxima del alt text (caracteres).
				'alt_max_length'    => 125,
			],

			// Gobierno de IA y moderación de contenido.
			'moderation'  => [
				'enabled'              => true,
				// Si una imagen es sensible: igual se optimiza técnicamente
				// pero NO se envía al modelo generativo.
				'block_generative'     => true,
				// Alt text genérico para activos bloqueados.
				'fallback_alt'         => '',
				// Umbral de bloqueo (0-1) para contenido explícito.
				'nsfw_threshold'       => 0.7,
			],

			// Control de carga, concurrencia y throttling.
			'throttling'  => [
				// Imágenes por lote en el procesamiento de cola.
				'batch_size'           => 10,
				// Peticiones concurrentes máximas hacia la nube por usuario.
				'max_concurrency'      => 10,
				// Segundos de espera entre lotes (enfriamiento).
				'cooldown_seconds'     => 5,
			],

			// Cuotas / créditos por suscripción.
			'quota'       => [
				// Plan: 'starter', 'growth', 'agency'.
				'plan'                 => 'starter',
				// Créditos mensuales de assets (0 = ilimitado / autohospedado).
				'monthly_credits'      => 0,
				// Créditos usados en el ciclo actual.
				'used_credits'         => 0,
				// Pausar la cola al agotar créditos.
				'pause_on_exhausted'   => true,
			],

			// Reglas de exclusión (modo Pro).
			'exclusions'  => [
				// IDs de adjuntos excluidos.
				'attachment_ids'       => [],
				// Subdirectorios de uploads excluidos (relativos).
				'directories'          => [],
				// MIME types excluidos.
				'mime_types'           => [],
			],

			// Disparadores automáticos.
			'automation'  => [
				// Optimizar automáticamente las subidas nuevas.
				'optimize_on_upload'   => true,
				// Ejecutar IA en las subidas nuevas.
				'ai_on_upload'         => false,
			],

			// Opciones avanzadas.
			'advanced'    => [
				'log_level'                 => 'info', // debug|info|warning|error.
				'log_retention_days'        => 30,
				'delete_data_on_uninstall'  => false,
				// Usar Action Scheduler si está disponible.
				'prefer_action_scheduler'   => true,
			],
		];
	}

	/**
	 * Devuelve todas las opciones, mezcladas con los valores por defecto.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION_KEY, [] );
			$stored      = is_array( $stored ) ? $stored : [];
			$this->cache = self::deep_merge( self::defaults(), $stored );
		}
		return $this->cache;
	}

	/**
	 * Obtiene un valor mediante notación de puntos (p.ej. "ai.vision_model").
	 *
	 * @param string $path    Ruta con puntos.
	 * @param mixed  $default Valor por defecto.
	 * @return mixed
	 */
	public function get( string $path, $default = null ) {
		$value = $this->all();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $value ) && array_key_exists( $segment, $value ) ) {
				$value = $value[ $segment ];
			} else {
				return $default;
			}
		}
		return $value;
	}

	/**
	 * Guarda un array completo de opciones (saneado) y refresca la cache.
	 *
	 * @param array<string, mixed> $input Entrada sin sanear.
	 * @return array<string, mixed> Opciones finales guardadas.
	 */
	public function update( array $input ): array {
		$current = $this->all();

		// Nunca permitimos que una API key vacía sobrescriba una existente
		// salvo que se solicite explícitamente borrarla.
		if ( isset( $input['ai']['api_key'] ) ) {
			$incoming = (string) $input['ai']['api_key'];
			if ( '' === $incoming ) {
				unset( $input['ai']['api_key'] );
			} else {
				$input['ai']['api_key'] = self::encrypt( $incoming );
			}
		}

		$merged    = self::deep_merge( $current, $input );
		$sanitized = $this->sanitize( $merged );

		update_option( self::OPTION_KEY, $sanitized );
		$this->cache = $sanitized;

		return $sanitized;
	}

	/**
	 * Devuelve la API Key descifrada para su uso en runtime.
	 *
	 * @return string
	 */
	public function get_api_key(): string {
		$raw = (string) $this->get( 'ai.api_key', '' );
		return '' === $raw ? '' : self::decrypt( $raw );
	}

	/**
	 * Indica si hay una API Key configurada (sin exponerla).
	 *
	 * @return bool
	 */
	public function has_api_key(): bool {
		return '' !== (string) $this->get( 'ai.api_key', '' );
	}

	/**
	 * Devuelve la configuración apta para enviar al frontend (sin secretos).
	 *
	 * @return array<string, mixed>
	 */
	public function for_frontend(): array {
		$all = $this->all();
		// Nunca exponemos la clave; solo si existe.
		$all['ai']['api_key']     = '';
		$all['ai']['has_api_key'] = $this->has_api_key();
		return $all;
	}

	/**
	 * Registra el ajuste para que se cargue tras updates.
	 *
	 * @return void
	 */
	public function refresh(): void {
		$this->cache = null;
	}

	/* ----------------------------------------------------------------
	 * Saneamiento
	 * ---------------------------------------------------------------- */

	/**
	 * Sanea recursivamente la estructura de ajustes.
	 *
	 * @param array<string, mixed> $settings Ajustes mezclados.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $settings ): array {
		$settings['mode'] = in_array( $settings['mode'] ?? 'lite', [ 'lite', 'pro' ], true )
			? $settings['mode']
			: 'lite';

		// Conversión.
		$c                       = $settings['conversion'];
		$c['target_format']      = in_array( $c['target_format'], [ 'webp', 'avif', 'auto' ], true ) ? $c['target_format'] : 'webp';
		$c['png_strategy']       = in_array( $c['png_strategy'], [ 'lossless', 'lossy' ], true ) ? $c['png_strategy'] : 'lossy';
		$c['png_to_webp']        = (bool) ( $c['png_to_webp'] ?? true );
		$c['webp_quality']       = self::clamp_int( $c['webp_quality'], 1, 100, 80 );
		$c['avif_quality']       = self::clamp_int( $c['avif_quality'], 1, 100, 60 );
		$c['jpeg_quality']       = self::clamp_int( $c['jpeg_quality'], 1, 100, 82 );
		$c['png_max_colors']     = self::clamp_int( $c['png_max_colors'], 2, 256, 256 );
		$c['max_width']          = self::clamp_int( $c['max_width'], 0, 12000, 2560 );
		$c['keep_original']      = (bool) $c['keep_original'];
		$c['sanitize_svg']       = (bool) $c['sanitize_svg'];
		$c['strip_metadata']     = (bool) $c['strip_metadata'];
		$settings['conversion']  = $c;

		// IA.
		$a                   = $settings['ai'];
		$a['enabled']        = (bool) $a['enabled'];
		$a['provider']       = in_array( $a['provider'], [ 'openai', 'fasterfy_cloud' ], true ) ? $a['provider'] : 'openai';
		$a['api_base']       = esc_url_raw( (string) $a['api_base'] );
		$a['vision_model']   = sanitize_text_field( (string) $a['vision_model'] );
		$a['temperature']    = max( 0.0, min( 2.0, (float) $a['temperature'] ) );
		$a['generate_alt']   = (bool) $a['generate_alt'];
		$a['generate_title'] = (bool) $a['generate_title'];
		$a['generate_description'] = (bool) $a['generate_description'];
		$a['hyphenate_title'] = (bool) $a['hyphenate_title'];
		$a['semantic_rename'] = (bool) $a['semantic_rename'];
		$a['language']       = sanitize_text_field( (string) $a['language'] );
		$a['alt_max_length'] = self::clamp_int( $a['alt_max_length'], 20, 300, 125 );
		// api_key ya viene cifrada o sin tocar; conservamos tal cual.
		$settings['ai']      = $a;

		// Moderación.
		$m                       = $settings['moderation'];
		$m['enabled']            = (bool) $m['enabled'];
		$m['block_generative']   = (bool) $m['block_generative'];
		$m['fallback_alt']       = sanitize_text_field( (string) $m['fallback_alt'] );
		$m['nsfw_threshold']     = max( 0.0, min( 1.0, (float) $m['nsfw_threshold'] ) );
		$settings['moderation']  = $m;

		// Throttling.
		$t                       = $settings['throttling'];
		$t['batch_size']         = self::clamp_int( $t['batch_size'], 1, 100, 10 );
		$t['max_concurrency']    = self::clamp_int( $t['max_concurrency'], 1, 50, 10 );
		$t['cooldown_seconds']   = self::clamp_int( $t['cooldown_seconds'], 0, 300, 5 );
		$settings['throttling']  = $t;

		// Cuota.
		$q                       = $settings['quota'];
		$q['plan']               = in_array( $q['plan'], [ 'starter', 'growth', 'agency' ], true ) ? $q['plan'] : 'starter';
		$q['monthly_credits']    = self::clamp_int( $q['monthly_credits'], 0, PHP_INT_MAX, 0 );
		$q['used_credits']       = self::clamp_int( $q['used_credits'], 0, PHP_INT_MAX, 0 );
		$q['pause_on_exhausted'] = (bool) $q['pause_on_exhausted'];
		$settings['quota']       = $q;

		// Exclusiones.
		$e                          = $settings['exclusions'];
		$e['attachment_ids']        = array_values( array_filter( array_map( 'absint', (array) $e['attachment_ids'] ) ) );
		$e['directories']           = array_values( array_map( 'sanitize_text_field', (array) $e['directories'] ) );
		$e['mime_types']            = array_values( array_map( 'sanitize_text_field', (array) $e['mime_types'] ) );
		$settings['exclusions']     = $e;

		// Automatización.
		$au                        = $settings['automation'];
		$au['optimize_on_upload']  = (bool) $au['optimize_on_upload'];
		$au['ai_on_upload']        = (bool) $au['ai_on_upload'];
		$settings['automation']    = $au;

		// Avanzado.
		$adv                              = $settings['advanced'];
		$adv['log_level']                 = in_array( $adv['log_level'], [ 'debug', 'info', 'warning', 'error' ], true ) ? $adv['log_level'] : 'info';
		$adv['log_retention_days']        = self::clamp_int( $adv['log_retention_days'], 1, 365, 30 );
		$adv['delete_data_on_uninstall']  = (bool) $adv['delete_data_on_uninstall'];
		$adv['prefer_action_scheduler']   = (bool) $adv['prefer_action_scheduler'];
		$settings['advanced']             = $adv;

		return $settings;
	}

	/* ----------------------------------------------------------------
	 * Utilidades
	 * ---------------------------------------------------------------- */

	/**
	 * Mezcla profunda de arrays asociativos (los del input ganan).
	 *
	 * @param array<string, mixed> $base     Base.
	 * @param array<string, mixed> $override Sobrescritura.
	 * @return array<string, mixed>
	 */
	private static function deep_merge( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) && self::is_assoc( $base[ $key ] ) ) {
				$base[ $key ] = self::deep_merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}

	/**
	 * Comprueba si un array es asociativo.
	 *
	 * @param array<mixed> $arr Array.
	 * @return bool
	 */
	private static function is_assoc( array $arr ): bool {
		if ( [] === $arr ) {
			return true;
		}
		return array_keys( $arr ) !== range( 0, count( $arr ) - 1 );
	}

	/**
	 * Acota un entero a un rango.
	 *
	 * @param mixed $value   Valor.
	 * @param int   $min     Mínimo.
	 * @param int   $max     Máximo.
	 * @param int   $default Valor por defecto si no es numérico.
	 * @return int
	 */
	private static function clamp_int( $value, int $min, int $max, int $default ): int {
		if ( ! is_numeric( $value ) ) {
			return $default;
		}
		return (int) max( $min, min( $max, (int) $value ) );
	}

	/* ----------------------------------------------------------------
	 * Cifrado de secretos en reposo
	 * ---------------------------------------------------------------- */

	/**
	 * Cifra un valor sensible usando las llaves de seguridad de WP.
	 *
	 * @param string $plaintext Texto plano.
	 * @return string Cadena cifrada (prefijo "ff1:" + base64).
	 */
	public static function encrypt( string $plaintext ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// Degradación: codifica para no almacenar en claro evidente.
			return 'ffb:' . base64_encode( $plaintext ); // phpcs:ignore
		}
		$key   = self::cipher_key();
		$iv    = random_bytes( 16 );
		$cph   = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return 'ff1:' . base64_encode( $iv . $cph ); // phpcs:ignore
	}

	/**
	 * Descifra un valor previamente cifrado por self::encrypt().
	 *
	 * @param string $ciphertext Cadena cifrada.
	 * @return string
	 */
	public static function decrypt( string $ciphertext ): string {
		if ( str_starts_with( $ciphertext, 'ffb:' ) ) {
			return (string) base64_decode( substr( $ciphertext, 4 ), true ); // phpcs:ignore
		}
		if ( ! str_starts_with( $ciphertext, 'ff1:' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return $ciphertext; // No cifrado o sin soporte.
		}
		$raw = base64_decode( substr( $ciphertext, 4 ), true ); // phpcs:ignore
		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}
		$iv  = substr( $raw, 0, 16 );
		$cph = substr( $raw, 16 );
		$out = openssl_decrypt( $cph, 'aes-256-cbc', self::cipher_key(), OPENSSL_RAW_DATA, $iv );
		return false === $out ? '' : $out;
	}

	/**
	 * Deriva una clave de cifrado de 256 bits desde las llaves de WordPress.
	 *
	 * @return string
	 */
	private static function cipher_key(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' )
			. ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' )
			. ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : 'fasterfy' );
		return hash( 'sha256', 'fasterfy|' . $material, true );
	}
}
