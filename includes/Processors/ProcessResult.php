<?php
/**
 * Resultado del procesamiento de un activo.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Processors;

defined( 'ABSPATH' ) || exit;

/**
 * Value object que describe el resultado de optimizar un archivo.
 */
final class ProcessResult {

	public bool $success = false;
	public bool $skipped = false;
	public string $message = '';

	/** Ruta absoluta del archivo original. */
	public string $original_path = '';

	/** Ruta absoluta del archivo resultante (puede ser igual al original). */
	public string $output_path = '';

	/** MIME de origen. */
	public string $source_mime = '';

	/** MIME del resultado. */
	public string $output_mime = '';

	/** Tamaño original en bytes. */
	public int $original_size = 0;

	/** Tamaño final en bytes. */
	public int $output_size = 0;

	/** Indica si el archivo fue reemplazado (mutación nativa). */
	public bool $replaced = false;

	/** Indica si cambió la extensión/MIME (afecta a rutas en BD). */
	public bool $format_changed = false;

	/** Datos adicionales (motor usado, dimensiones, etc.). */
	public array $meta = [];

	/**
	 * Crea un resultado de éxito.
	 *
	 * @param array<string, mixed> $props Propiedades.
	 * @return self
	 */
	public static function ok( array $props = [] ): self {
		$r          = new self();
		$r->success = true;
		foreach ( $props as $k => $v ) {
			if ( property_exists( $r, $k ) ) {
				$r->{$k} = $v;
			}
		}
		return $r;
	}

	/**
	 * Crea un resultado de "omitido".
	 *
	 * @param string $reason Motivo.
	 * @return self
	 */
	public static function skip( string $reason ): self {
		$r          = new self();
		$r->success = true;
		$r->skipped = true;
		$r->message = $reason;
		return $r;
	}

	/**
	 * Crea un resultado de error.
	 *
	 * @param string $message Mensaje.
	 * @return self
	 */
	public static function fail( string $message ): self {
		$r          = new self();
		$r->success = false;
		$r->message = $message;
		return $r;
	}

	/**
	 * Bytes ahorrados.
	 *
	 * @return int
	 */
	public function bytes_saved(): int {
		return max( 0, $this->original_size - $this->output_size );
	}

	/**
	 * Porcentaje de ahorro (0-100).
	 *
	 * @return float
	 */
	public function savings_percent(): float {
		if ( $this->original_size <= 0 ) {
			return 0.0;
		}
		return round( ( $this->bytes_saved() / $this->original_size ) * 100, 2 );
	}

	/**
	 * Serializa a array para la API/UI.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return [
			'success'         => $this->success,
			'skipped'         => $this->skipped,
			'message'         => $this->message,
			'source_mime'     => $this->source_mime,
			'output_mime'     => $this->output_mime,
			'original_size'   => $this->original_size,
			'output_size'     => $this->output_size,
			'bytes_saved'     => $this->bytes_saved(),
			'savings_percent' => $this->savings_percent(),
			'replaced'        => $this->replaced,
			'format_changed'  => $this->format_changed,
			'meta'            => $this->meta,
		];
	}
}
