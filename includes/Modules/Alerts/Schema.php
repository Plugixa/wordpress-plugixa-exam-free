<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Alerts;

use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One inbox per person, and what the bell reads.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'alerts' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$changes = Db::delta( self::alerts() );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * One alert, addressed to one person.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE SUBJECT IS A SNAPSHOT, AND THAT IS A DECISION RATHER THAN AN OVERSIGHT.
	 *
	 * `subject` is copied in at the moment the alert fires rather than joined back to the
	 * exam at read time, so renaming an exam afterwards leaves the feed showing the old
	 * title. That is the more truthful reading — an alert is a record of what happened, not
	 * a live view of a row — and it is also the only one that survives the thing being
	 * deleted. A JOIN to a soft-deleted exam does not fail loudly; IT RETURNS FEWER ROWS,
	 * and the alert simply goes missing from somebody's feed with nothing in a log.
	 *
	 * `context_type` / `context_id` say what it was ABOUT, polymorphically, and nothing
	 * reads them expecting a row to still be there. The LINK is resolved at read time by
	 * whichever module owns that kind of thing — never stored, because a stored route is
	 * wrong the moment a permalink base changes and nobody finds out for months.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * NO `deleted_at`. An alert is not a record anybody edits, trashes or restores: it is
	 * delivered, read, and eventually pruned. `AlertRepository::soft_deletes()` returns
	 * false to match, and the audit columns are requested WITHOUT it — `Repository::insert()`
	 * writes `created_at`, `modified_at`, `created_by` and `modified_by` unconditionally, so
	 * a table missing any of the four fails every insert and returns 0 rather than throwing.
	 *
	 * The index is the read: the bell asks for one user's rows newest first, and asks how
	 * many of them are unread, and `inbox (user_id, read_at, created_at)` answers both
	 * without touching the table.
	 */
	private static function alerts(): string {
		$table   = Db::table( 'alerts' );
		$charset = Db::charset();
		$audit   = Repository::audit_columns( false );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			user_id bigint(20) unsigned NOT NULL default 0,
			alert_type varchar(50) NOT NULL default '',
			subject varchar(255) NOT NULL default '',
			context_type varchar(32) NOT NULL default '',
			context_id bigint(20) unsigned NOT NULL default 0,
			read_at datetime default NULL,
			{$audit}
			PRIMARY KEY  (id),
			KEY inbox (user_id,read_at,created_at),
			KEY context (context_type,context_id)
		) {$charset};";
	}
}
