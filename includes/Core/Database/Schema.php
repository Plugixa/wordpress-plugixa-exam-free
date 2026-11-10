<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table-name and dbDelta helpers shared by every module's migration.
 *
 * Schema ownership is decentralised: this class creates no tables of its own. Each module
 * declares and creates the tables it owns, so a premium module can add tables without any
 * free file changing.
 */
final class Schema {

	/**
	 * Table prefix for every table this plugin owns.
	 *
	 * Also what the uninstaller drops by, so a table belonging to a module that has since
	 * been deleted still goes.
	 */
	public const PREFIX = 'plugixa_exam_';

	/**
	 * Fully-qualified table name.
	 *
	 * @param string $name Bare table name, e.g. "questions".
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . self::PREFIX . $name;
	}

	/**
	 * The engine and charset clause every table is created with.
	 *
	 * InnoDB is requested EXPLICITLY rather than inherited from the server's default,
	 * because that default cannot be relied on — and an exam system on MyISAM is quietly
	 * broken in ways that never announce themselves:
	 *
	 *  - no transactions. START TRANSACTION and ROLLBACK are accepted and silently ignored,
	 *    so `finalize()`'s exactly-once guard is not a guard at all.
	 *  - table-level locking. Every autosave locks the whole responses table, so a room of
	 *    thirty examinees serialises behind each other on the one write path that must not
	 *    be slow.
	 *  - no crash recovery. A power cut during an exam leaves a corrupt table rather than a
	 *    rolled-back transaction — and the data at risk is somebody's certification.
	 */
	public static function charset(): string {
		global $wpdb;

		return 'ENGINE=InnoDB ' . $wpdb->get_charset_collate();
	}

	/**
	 * Run one or more CREATE TABLE statements through dbDelta.
	 *
	 * dbDelta is picky in ways that fail silently: types must be lowercase, there must be
	 * two spaces after `PRIMARY KEY`, and every KEY must be named. Statements pass through
	 * verbatim, so those rules are the caller's to honour.
	 *
	 * Returns what dbDelta changed. On a schema that has already been applied this must be
	 * empty — if it is not, dbDelta disagrees with what MySQL actually stored and will keep
	 * issuing ALTERs on every page load, silently, forever. `SchemaTest` asserts exactly
	 * that, which is the only way to catch the whole class of bug.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * AND IT CHECKS THAT EACH TABLE IS THERE AFTERWARDS, BECAUSE dbDelta WILL NOT SAY.
	 *
	 * A CREATE TABLE the database refuses — a user without the privilege, a full disk, a
	 * row format the server will not take — makes `$wpdb->query()` return false inside
	 * dbDelta, and dbDelta does not look. It returns as it always does, lists the table
	 * among the things it "created", and raises nothing. Measured on a clean install with
	 * one CREATE made to fail: the activation reported success, eighteen of nineteen tables
	 * existed, the version was recorded as up to date, and nothing ever tried again. The
	 * missing one was where answers are stored.
	 *
	 * So the only honest report is to look. A table that is not there afterwards is thrown
	 * as {@see MigrationFailed}, with what the database said about it.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string ...$statements CREATE TABLE statements.
	 *
	 * @return array<string, string> What dbDelta changed, keyed by table.column.
	 *
	 * @throws MigrationFailed When a table named in a statement does not exist afterwards.
	 */
	public static function delta( string ...$statements ): array {
		global $wpdb;

		$changes = array();
		$missing = array();
		$reason  = '';

		// `dbDelta()` is defined in a file only wp-admin loads, and a migration can run on
		// any request. Loaded for the loop that follows.
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		foreach ( $statements as $sql ) {
			$changes = array_merge( $changes, (array) dbDelta( $sql ) );

			// Read FIRST. The CREATE is the last thing dbDelta runs for a new table, and the
			// next query — the check below — clears whatever it left here.
			$said = (string) $wpdb->last_error;

			// The same expression dbDelta uses to find the name in the statement.
			if ( 1 !== preg_match( '|CREATE TABLE ([^ ]*)|', $sql, $named ) ) {
				continue;
			}

			$table = trim( $named[1], '`' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- asks the server whether a table it was just told to create exists; nothing to cache.
			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				continue;
			}

			$missing[] = $table;
			$reason    = '' !== $said ? $said : $reason;
		}

		if ( array() !== $missing ) {
			// Built, then thrown. It carries the table names and the database's message AS
			// THEY ARE: nothing is printed here, and `Admin\MigrationNotice` escapes both
			// at the point where they are.
			$failed = new MigrationFailed( $missing, $reason );

			throw $failed;
		}

		return $changes;
	}

	/**
	 * Convert any of this plugin's tables that is not already InnoDB.
	 *
	 * dbDelta only ever adds and alters columns — it will not change a table's engine — so
	 * an installation created before the ENGINE clause existed would stay on MyISAM
	 * forever. This is the upgrade path, and a no-op on a table that is already right.
	 *
	 * @param string ...$names Bare table names.
	 */
	public static function ensure_innodb( string ...$names ): void {
		global $wpdb;

		foreach ( $names as $name ) {
			$table = self::table( $name );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection on a custom table; a cached answer would be the bug.
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);

			if ( null === $engine || 0 === strcasecmp( (string) $engine, 'InnoDB' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- a schema change is the entire point of this method.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
		}
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $name Bare table name.
	 */
	public static function exists( string $name ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', self::table( $name ) ) );
	}

	/**
	 * Whether a table still has a column.
	 *
	 * THE REASON THIS EXISTS: dbDelta can add a column and cannot remove one. It compares
	 * the CREATE TABLE it is given against the live table and issues the ALTERs that ADD
	 * what is missing — a column deleted from the statement is simply ignored, and stays
	 * for ever. A migration that MOVES data out of a column has to drop it explicitly, and
	 * has to ask first, because it will run again on the next upgrade.
	 *
	 * @param string $table  Bare table name.
	 * @param string $column Column name.
	 */
	public static function has_column( string $table, string $column ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection; a cached answer would be the whole bug.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', self::table( $table ), $column )
		);
	}

	/**
	 * Drop a column, if it is still there.
	 *
	 * Guarded rather than blind, because a migration runs on every version bump and the
	 * second run must be a no-op rather than an error.
	 *
	 * @param string $table  Bare table name.
	 * @param string $column Column name.
	 */
	public static function drop_column( string $table, string $column ): void {
		if ( ! self::has_column( $table, $column ) ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- a schema change is the entire point of this method.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', self::table( $table ), $column ) );
	}

	/**
	 * Drop tables. Used by the uninstaller.
	 *
	 * @param string ...$names Bare table names.
	 */
	public static function drop( string ...$names ): void {
		global $wpdb;

		foreach ( $names as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall drops the plugin's own tables.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table( $name ) ) );
		}
	}

	/**
	 * Every table this plugin owns, read from the database rather than from a list.
	 *
	 * The uninstaller drops by prefix so that a table belonging to a module which has since
	 * been deleted still goes — a hand-written list would leave it behind for ever, and
	 * "uninstall removed everything except the four tables from the module you removed last
	 * year" is not a promise anybody can keep by hand.
	 *
	 * @return string[] Fully-qualified table names.
	 */
	public static function all_tables(): array {
		global $wpdb;

		$like = $wpdb->esc_like( $wpdb->prefix . self::PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection at uninstall time.
		return array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) );
	}
}
