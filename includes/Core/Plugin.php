<?php
/**
 * Plugin bootstrap.
 *
 * @package AdminSuite
 */

declare( strict_types = 1 );

namespace AdminSuite\Core;

use AdminSuite\Api\DashboardController;
use AdminSuite\Api\MenuController;
use AdminSuite\Api\SearchController;
use AdminSuite\Api\SettingsController;
use AdminSuite\Api\UserPreferencesController;
use AdminSuite\Compatibility\OutputBuffer;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every service of the plugin onto WordPress hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Guards against double booting.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Private constructor: use Plugin::instance().
	 */
	private function __construct() {
	}

	/**
	 * Retrieve the shared instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register every hook owned by the plugin.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		add_action( 'init', array( $this, 'loadTextdomain' ) );

		$controllers = array(
			MenuController::class            => new MenuController(),
			DashboardController::class       => new DashboardController(),
			SearchController::class          => new SearchController(),
			SettingsController::class        => new SettingsController(),
			UserPreferencesController::class => new UserPreferencesController(),
		);

		foreach ( $controllers as $controller ) {
			add_action( 'rest_api_init', array( $controller, 'registerRoutes' ) );
		}

		( new AdminShell() )->register();
		( new AccountMenu() )->register();
		( new Enqueue() )->register();
		( new OutputBuffer() )->register();
	}

	/**
	 * Load translations.
	 */
	public function loadTextdomain(): void {
		load_plugin_textdomain(
			'admin-suite',
			false,
			dirname( plugin_basename( ADMIN_SUITE_FILE ) ) . '/languages'
		);
	}
}
