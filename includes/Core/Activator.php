<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core;

use PlugixaExam\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation and migration.
 *
 * Creates no tables of its own — it asks every module to create the ones it owns. That is
 * what lets a premium module ship a table without a single free file changing.
 */
final class Activator {

	public const DB_VERSION_OPTION = 'plugixa_exam_db_version';

	/**
	 * Where a migration that did not finish is written down: when, and what each module said.
	 *
	 * Present means "the database is not up to date and the plugin knows it". It is what the
	 * admin notice reads, and what spaces out the retries. Deleted by the first migration
	 * that finishes.
	 */
	public const FAILURE_OPTION = 'plugixa_exam_migration_failed';

	/**
	 * Seconds to wait before the plugin tries a failed migration again by itself.
	 *
	 * Without it a site whose database user may not create tables would run every module's
	 * dbDelta — dozens of queries and the whole of wp-admin's includes — on every request,
	 * front end included, for as long as the fault lasted. Five minutes is short enough that
	 * a host fixing a privilege sees it take effect without being told to do anything, and
	 * an administrator who is waiting can press "Try again" on the notice.
	 */
	public const RETRY_AFTER = 300;

	/**
	 * Settings rows kept by nothing, deleted once on the next migration.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * AN EXPLICIT LIST, AND IT MUST STAY ONE. THE OBVIOUS GENERALISATION DESTROYS DATA.
	 *
	 * The tempting version is "delete any row no module declares a default for", which would
	 * need no maintenance and would be a catastrophe: on the FREE build every premium module
	 * is absent from disk, so every premium setting is undeclared. A site that downgraded for
	 * an afternoon — or whose licence lapsed over a weekend — would come back to find its
	 * certificate numbering, its invigilation intervals and its webhook policy silently
	 * reset, because a cleanup could not tell "this module is gone" from "this module is not
	 * in this build".
	 *
	 * The same reasoning rules out pruning on deactivation. A row nobody reads is harmless;
	 * a row somebody set and this deleted is not.
	 *
	 * So each entry here is a key a human has confirmed is dead, with why:
	 *
	 *  - `sitewidgets_enabled`    — a module that was renamed away; nothing on disk has
	 *                               mentioned `sitewidgets` for some releases.
	 *  - `monitor_stream_enabled` — gated server-sent events that were never built. No
	 *                               `EventSource`, no `text/event-stream`, no endpoint.
	 *  - `monitor_ping_seconds`   — configured a ping interval for a ping that does not
	 *                               exist; nothing in the runner or the board ever read it.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @var string[]
	 */
	private const RETIRED_SETTINGS = array(
		'sitewidgets_enabled',
		'monitor_stream_enabled',
		'monitor_ping_seconds',

		/*
		 * ⚠️ RETIRED WITH THE SIDEBAR. `app` made the plugin emit its own document — no
		 * theme header, no theme footer, no admin bar — on the promise that it drew its own
		 * navigation instead. It does not any more: the exam area is pages the site owner
		 * puts in their own menu. `app` would now mean a page with no navigation of any
		 * kind and no way out but the browser's Back button.
		 *
		 * It had one runtime read and never had an admin control, so almost nobody can have
		 * set it — but a site that did would be left on a dead end, silently.
		 */
		'frontend_chrome',
	);

	/**
	 * Runs on `register_activation_hook`.
	 */
	public static function activate(): void {
		self::migrate();

		// Rewrite rules are registered on `init` by Core\Frontend\Router, which has not run
		// at activation time. Flushing here would write a rule set that does not yet include
		// ours, so the flag is set and Router flushes on the next request instead.
		update_option( 'plugixa_exam_flush_rewrites', '1' );

		/**
		 * Fires after the plugin is activated and migrated.
		 */
		do_action( 'plugixa_exam_activated' );
	}

	/**
	 * What the database has been brought up to date WITH.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE VERSION IS NOT ENOUGH, AND THE FREE → PREMIUM UPGRADE IS THE PROOF.
	 *
	 * The drift guard used to compare the stored value against `PLUGIXA_EXAM_VERSION`
	 * alone. Swapping the free build for the premium one is NOT a version change — the
	 * licence arrives, Freemius installs the premium ZIP at the same version, and
	 * WordPress does not re-run the activation hook. So the guard saw `0.1.0 === 0.1.0`,
	 * skipped the migration, and SEVENTEEN TABLES across eight premium modules were never
	 * created. Every one of those screens then failed with `Table … doesn't exist`, in the
	 * log, silently. That is the ordinary upgrade path, not an edge case.
	 *
	 * So the signature carries the thing that actually changed: WHICH MODULES ARE ON DISK.
	 * `ModuleRegistry` discovers them by globbing, so this covers free → premium, premium →
	 * free, and a module added or removed by any later release or add-on — without anyone
	 * having to remember to bump a number.
	 *
	 * Stored as readable text rather than a hash, because the first question asked of a
	 * site that migrated when it should not have — or did not when it should — is "what did
	 * it think was installed", and a hash cannot answer it.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string[] $module_ids Ids of the modules currently on disk.
	 */
	public static function signature( array $module_ids ): string {
		// Sorted, so filesystem order cannot make two identical installs disagree.
		sort( $module_ids );

		return PLUGIXA_EXAM_VERSION . '|' . implode( ',', $module_ids );
	}

