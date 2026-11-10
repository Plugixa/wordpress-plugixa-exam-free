<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Admin;

use PlugixaExam\Core\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells an administrator that the plugin's database could not be set up, and why.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE PLUGIN EXPLAINS THE PROBLEM INSTEAD OF FAILING.
 *
 * That sentence is from WordPress.org's review of 1.1.1, which found that a module whose
 * tables could not be created was recorded as migrated all the same. The first half of the
 * answer is in {@see Activator::migrate()}: a migration that did not finish is not recorded
 * as done. This is the second half. A site in that state has screens that will fail on the
 * first query, and the person who can do something about it — usually by asking their host
 * for a database user that may create tables — had no way to know.
 *
 * WHERE IT SHOWS, AND WHY NOT EVERYWHERE. On the Plugins screen, where somebody has just
 * pressed Activate; on the Dashboard; and on this plugin's own screens. Not on every admin
 * page: the directory asks that a notice stay where it is relevant, and an editor writing a
 * post can do nothing about a database privilege.
 *
 * WHO SEES IT. Whoever may activate plugins. It names tables and repeats what the database
 * said, which is not for everybody who can sign in.
 *
 * IT CANNOT BE DISMISSED, AND IT REMOVES ITSELF. It is not an announcement. It is there
 * while the fault is and gone with the first migration that finishes.
 *
 * THE WORDS ARE CHOSEN HERE, NOT WHERE THE FAILURE HAPPENED. A migration can run on
 * `plugins_loaded`, before WordPress will load a translation, so what is stored is the
 * table names and the database's own message; this puts them into a sentence in the
 * reader's language.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class MigrationNotice {

	/**
	 * The admin-post action, and the nonce, of the "Try again" button.
	 */
	public const ACTION = 'plugixa_exam_migrate';

	public static function register_hooks(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'retry' ) );
	}

	/**
	 * Print the notice, if there is a failure to report to this person on this screen.
	 */
	public static function render(): void {
		$failure = Activator::failure();

		if ( null === $failure || ! current_user_can( 'activate_plugins' ) || ! self::relevant_screen() ) {
			return;
		}

		$retry = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html__( 'Plugixa Exam could not finish setting up its database, so parts of it will not work.', 'plugixa-exam' );
		echo '</strong></p><ul style="list-style:disc;margin-left:1.5em;">';

		foreach ( $failure['failures'] as $module => $what ) {
			echo '<li>' . esc_html( self::describe( (string) $module, (array) $what ) ) . '</li>';
		}

		echo '</ul><p>';
		echo esc_html__( 'The usual cause is a database user that is not allowed to create tables. Once that is put right, try again.', 'plugixa-exam' );
		echo ' <a class="button button-secondary" href="' . esc_url( $retry ) . '">' . esc_html__( 'Try again', 'plugixa-exam' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Run the migration now, because somebody asked for it.
	 *
	 * Does not wait for {@see Activator::RETRY_AFTER}: that interval is for the plugin
	 * retrying by itself, and this is a person who has just changed something.
	 */
	public static function retry(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'plugixa-exam' ), '', array( 'response' => 403 ) );
		}

		Activator::migrate();

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * One failure, as a sentence.
	 *
	 * @param string               $module The module whose migration did not finish.
	 * @param array<string, mixed> $what   `tables` and `reason`, as {@see Activator} stored them.
	 */
	private static function describe( string $module, array $what ): string {
		$tables = array_map( 'strval', (array) ( $what['tables'] ?? array() ) );

		// The database's message can be long, and it is somebody else's text: shortened,
		// and escaped by the caller like everything else here.
		$reason = wp_html_excerpt( trim( (string) ( $what['reason'] ?? '' ) ), 300, '…' );

		if ( array() === $tables ) {
			return sprintf(
				/* translators: 1: the part of the plugin that failed, e.g. "certificates"; 2: the error message. */
				__( 'Setting up %1$s failed: %2$s', 'plugixa-exam' ),
				$module,
				$reason
			);
		}

		if ( '' === $reason ) {
			return sprintf(
				/* translators: %s: one or more database table names. */
				__( 'The table %s could not be created.', 'plugixa-exam' ),
				implode( ', ', $tables )
			);
		}

		return sprintf(
			/* translators: 1: one or more database table names; 2: the database's own error message. */
			__( 'The table %1$s could not be created. The database said: %2$s', 'plugixa-exam' ),
			implode( ', ', $tables ),
			$reason
		);
	}

	/**
	 * Whether this is a screen where the notice belongs.
	 */
	private static function relevant_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen ) {
			return false;
		}

		return in_array( $screen->id, array( 'plugins', 'dashboard' ), true )
			|| str_contains( (string) $screen->id, Admin::MENU_SLUG );
	}
}
