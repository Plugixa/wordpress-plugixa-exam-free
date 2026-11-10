<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Modules;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Database\MigrationFailed;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds modules on disk and boots them.
 *
 * THE GLOB IS THE FREE/PREMIUM SEAM, not merely a convenience.
 *
 * `scan()` looks in two roots. The free ZIP has no `pro/` directory — Freemius strips it,
 * driven by the `@fs_premium_only` header — so `is_dir()` is false, the glob returns
 * nothing, and premium modules DO NOT EXIST in that build. They are not present and
 * disabled, not present and licence-checked; they are absent. That distinction is the whole
 * point: WordPress.org rejects a plugin that ships locked premium code as trialware
 * (Guideline 5), and no amount of careful gating changes what is in the ZIP.
 *
 * The corollary is that there is no licence check anywhere in this plugin, and there must
 * never be one. Code that is not shipped cannot be locked.
 */
final class ModuleRegistry {

	/**
	 * Booted modules, keyed by id.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $modules = array();

	/**
	 * Whether discover() has run.
	 */
	private bool $discovered = false;

	/**
	 * @param Container $container The container modules are resolved from.
	 */
	public function __construct( private readonly Container $container ) {}

	/**
	 * Instantiate every module found on disk and let it bind its services.
	 *
	 * Services first, hooks later, in two separate passes — see {@see register_hooks()}.
	 */
	public function discover(): void {
		if ( $this->discovered ) {
			return;
		}

		$this->discovered = true;

		$classes = array_merge(
			$this->scan( PLUGIXA_EXAM_PATH . 'includes/Modules', 'PlugixaExam\\Modules\\' ),
			$this->scan( PLUGIXA_EXAM_PATH . 'pro/includes/Modules', 'PlugixaExam\\Pro\\Modules\\' )
		);

		/**
		 * The public door: an add-on plugin contributes a module class without living
		 * inside this plugin.
		 *
		 * @param string[] $classes Fully-qualified module class names.
		 */
		$classes = (array) apply_filters( 'plugixa_exam_modules', $classes );

		foreach ( $classes as $class_name ) {
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}

			if ( ! is_subclass_of( $class_name, ModuleInterface::class ) ) {
				continue;
			}

			try {
				$module = $this->container->get( $class_name );
				$module->register_services( $this->container );
				$this->modules[ $module->id() ] = $module;
			} catch ( Throwable $e ) {
				// A broken module must not take the plugin down. An exam site whose
				// Certificates module fatals should still be able to run its exams.
				$this->log_failure( $class_name, $e );
			}
		}
	}

	/**
	 * Register every module's hooks.
	 *
	 * Separate from `discover()` because a module's hooks may resolve a service another
	 * module binds, and binding order is filesystem order — which is to say, arbitrary.
	 * Two passes make the order irrelevant.
	 */
	public function register_hooks(): void {
		/*
		 * Priority 5, so that every module's own `strings()` — added at the default 10 by
		 * the loop below — runs afterwards and wins. This only ever fills a gap. See
		 * `app_labels()`.
		 */
		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'app_labels' ), 5 );

		foreach ( $this->modules as $id => $module ) {
			if ( ! $this->enabled( $id ) ) {
				continue;
			}

			try {
				$module->register_hooks( $this->container );
			} catch ( Throwable $e ) {
				$this->log_failure( $module::class, $e );
			}
		}
	}

	/**
	 * Supply a display name for the apps whose modules are switched off.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE SWITCH THAT TURNED A FEATURE OFF ALSO TOOK AWAY ITS NAME.
	 *
	 * A module contributes its own label through `plugixa_exam_admin_i18n_strings`, and
	 * that filter is added inside `register_hooks()` — which the loop above skips for a
	 * disabled module. So the moment an app was switched off, the App Manager rendered its
	 * row as `advancedtypes`, `api_tokens`, `customfields`: a raw slug, on the one screen
	 * whose entire job is to offer the feature back. `t( id, id )` falls back to the key,
	 * so nothing errored and nothing logged.
	 *
	 * ⚠️ THIS IS NOT A HOLE IN THE GATE, AND THE DISTINCTION IS THE WHOLE DESIGN.
	 *
	 * The gate stops a disabled module BEHAVING — no listeners, no cron, no REST routes,
	 * because a switched-off Webhooks module was still POSTing to other people's servers.
	 * `strings()` is a pure array of `__()` calls: no listener, no endpoint, no query. It is
	 * read here exactly as `AppRegistry::registry()` already reads `app()` on every module
	 * regardless of state, from the same ungated collection `discover()` builds.
	 *
	 * ONLY THE LABEL IS TAKEN, never the module's whole string set. A disabled module
	 * renders no screens, so its other seventy strings would be weight on every admin page
	 * in exchange for nothing.
	 *
	 * ENABLED MODULES ARE SKIPPED because their own filter supplies the label a moment
	 * later, and calling `strings()` on all thirty-seven would double that work on every
	 * admin page load to change nothing.
	 *
	 * @param array<string, string> $strings Accumulated strings.
	 *
	 * @return array<string, string>
	 */
	public function app_labels( array $strings ): array {
		foreach ( $this->modules as $id => $module ) {
			if ( $this->enabled( $id ) ) {
				continue;
			}

			/*
			 * Duck-typed rather than widened into `ModuleInterface`. Every module already
			 * has this method — it is how all of them publish their labels — and requiring
			 * it would make thirty-seven modules declare something they already declare,
			 * for the benefit of a caller that can simply ask.
			 */
			if ( ! method_exists( $module, 'strings' ) ) {
				continue;
			}

			try {
				$own = (array) $module->strings( array() );
			} catch ( Throwable $e ) {
				// Fails open like everything else here: a module with a broken `strings()`
				// costs its own label, not the whole App Manager screen.
				continue;
			}

			if ( isset( $own[ $id ] ) && '' !== trim( (string) $own[ $id ] ) ) {
				$strings[ $id ] = (string) $own[ $id ];
			}
		}

		return $strings;
	}

	/**
	 * Register every module's REST routes. Called on `rest_api_init`.
	 */
	public function register_routes(): void {
		foreach ( $this->modules as $id => $module ) {
			if ( ! $this->enabled( $id ) ) {
				continue;
			}

			try {
				$module->register_routes( $this->container );
			} catch ( Throwable $e ) {
				$this->log_failure( $module::class, $e );
			}
		}
	}

	/**
	 * Whether an app is switched on, for the two passes that respect the App Manager.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THIS IS THE "REQUEST GUARD" {@see AppRegistry} HAS ALWAYS CLAIMED TO HAVE.
	 *
	 * That class documents three properties, the third being "ONE REGISTRY, TWO CONSUMERS
	 * — the SPA reads this through Config and the request guard reads it directly, so the
	 * client and the server cannot come to different conclusions about the same feature."
	 * There was no such guard. `is_enabled()` had ZERO callers, and these two loops ran
	 * over every module unconditionally.
	 *
	 * So switching an app off hid it in the admin screens and changed nothing on the
	 * server. Measured before this fix, with the flag persisted OFF and a fresh request:
	 * `/certificates`, `/webhooks`, `/cohorts` and `/monitor/grants` all answered 200, and
	 * a disabled Webhooks module still had all three of its event listeners attached — so a
	 * site that had switched Webhooks off went on POSTing attempt and certificate data to
	 * whatever external endpoints were registered.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * ONLY HOOKS AND ROUTES ARE GATED. `discover()` still binds every module's services and
	 * `migrate()` still runs every module's schema, both deliberately:
	 *
	 * - SERVICES, because the container must still resolve a disabled module's classes.
	 *   `AppRegistry` itself is built from `all()`, the App Manager screen has to list what
	 *   it is offering to switch back on, and another module holding a reference must not
	 *   fatal because somebody flipped a toggle.
	 * - TABLES, because a disabled module is not an uninstalled one. Certificates and
	 *   Monitoring own tables holding real records, and only `Core\Uninstaller` may drop
	 *   them — switching a feature off for a term must not destroy what it recorded.
	 *
	 * FAILS OPEN ON ANY ERROR, which is `AppRegistry`'s first stated property and matters
	 * more here than anywhere else: resolving the flags needs `Settings`, which needs the
	 * database. A site whose options table is briefly unreachable should run with every
	 * feature on, not black out entirely.
	 *
	 * @param string $id Module id, which is also its app id.
	 */
	private function enabled( string $id ): bool {
		try {
			return $this->container->get( AppRegistry::class )->is_enabled( $id );
		} catch ( Throwable $e ) {
			return true;
		}
	}

	/**
	 * Run every module's migration, and say which did not finish.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * A FAILURE IS CAUGHT SO THE OTHER MODULES STILL MIGRATE, AND THEN IT IS REPORTED.
	 *
	 * This used to catch every exception and return nothing. The caller recorded the
	 * database version as up to date whatever had happened, so a module whose tables could
	 * not be created was marked done and never tried again — and outside WP_DEBUG the
	 * exception was not even logged. WordPress.org's review of 1.1.1 said exactly that.
	 *
	 * Catching is still right: an exam site whose Certificates tables cannot be made should
	 * get its attempts tables. Saying nothing was the fault. The caller is handed every
	 * failure and must not record the migration as complete while there is one.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @return array<string, array{tables: string[], reason: string}> Module id => what went
	 *                                                                wrong. Empty when
	 *                                                                every module migrated.
	 */
	public function migrate(): array {
		$failures = array();

		foreach ( $this->modules as $module ) {
			try {
				$module->migrate();
			} catch ( MigrationFailed $e ) {
				$failures[ $module->id() ] = array(
					'tables' => $e->tables(),
					'reason' => $e->reason(),
				);

				$this->log_failure( $module::class, $e, 'migrate' );
			} catch ( Throwable $e ) {
				// Not a table that failed to appear, but a migration that did not finish
				// all the same. The message is the developer's; it is shown as it is.
				$failures[ $module->id() ] = array(
					'tables' => array(),
					'reason' => $e->getMessage(),
				);

				$this->log_failure( $module::class, $e, 'migrate' );
			}
		}

		return $failures;
	}

	/**
	 * The merged capability map across all modules.
	 *
	 * @return array<string, string[]>
	 */
	public function capabilities(): array {
		$caps = array();

		foreach ( $this->modules as $module ) {
			foreach ( $module->capabilities() as $cap => $roles ) {
				$caps[ $cap ] = array_values(
					array_unique( array_merge( $caps[ $cap ] ?? array(), $roles ) )
				);
			}
		}

		return $caps;
	}

	/**
	 * @return array<string, ModuleInterface>
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * @param string $id Module id.
	 */
	public function get( string $id ): ?ModuleInterface {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * Module class names in a directory.
	 *
	 * The folder name IS the namespace segment, so `includes/Modules/Questions/Module.php`
	 * must declare `PlugixaExam\Modules\Questions\Module`. There is no manifest file and no
	 * registration call; the directory layout is the registration.
	 *
	 * @param string $dir              Absolute path to a Modules directory.
	 * @param string $namespace_prefix Namespace the directory maps to.
	 *
	 * @return string[]
	 */
	private function scan( string $dir, string $namespace_prefix ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$classes = array();

		foreach ( (array) glob( $dir . '/*/Module.php' ) as $file ) {
			$name      = basename( dirname( (string) $file ) );
			$classes[] = $namespace_prefix . $name . '\\Module';
		}

		return $classes;
	}

	/**
	 * Record a module failure without interrupting the request.
	 *
	 * @param string    $class_name The module that failed.
	 * @param Throwable $e          What went wrong.
	 * @param string    $doing      What it was doing: "boot" or "migrate".
	 */
	private function log_failure( string $class_name, Throwable $e, string $doing = 'boot' ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG-gated diagnostic; a failed migration is also reported to the administrator by Admin\MigrationNotice.
		error_log(
			sprintf(
				'[plugixa-exam] Module %s failed to %s: %s',
				$class_name,
				$doing,
				$e->getMessage()
			)
		);
	}
}
