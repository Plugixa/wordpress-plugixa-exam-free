<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Modules;

use PlugixaExam\Core\Container;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The contract every module satisfies.
 *
 * A module is a self-contained folder. Adding one means dropping a directory into
 * `includes/Modules/` (free) or `pro/includes/Modules/` (premium) — no core file is
 * edited, no registry list is appended to. {@see ModuleRegistry} discovers it on disk.
 *
 * WHY `app()` EXISTS, WHICH plugixa-crm's EQUIVALENT DOES NOT HAVE.
 *
 * NextExams shipped an "App Manager": a hand-written registry of ~26 toggleable
 * subsystems, each one boolean settings row, consulted by both the SPA and the server-side
 * request guard. It is a genuinely good pattern and this plugin keeps it — but keeping the
 * registry as a hand-written constant would mean every new module has to be added in two
 * places, and the failure mode of forgetting the second one is a module whose endpoints are
 * ungated. So the registry is DERIVED from the modules on disk instead: a module declares
 * its own category, its own setting key and its own dependencies, and {@see AppRegistry}
 * assembles the answer. There is one list, and it is the filesystem.
 *
 * This is also why the App Manager and the free/premium seam are the same mechanism rather
 * than two: a premium module absent from the free build contributes nothing to the
 * registry, exactly as a disabled module contributes nothing to the UI.
 */
interface ModuleInterface {

	/**
	 * Stable machine id, e.g. "questions".
	 *
	 * Used for REST paths, capability names, the App Manager setting, and the key this
	 * module is stored under in the registry. It must match the JS module folder name so
	 * the two halves can be reasoned about together.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * IT MUST ALSO BE A TRANSLATION KEY, or the App Manager renders it raw.
	 *
	 * That screen labels every switch with `t( id, id )` — the id doubles as its own
	 * fallback — so a module whose `strings()` does not carry a `'<id>' => __( … )` entry
	 * appears to an administrator as `advancedtypes`, `api_tokens`, `customfields`. Eight
	 * of thirty-five did, for six phases, because nothing connected the two halves: the
	 * label is contributed by the module and the screen that reads it lives in Settings.
	 *
	 * `AppLabelTest` now walks every registered module and fails on a missing one, which is
	 * the only thing that will keep this true — the defect is invisible in every unit test
	 * and looks like a slug somebody chose on purpose.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 */
	public function id(): string;

	/**
	 * App Manager metadata.
	 *
	 * @return array{
	 *     category: string,
	 *     core?: bool,
	 *     setting?: string,
	 *     default?: bool,
	 *     deps?: string[],
	 *     types?: string[]
	 * }
	 *
	 * `core` modules have no setting row at all and cannot be switched off, even by a
	 * hand-crafted POST — {@see AppRegistry::is_enabled()} short-circuits on them and
	 * {@see \PlugixaExam\Core\Http\AppController}'s whitelist is derived from this rather
	 * than written out by hand.
	 *
	 * `setting` names the row in the settings table that stores the flag. Seven of them
	 * deliberately reuse a master switch that exists for its own sake
	 * (`certificates_enabled`, `monitoring_enabled`, …) rather than growing an `app_*`
	 * twin that could disagree with it.
	 *
	 * `types` names the question formats a module OWNS, and exists because switching one of
	 * those modules off is the one toggle that can silently break finished work. A module
	 * contributes its formats through a hook, so a disabled module contributes none — and a
	 * question whose format nobody recognises has its answer discarded and its candidate
	 * scored zero, in silence. Measured: with `app_advancedtypes` off, a PERFECT answer to a
	 * real ordering question grades `null`.
	 *
	 * Declaring the formats lets the App Manager count what is at stake and say so before
	 * the switch is thrown, instead of leaving somebody to discover it a term later. Only
	 * the five format-owning modules declare it; for everyone else it is absent and the
	 * screen shows no warning.
	 */
	public function app(): array;

	/**
	 * Create this module's database tables.
	 *
	 * Runs on activation and on every version change, so it must be idempotent — use
	 * `dbDelta` through {@see \PlugixaExam\Core\Database\Schema}.
	 */
	public function migrate(): void;

	/**
	 * Capabilities this module introduces.
	 *
	 * @return array<string, string[]> Capability name => WordPress role names.
	 */
	public function capabilities(): array;

	/**
	 * Bind repositories and services into the container.
	 *
	 * Most modules leave this empty: the container autowires from constructor signatures,
	 * so a service with only class-typed dependencies needs no factory.
	 *
	 * @param Container $container The container.
	 */
	public function register_services( Container $container ): void;

	/**
	 * Register REST routes. Called on `rest_api_init`.
	 *
	 * @param Container $container The container.
	 */
	public function register_routes( Container $container ): void;

	/**
	 * Register hooks — cron events, listeners, filters.
	 *
	 * This is where a module plugs into everything else. Direct imports across modules are
	 * forbidden; a module reaches another through the container, through a filter, or not
	 * at all.
	 *
	 * @param Container $container The container.
	 */
	public function register_hooks( Container $container ): void;
}
