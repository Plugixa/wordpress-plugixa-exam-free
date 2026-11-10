<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core;

use PlugixaExam\Core\Admin\Admin;
use PlugixaExam\Core\Admin\Assets;
use PlugixaExam\Core\Http\AppController;
use PlugixaExam\Core\Http\BootstrapController;
use PlugixaExam\Core\Http\PagesController;
use PlugixaExam\Core\Http\SettingsController;
use PlugixaExam\Core\Modules\ModuleRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The composition root.
 *
 * Builds the container, discovers modules, and wires the handful of things Core owns
 * directly. Nothing else in the plugin news up a service.
 */
final class Plugin {

	private static ?self $instance = null;

	private readonly Container $container;

	private readonly ModuleRegistry $modules;

	private bool $booted = false;

	private function __construct() {
		$this->container = new Container();
		$this->container->instance( Container::class, $this->container );

		$this->modules = new ModuleRegistry( $this->container );
		$this->container->instance( ModuleRegistry::class, $this->modules );
	}

	public static function instance(): self {
		self::$instance ??= new self();

		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	public function modules(): ModuleRegistry {
		return $this->modules;
	}

	/**
	 * Start the plugin. Called once, on `plugins_loaded`.
	 *
	 * Guarded rather than trusting the caller: `plugins_loaded` fires once, but the test
	 * suite boots the plugin explicitly and a second boot would register every hook twice
	 * — which is invisible until something that must happen once happens twice.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		// First, so that anything registering a hook below is registering it in a world
		// where the catalogues are already scheduled to load. Costs one `add_action`.
		I18n::register_hooks();

		// Before any module: a route's permission check asks WordPress, and this is what
		// lets WordPress answer.
		\PlugixaExam\Core\Security\Permissions::register_hooks();

		// WordPress image captions inserted by the editors' "Add media" button, as <figure>.
		\PlugixaExam\Core\Content\Captions::register_hooks();

		$this->modules->discover();
		$this->modules->register_hooks();

		( new Admin( $this->container ) )->register_hooks();
		( new Assets() )->register_hooks();

		/*
		 * BEFORE the router, because it answers `plugixa_exam_url_languages`, which the router
		 * reads while building its rules on `init`. It is registered in the FREE build on
		 * purpose: a Polylang site prefixes every URL, and a plugin that 404s half its own
		 * address space is not a premium feature to withhold. Translating what is AT those
		 * URLs is the premium half, and lives in `Pro\Modules\Multilingual`.
		 */
		$this->container->get( \PlugixaExam\Core\Frontend\Languages::class )->register_hooks();

		// The front end. Registered after modules so a module's `plugixa_exam_url_base`
		// filter is already in place when the router builds its rewrite rules on `init`.
		/*
		 * BEFORE the router, because it owns the `pages` setting the router reads when it
		 * builds a link — and the default for that key has to exist before anything asks.
		 */
		$this->container->get( \PlugixaExam\Core\Frontend\Pages::class )->register_hooks();
		$this->container->get( \PlugixaExam\Core\Frontend\Router::class )->register_hooks();
		$this->container->get( \PlugixaExam\Core\Frontend\AppAssets::class )->register_hooks();
		$this->container->get( \PlugixaExam\Core\Frontend\FormHandler::class )->register_hooks();
		$this->container->get( \PlugixaExam\Core\Frontend\Shortcodes::class )->register_hooks();
		$this->container->get( \PlugixaExam\Core\Frontend\Blocks::class )->register_hooks();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Other plugins' site-wide REST checks that misread this plugin's `id` parameter.
		\PlugixaExam\Core\Http\ForeignRestChecks::register_hooks();

		/*
		 * Schema drift guard.
		 *
		 * `register_activation_hook` does not fire when a plugin is updated by copying files
		 * over the old ones — which is how every developer and every FTP-based update works.
		 * Without this, a release that adds a column ships to a site that never gets it, and
		 * the failure surfaces as a SQL error in whichever feature reads it first.
		 *
		 * COMPARED AGAINST A SIGNATURE, NOT A VERSION. Swapping the free build for the
		 * premium one changes the modules on disk and nothing else — same version, no
		 * activation hook — and a version comparison sees no reason to migrate. See
		 * {@see Activator::signature()} for the seventeen tables that cost.
		 *
		 * Runs here rather than on `admin_init` because a premium module's table can be
		 * needed by a front-end request just as easily: a certificate verification page or
		 * an LTI launch reaches its table with no administrator anywhere near the site.
		 *
		 * `due()` is what keeps a migration that CANNOT finish from being run on every
		 * request: after a failure it waits, and `Admin\MigrationNotice` tells an
		 * administrator what the database refused.
		 */
		if ( get_option( 'plugixa_exam_db_version' ) !== Activator::signature( array_keys( $this->modules->all() ) ) && Activator::due() ) {
			Activator::migrate();
		}

		/*
		 * Hooked on every request, not only inside wp-admin. The two hooks fire nowhere
		 * else, so it costs nothing — and a line that runs only in wp-admin is a line no
		 * test and no WP-CLI command ever executes. The first version of this one named the
		 * class wrongly and was a fatal error on every admin screen, with the whole suite
		 * green; it was found by activating the plugin over HTTP on a clean site.
		 */
		\PlugixaExam\Core\Admin\MigrationNotice::register_hooks();

		/**
		 * Fires once the plugin is fully booted.
		 *
		 * @param Container $container The service container.
		 */
		do_action( 'plugixa_exam_loaded', $this->container );
	}

	/**
	 * Register Core's REST routes, then every module's.
	 *
	 * Core goes first so that a module cannot accidentally shadow `/bootstrap` or
	 * `/settings` — `register_rest_route` lets the last registration win, and a module
	 * quietly replacing the endpoint the SPA starts up through would be a confusing
	 * afternoon.
	 */
	public function register_routes(): void {
		( new BootstrapController( $this->container ) )->register_routes();
		( new SettingsController( $this->container->get( Settings::class ) ) )->register_routes();
		( new AppController( $this->container->get( Modules\AppRegistry::class ) ) )->register_routes();
		( new PagesController( $this->container->get( Settings::class ) ) )->register_routes();

		$this->modules->register_routes();

		/**
		 * Register additional REST routes.
		 *
		 * @param string    $namespace The plugin's REST namespace.
		 * @param Container $container The service container.
		 */
		do_action( 'plugixa_exam_register_rest_routes', 'plugixa-exam/v1', $this->container );
	}
}
