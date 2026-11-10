<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scheduling a recurring job, at a moment when it is safe to.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * NOT BEFORE `init`, AND EVERY MODULE USED TO.
 *
 * Modules register their hooks on `plugins_loaded`, and each one that owns a recurring job
 * checked `wp_next_scheduled()` there and scheduled it on the spot. `wp_schedule_event()`
 * validates its recurrence against `wp_get_schedules()`, which is the `cron_schedules`
 * filter applied — every plugin's interval labels, translated. So on the first request
 * after activation, the one request where nothing is scheduled yet, this plugin made
 * WordPress translate its own label and WooCommerce's before `init`. Since 6.7 WordPress
 * reports that as "translation loading triggered too early", once for each text domain.
 *
 * It showed on a site with WooCommerce and a debug log, and nowhere else, which is why it
 * sat in a log for weeks as a pair of notices nobody could reproduce on demand: it needs an
 * unscheduled event, so it happens once per activation and never again.
 *
 * Deferring to `init` also retires an older trap. A module scheduling against an interval
 * that a filter registered a few lines LATER got `invalid_schedule` on every request, for
 * ever. By `init` every module has registered everything, so the order inside a module's
 * `register_hooks()` no longer decides whether its job runs.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class Cron {

	/**
	 * Make sure a recurring event exists, scheduling it on `init` if it does not.
	 *
	 * @param string $hook       The action the event fires.
	 * @param string $recurrence A schedule name from `wp_get_schedules()`.
	 * @param int    $delay      Seconds from now until the first run.
	 */
	public static function ensure( string $hook, string $recurrence, int $delay ): void {
		$schedule = static function () use ( $hook, $recurrence, $delay ): void {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + $delay, $recurrence, $hook );
			}
		};

		// A module booted after `init` — a test, a late include — would otherwise wait for
		// an action that has already gone by.
		if ( did_action( 'init' ) ) {
			$schedule();

			return;
		}

		add_action( 'init', $schedule );
	}
}
