<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core;

use PlugixaExam\Core\Database\Schema;
use PlugixaExam\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Remove everything the plugin ever created.
 *
 * Invoked through Freemius's `after_uninstall` action rather than a root `uninstall.php`:
 * WordPress only runs `uninstall.php` from the active plugin folder, which breaks under
 * Freemius's free/premium version switching, and Freemius rejects plugins that ship one.
 *
 * DROPS BY PREFIX, NOT BY LIST. A module removed in an earlier release still owns tables on
 * disk, and a hand-written list cannot know about them — "uninstall removed everything
 * except the four tables from the module you deleted last year" is not a promise anyone can
 * keep by hand. {@see Schema::all_tables()} asks the database instead.
 */
final class Uninstaller {

	public static function uninstall(): void {
		/**
		 * Fires before any data is removed, while it can still be read.
		 *
		 * A module that wrote outside its own tables — a post meta key, an upload directory
		 * — cleans up here.
		 */
		do_action( 'plugixa_exam_uninstall' );

		self::drop_tables();
		self::delete_options();
		self::clear_cron();

		Capabilities::revoke_all();
	}

	/**
	 * Drop every table under this plugin's prefix.
	 */
	private static function drop_tables(): void {
		global $wpdb;

		foreach ( Schema::all_tables() as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall drops the plugin's own tables.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}

	/**
	 * Delete every option and transient under this plugin's prefixes.
	 *
	 * Two prefixes, because transients set by {@see \PlugixaExam\Core\Security\GuestToken}
	 * use the short `pxe_` form — a transient key is capped at 191 characters and the
	 * `_transient_timeout_` WordPress prepends eats 19 of them.
	 */
	private static function delete_options(): void {
		global $wpdb;

		$patterns = array(
			$wpdb->esc_like( 'plugixa_exam_' ) . '%',
			$wpdb->esc_like( '_transient_pxe_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_pxe_' ) . '%',
		);

		foreach ( $patterns as $pattern ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall sweep; delete_option() cannot match a pattern.
			$names = (array) $wpdb->get_col(
				$wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $pattern )
			);

			foreach ( $names as $name ) {
				delete_option( (string) $name );
			}
		}
	}

	/**
	 * Unschedule every event this plugin registered.
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
