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
 * Deactivation.
 *
 * Deliberately keeps every row and every option. Deactivating a plugin is how a site owner
 * troubleshoots a conflict, and a plugin that discards a term's worth of exam results the
 * moment it is switched off is one nobody can safely troubleshoot. Data removal happens at
 * uninstall, where the user has been asked.
 */
final class Deactivator {

	public static function deactivate(): void {
		self::clear_cron();

		self::forget_rewrite_rules();

		/**
		 * Fires as the plugin is deactivated.
		 */
		do_action( 'plugixa_exam_deactivated' );
	}

	/**
	 * Take this plugin's addresses out of WordPress's stored rewrite rules.
	 *
	 * DELETED, NOT FLUSHED — and `flush_rewrite_rules()` here was the bug. A flush rebuilds
	 * the rule set there and then, in the request that is deactivating the plugin, while the
	 * rules `Router` added on `init` are still registered. So it wrote every one of them
	 * straight back, and `/exams/` went on matching after the plugin was deactivated and
	 * after it was deleted: WordPress resolved it to a query var nothing recognises and
	 * served the blog's front page with a 200 where a 404 belonged. Found by uninstalling
	 * from the Plugins screen and asking for the address afterwards.
	 *
	 * Deleting the option leaves nothing to be wrong. WordPress rebuilds the set the next
	 * time it needs it, which is a later request — one this plugin is not loaded in.
	 */
	public static function forget_rewrite_rules(): void {
		delete_option( 'rewrite_rules' );
	}

	/**
	 * Unschedule every event this plugin registered.
	 *
	 * Swept by prefix rather than by a list, so an event belonging to a module that has
	 * since been removed still goes — a hand-written list leaves an orphaned hook firing
	 * against nothing on every cron run, for ever.
	 */
	private static function clear_cron(): void {
		$crons = _get_cron_array();

		if ( ! is_array( $crons ) ) {
			return;
		}

		foreach ( $crons as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( str_starts_with( (string) $hook, 'plugixa_exam_' ) ) {
					wp_clear_scheduled_hook( (string) $hook );
				}
			}
		}
	}
}
