<?php
/**
 * Núcleo del plugin: orquesta servicios, dependencias y hooks.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy;

use FasterFy\AI\AIManager;
use FasterFy\Admin\Admin;
use FasterFy\Media\BackupManager;
use FasterFy\Media\MediaScanner;
use FasterFy\Media\UploadInterceptor;
use FasterFy\Processors\ProcessorFactory;
use FasterFy\Queue\QueueManager;
use FasterFy\Rest\RestController;

defined( 'ABSPATH' ) || exit;

/**
 * Contenedor de servicios y bootstrap principal.
 */
final class Core {

	/**
	 * Instancia única (singleton).
	 *
	 * @var Core|null
	 */
	private static ?Core $instance = null;

	/**
	 * Servicios registrados.
	 *
	 * @var array<string, object>
	 */
	private array $services = [];

	/**
	 * Indica si el plugin ya arrancó.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Constructor privado (singleton).
	 */
	private function __construct() {}

	/**
	 * Devuelve la instancia única.
	 *
	 * @return Core
	 */
	public static function instance(): Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Arranca el plugin: registra servicios y hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->load_textdomain();
		$this->register_services();
		$this->register_hooks();

		/**
		 * Se dispara cuando FasterFy ha terminado de arrancar.
		 *
		 * @param Core $core Instancia del núcleo.
		 */
		do_action( 'fasterfy_booted', $this );
	}

	/**
	 * Carga el dominio de traducciones.
	 *
	 * @return void
	 */
	private function load_textdomain(): void {
		load_plugin_textdomain( 'fasterfy', false, dirname( FASTERFY_BASENAME ) . '/languages' );
	}

	/**
	 * Instancia y registra los servicios del contenedor.
	 *
	 * @return void
	 */
	private function register_services(): void {
		$settings = new Settings();
		$logger   = new Logger( $settings );

		$this->services['settings']  = $settings;
		$this->services['logger']    = $logger;
		$this->services['processor'] = new ProcessorFactory( $settings, $logger );
		$this->services['backup']    = new BackupManager( $settings, $logger );
		$this->services['ai']        = new AIManager( $settings, $logger );
		$this->services['scanner']   = new MediaScanner( $settings );
		$this->services['queue']     = new QueueManager(
			$settings,
			$logger,
			$this->services['scanner'],
			$this->services['processor'],
			$this->services['backup'],
			$this->services['ai']
		);
		$this->services['rest']     = new RestController( $this );
		$this->services['upload']   = new UploadInterceptor( $this );
		$this->services['admin']    = new Admin( $this );
	}

	/**
	 * Registra los hooks que conectan los servicios con WordPress.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		foreach ( $this->services as $service ) {
			if ( $service instanceof Contracts\Bootable ) {
				$service->register_hooks();
			}
		}
	}

	/**
	 * Recupera un servicio por su clave.
	 *
	 * @param string $key Clave del servicio.
	 * @return object|null
	 */
	public function get( string $key ): ?object {
		return $this->services[ $key ] ?? null;
	}

	/* ----- Accesores tipados de conveniencia ----- */

	public function settings(): Settings {
		/** @var Settings */
		return $this->services['settings'];
	}

	public function logger(): Logger {
		/** @var Logger */
		return $this->services['logger'];
	}

	public function processor(): ProcessorFactory {
		/** @var ProcessorFactory */
		return $this->services['processor'];
	}

	public function backup(): BackupManager {
		/** @var BackupManager */
		return $this->services['backup'];
	}

	public function ai(): AIManager {
		/** @var AIManager */
		return $this->services['ai'];
	}

	public function scanner(): MediaScanner {
		/** @var MediaScanner */
		return $this->services['scanner'];
	}

	public function queue(): QueueManager {
		/** @var QueueManager */
		return $this->services['queue'];
	}
}
