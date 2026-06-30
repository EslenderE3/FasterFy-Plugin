<?php
/**
 * Capa de administración: registra el menú, encola los assets del panel
 * SPA y añade enlaces/acciones contextuales.
 *
 * @package FasterFy
 */

declare( strict_types=1 );

namespace FasterFy\Admin;

use FasterFy\Contracts\Bootable;
use FasterFy\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Gestiona la interfaz de administración de FasterFy.
 */
final class Admin implements Bootable {

	public const MENU_SLUG = 'fasterfy';

	/**
	 * Núcleo.
	 *
	 * @var Core
	 */
	private Core $core;

	/**
	 * Sufijo del hook de la página del plugin.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param Core $core Núcleo.
	 */
	public function __construct( Core $core ) {
		$this->core = $core;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'plugin_action_links_' . FASTERFY_BASENAME, [ $this, 'action_links' ] );
		// Acción rápida en la fila de cada adjunto del listado de medios.
		add_filter( 'media_row_actions', [ $this, 'media_row_actions' ], 10, 2 );
	}

	/**
	 * Registra el menú principal del plugin.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		$this->hook_suffix = (string) add_menu_page(
			__( 'FasterFy', 'fasterfy' ),
			__( 'FasterFy', 'fasterfy' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_app' ],
			$this->menu_icon(),
			81
		);
	}

	/**
	 * Ícono del menú (escudo de marca FasterFy) como data URI SVG.
	 *
	 * @return string
	 */
	private function menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48">'
			. '<path d="M24 2 44 13 44 35 24 46 4 35 4 13Z" fill="#33EE33"/>'
			. '<path d="M26 10 15 27 22 27 19 39 33 21 25 21Z" fill="#1F1F1F"/>'
			. '</svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Renderiza el contenedor de la SPA.
	 *
	 * @return void
	 */
	public function render_app(): void {
		$view = FASTERFY_PATH . 'admin/views/app.php';
		if ( is_readable( $view ) ) {
			require $view;
		}
	}

	/**
	 * Encola CSS/JS solo en la página del plugin.
	 *
	 * @param string $hook_suffix Hook actual.
	 * @return void
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'fasterfy-admin',
			FASTERFY_URL . 'admin/css/fasterfy-admin.css',
			[],
			FASTERFY_VERSION
		);

		wp_enqueue_script(
			'fasterfy-admin',
			FASTERFY_URL . 'admin/js/fasterfy-admin.js',
			[ 'wp-i18n' ],
			FASTERFY_VERSION,
			true
		);

		wp_localize_script(
			'fasterfy-admin',
			'FasterFyData',
			[
				'restUrl'      => esc_url_raw( rest_url( 'fasterfy/v1' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
				'settings'     => $this->core->settings()->for_frontend(),
				'capabilities' => [
					'image'        => $this->core->processor()->capabilities(),
					'queue_engine' => $this->core->queue()->engine_label(),
					'supported'    => $this->core->processor()->supported_mimes(),
				],
				'adminUrl'     => esc_url_raw( admin_url() ),
				'version'      => FASTERFY_VERSION,
			]
		);
	}

	/**
	 * Añade enlace "Ajustes" en la lista de plugins.
	 *
	 * @param string[] $links Enlaces.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		$url   = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$extra = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Panel', 'fasterfy' ) . '</a>';
		array_unshift( $links, $extra );
		return $links;
	}

	/**
	 * Añade una acción "Optimizar con FasterFy" a cada fila de medios.
	 *
	 * @param array<string, string> $actions Acciones.
	 * @param \WP_Post               $post    Adjunto.
	 * @return array<string, string>
	 */
	public function media_row_actions( array $actions, \WP_Post $post ): array {
		if ( 'attachment' !== $post->post_type ) {
			return $actions;
		}
		if ( ! $this->core->processor()->supports_mime( (string) $post->post_mime_type ) ) {
			return $actions;
		}
		$url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '#/media?focus=' . $post->ID );
		$actions['fasterfy'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Optimizar (FasterFy)', 'fasterfy' ) . '</a>';
		return $actions;
	}
}