	/**
	 * Bring the database and capabilities up to date with the code.
	 *
	 * Called from `activate()` and from `Plugin::boot()` when the stored signature does not
	 * match the running one — see the drift guard there for why both are needed.
	 *
	 * @return bool Whether every module migrated. False means the version was NOT recorded
	 *              and {@see failure()} now says why.
	 */
	public static function migrate(): bool {
		$plugin  = Plugin::instance();
		$modules = $plugin->modules();

		// A file-copy update can reach this before boot() has discovered anything, because
		// the drift check runs inside boot() itself and activation does not run boot() at
		// all. Discovering twice is a no-op; discovering never is a migration that migrates
		// nothing and then records the new version, which hides the problem until a query
		// fails.
		if ( array() === $modules->all() ) {
			$modules->discover();
		}

		$failures = $modules->migrate();

		self::prune_retired_settings();

		// Granted whatever happened to the tables: an administrator who cannot open the
		// plugin's screen cannot read why it is not working.
		Capabilities::grant(
			array_merge( Capabilities::core_map(), $modules->capabilities() )
		);

		if ( array() !== $failures ) {
			/*
			 * NOT RECORDED AS DONE. The version stays what it was, so the drift guard in
			 * `Plugin::boot()` still sees a database that is behind and comes back to it —
			 * after {@see RETRY_AFTER}, not on the next request. What failed is written
			 * down as facts (module, tables, what the database said) and put into words by
			 * `Admin\MigrationNotice`, because this can run before WordPress will load a
			 * translation.
			 */
			update_option(
				self::FAILURE_OPTION,
				array(
					'at'       => time(),
					'failures' => $failures,
				),
				false
			);

			/**
			 * Fires when a migration did not finish.
			 *
			 * @param array<string, array{tables: string[], reason: string}> $failures Module id => what went wrong.
			 */
			do_action( 'plugixa_exam_migration_failed', $failures );

			return false;
		}

		delete_option( self::FAILURE_OPTION );

		/*
		 * Recorded AFTER the migration, only when every module finished, and computed from
		 * what was actually migrated. Writing the signature first would mark a half-finished
		 * migration as complete — and `dbDelta` is idempotent, so the cost of running again
		 * is a few SHOW queries while the cost of skipping is a missing table nobody notices
		 * until a screen 500s.
		 */
		update_option( self::DB_VERSION_OPTION, self::signature( array_keys( $modules->all() ) ) );

		/**
		 * Fires after every module's schema has been brought up to date.
		 */
		do_action( 'plugixa_exam_migrated' );

		return true;
	}

	/**
	 * The migration that did not finish, if there is one.
	 *
	 * @return array{at: int, failures: array<string, array{tables: string[], reason: string}>}|null
	 */
	public static function failure(): ?array {
		$recorded = get_option( self::FAILURE_OPTION );

		if ( ! is_array( $recorded ) || empty( $recorded['failures'] ) || ! is_array( $recorded['failures'] ) ) {
			return null;
		}

		return array(
			'at'       => (int) ( $recorded['at'] ?? 0 ),
			'failures' => $recorded['failures'],
		);
	}

	/**
	 * Whether the drift guard should try the migration now.
	 *
	 * Always, unless the last attempt failed less than {@see RETRY_AFTER} seconds ago.
	 * Activation and the notice's "Try again" do not ask: somebody pressed something.
	 */
	public static function due(): bool {
		$failure = self::failure();

		return null === $failure || ( time() - $failure['at'] ) >= self::RETRY_AFTER;
	}

	/**
	 * Drop the settings rows in {@see RETIRED_SETTINGS}, if this site still has any.
	 *
	 * Writes only when something was actually removed: the option is autoloaded, and
	 * rewriting it on every migration of every site to change nothing is a wasted write on
	 * the request somebody is waiting for.
	 */
	private static function prune_retired_settings(): void {
		$all = get_option( Settings::OPTION );

		if ( ! is_array( $all ) ) {
			return;
		}

		$before = count( $all );

		foreach ( self::RETIRED_SETTINGS as $key ) {
			unset( $all[ $key ] );
		}

		if ( count( $all ) === $before ) {
			return;
		}

		update_option( Settings::OPTION, $all, true );

		// The in-request cache was populated before this ran, and the App Manager reads it
		// on the same request during activation.
		Plugin::instance()->container()->get( Settings::class )->flush();
	}
}
