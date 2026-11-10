<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Subjects;

use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The subjects table.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'subjects' );
	}

	/**
	 * @return array<string, string> What dbDelta changed.
	 */
	public static function install(): array {
		$changes = Db::delta( self::subjects() );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * Subjects: a hierarchy stored as a materialised path.
	 *
	 * WHY A PATH RATHER THAN A RECURSIVE QUERY.
	 *
	 * The hot question is not "what are this subject's children" — it is "give me every
	 * question anywhere beneath this subject", which a random-draw pool rule asks every time
	 * an attempt starts, for every rule, while an examinee waits. A path answers that with
	 * one indexed `LIKE 'x/y/%'`; an adjacency list needs a recursive CTE, which MySQL only
	 * gained in 8.0 and which WordPress does not guarantee.
	 *
	 * The cost is that a move has to rewrite the path of every descendant. That is
	 * `SubjectService::move()`, it is rare, and it is a write — the right side of the trade
	 * for a structure that is read on every attempt start.
	 *
	 * `path` includes the row itself, so a subtree is `path = 'x' OR path LIKE 'x/%'`.
	 * Excluding self would make every subtree query a two-part union of the node and its
	 * descendants, which is the sort of thing one caller eventually gets wrong.
	 */
	private static function subjects(): string {
		$table   = Db::table( 'subjects' );
		$charset = Db::charset();
		$audit   = Repository::audit_columns();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			parent_id bigint(20) unsigned NOT NULL default 0,
			path varchar(400) NOT NULL default '',
			level tinyint(3) unsigned NOT NULL default 1,
			code varchar(50) default NULL,
			title varchar(255) NOT NULL default '',
			slug varchar(255) NOT NULL default '',
			description text,
			ordering int(11) NOT NULL default 0,
			published tinyint(1) NOT NULL default 1,
			{$audit}
			PRIMARY KEY  (id),
			KEY parent_ordering (parent_id,ordering),
			KEY path (path(191)),
			KEY published (published),
			KEY slug (slug(191)),
			KEY code (code),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}
}
