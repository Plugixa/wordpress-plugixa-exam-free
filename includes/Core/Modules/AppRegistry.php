<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Modules;

use PlugixaExam\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The App Manager: which functional subsystems are switched on.
 *
 * Ported from NextExams' `AppHelper`, with one change that is the reason this class is
 * worth having. Upstream, the registry was a hand-written `const APPS` array that had to be
 * kept in step with the controllers it gated — and a controller absent from both that map
 * and the CORE list was silently ungated. Here the registry is DERIVED: every module
 * declares its own {@see ModuleInterface::app()} metadata, and this class assembles them.
 * There is one list, and it is the filesystem.
 *
 * THREE PROPERTIES THIS MUST KEEP.
 *
 *  1. FAIL OPEN. A missing settings row falls back to the module's declared default; an
 *     unknown key is enabled. A partial upgrade or a half-applied migration must never
 *     black out the product — an exam site that cannot run its exams because a settings
 *     row is missing is a worse outcome than one running a feature its owner had disabled.
 *
 *  2. CORE CANNOT BE DISABLED. Core modules have no setting row and `is_enabled()`
 *     short-circuits on them, so a hand-crafted POST cannot switch off the question bank.
 *
 *  3. ONE REGISTRY, TWO CONSUMERS. The SPA reads this through
 *     {@see \PlugixaExam\Core\App\Config} and the request guard reads it directly, so the
 *     client and the server cannot come to different conclusions about the same feature.
 */
final class AppRegistry {

	/**
	 * Category vocabulary, in display order.
	 *
	 * `core` leads because the App Manager renders it as a separate locked section above
	 * the category tabs.
	 *
	 * @var string[]
	 */
	public const CATEGORIES = array( 'core', 'learner', 'authoring', 'insight', 'integrations', 'admin' );

	/**
	 * Resolved enabled-state per app id, for the life of the request.
	 *
	 * @var array<string, bool>|null
	 */
	private ?array $resolved = null;

	/**
	 * @param ModuleRegistry $modules  Where the app declarations come from.
	 * @param Settings       $settings Where the flags are stored.
	 */
	public function __construct(
		private readonly ModuleRegistry $modules,
		private readonly Settings $settings
	) {}

	/**
	 * The registry: app id => declaration, for every module present on disk.
	 *
	 * A premium module absent from a free build contributes nothing here, exactly as a
	 * disabled module contributes nothing to the UI. The App Manager and the free/premium
	 * seam are the same mechanism.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function registry(): array {
		$registry = array();

		foreach ( $this->modules->all() as $id => $module ) {
			$app = $module->app();

			$registry[ $id ] = array(
				'category' => $app['category'] ?? 'integrations',
				'core'     => (bool) ( $app['core'] ?? false ),
				'setting'  => $app['setting'] ?? null,
				'default'  => (bool) ( $app['default'] ?? true ),
				'deps'     => array_values( (array) ( $app['deps'] ?? array() ) ),
				// Question formats this module owns, for the App Manager's warning. Empty
				// for all but the five format-owning modules (Cloze and ImageChoice in the
				// free build; AdvancedTypes, VisualTypes and FileAnswers in premium).
				'types'    => array_values( array_filter( array_map( 'strval', (array) ( $app['types'] ?? array() ) ) ) ),
			);
		}

		return $registry;
	}

	/**
	 * Whether an app is *effectively* enabled — its own flag is on, and every app it
	 * depends on is effectively enabled too.
	 *
	 * @param string $id App id.
	 */
	public function is_enabled( string $id ): bool {
		$map = $this->resolve();

		// Fail open on an unknown key. See the class docblock.
		return $map[ $id ] ?? true;
	}

	/**
	 * Every app's effective state, for injection into the SPA.
	 *
	 * @return array<string, bool>
	 */
	public function enabled_map(): array {
		return $this->resolve();
	}

	/**
	 * App ids an administrator is allowed to toggle.
	 *
	 * Derived from the registry rather than written out, so a module added later cannot
	 * accidentally become un-toggleable — or, worse, toggleable when it is core.
	 *
	 * @return string[]
	 */
	public function toggleable(): array {
		return array_keys(
			array_filter(
				$this->registry(),
				static fn( array $app ): bool => ! $app['core'] && null !== $app['setting']
			)
		);
	}

	/**
	 * Switch an app on or off.
	 *
	 * @param string $id      App id.
	 * @param bool   $enabled Desired state.
	 *
	 * @return bool Whether the change was applied.
	 */
	public function set( string $id, bool $enabled ): bool {
		$registry = $this->registry();

		if ( ! isset( $registry[ $id ] ) || $registry[ $id ]['core'] || null === $registry[ $id ]['setting'] ) {
			return false;
		}

		$this->settings->set( $registry[ $id ]['setting'], $enabled ? '1' : '0', 'boolean' );
		$this->resolved = null;

		return true;
	}

	/**
	 * Resolve every app's own flag, then fold in the dependency graph.
	 *
	 * @return array<string, bool>
	 */
	private function resolve(): array {
		if ( null !== $this->resolved ) {
			return $this->resolved;
		}

		$registry = $this->registry();
		$own      = array();

		foreach ( $registry as $id => $app ) {
			if ( $app['core'] || null === $app['setting'] ) {
				$own[ $id ] = true;
				continue;
			}

			$own[ $id ] = $this->settings->bool( $app['setting'], $app['default'] );
		}

		// Fold dependencies in until nothing changes. The graph is flat today — no module
		// hard-requires another — but the tri-state Active/Paused/Inactive UI is built on
		// it, and a fixed-point loop costs nothing over an empty graph while being correct
		// over a deep one.
		$effective = $own;

		do {
			$changed = false;

			foreach ( $registry as $id => $app ) {
				if ( ! $effective[ $id ] ) {
					continue;
				}

				foreach ( $app['deps'] as $dep ) {
					// An unknown dependency is treated as satisfied, per fail-open.
					if ( isset( $effective[ $dep ] ) && ! $effective[ $dep ] ) {
						$effective[ $id ] = false;
						$changed          = true;
						break;
					}
				}
			}
		} while ( $changed );

		$this->resolved = $effective;

		return $effective;
	}
}
