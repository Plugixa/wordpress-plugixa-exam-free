<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Tags;

use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags and their join table.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'tags', 'question_tags' );
	}

	/**
	 * @return array<string, string> What dbDelta changed.
	 */
	public static function install(): array {
		$changes = Db::delta( self::tags(), self::question_tags() );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * Tags: flat, cross-cutting labels.
	 *
	 * Deliberately distinct from Subjects. A subject is where a question LIVES — one place,
	 * hierarchical, and what a random-draw pool rule selects on. A tag is a cross-cutting
	 * property a question HAS — many per question, flat, and what narrows a pool once the
	 * subject has chosen it. Collapsing the two, which is tempting because both are
	 * "categories", loses the tree that pool rules depend on.
	 */
	private static function tags(): string {
		$table   = Db::table( 'tags' );
		$charset = Db::charset();
		$audit   = Repository::audit_columns();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			title varchar(100) NOT NULL default '',
			slug varchar(100) NOT NULL default '',
			published tinyint(1) NOT NULL default 1,
			ordering int(11) NOT NULL default 0,
			{$audit}
			PRIMARY KEY  (id),
			KEY slug (slug),
			KEY published (published),
			KEY ordering (ordering),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}

	/**
	 * The question↔tag join.
	 *
	 * NO SOFT DELETE, AND NO AUDIT COLUMNS. This table holds no information of its own — a
	 * row is the assertion "this question has this tag", and un-asserting it means the row
	 * should be gone. A soft-deleted join row would also collide with the UNIQUE key the
	 * moment the tag was re-applied: the index counts trashed rows, so re-tagging would
	 * fail with a duplicate-key error. `TagRepository` therefore purges rather than trashes.
	 */
	private static function question_tags(): string {
		$table   = Db::table( 'question_tags' );
		$charset = Db::charset();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			question_id bigint(20) unsigned NOT NULL default 0,
			tag_id bigint(20) unsigned NOT NULL default 0,
			created_at datetime default NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY question_tag (question_id,tag_id),
			KEY tag_id (tag_id)
		) {$charset};";
	}
}
