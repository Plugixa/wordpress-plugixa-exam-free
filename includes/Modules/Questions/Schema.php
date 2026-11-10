<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Questions;

use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The question bank tables.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'questions', 'question_options', 'question_versions' );
	}

	/**
	 * @return array<string, string> What dbDelta changed.
	 */
	public static function install(): array {
		$changes = Db::delta( self::questions(), self::question_options(), self::question_versions() );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * Questions.
	 *
	 * `answer_key` is JSON rather than columns because its shape is per-type: a short
	 * answer needs an accepted-values list and a numeric tolerance, a multiple-response
	 * needs a partial-credit policy, an essay needs a word range. Modelling the union as
	 * columns gives a table where most columns are NULL for most rows, and adding the module
	 * types would add more. The trade is that the shape is validated in PHP rather than by the
	 * database — see `AnswerKey`.
	 *
	 * `question_type` is a plain varchar, not an enum. Modules register nine more types —
	 * two free, seven premium — and an enum would mean a schema change to install an add-on.
	 */
	private static function questions(): string {
		$table   = Db::table( 'questions' );
		$charset = Db::charset();
		$audit   = Repository::audit_columns();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			subject_id bigint(20) unsigned NOT NULL default 0,
			question_type varchar(30) NOT NULL default '',
			title varchar(255) NOT NULL default '',
			slug varchar(255) NOT NULL default '',
			stem mediumtext,
			difficulty tinyint(3) unsigned NOT NULL default 1,
			default_points decimal(8,2) NOT NULL default 1.00,
			answer_key longtext,
			explanation text,
			shuffle_options tinyint(1) NOT NULL default 1,
			published tinyint(1) NOT NULL default 1,
			version int(11) NOT NULL default 1,
			approval_status varchar(20) NOT NULL default 'approved',
			rubric longtext,
			custom_fields longtext,
			render_hint longtext,
			{$audit}
			PRIMARY KEY  (id),
			KEY subject_id (subject_id),
			KEY question_type (question_type),
			KEY difficulty (difficulty),
			KEY published (published),
			KEY slug (slug(191)),
			KEY deleted_at (deleted_at),
			KEY pool_draw (subject_id,question_type,difficulty,published)
		) {$charset};";
	}

	/**
	 * Question options.
	 *
	 * ONE COMPOSITE INDEX, DELIBERATELY. Options are ALWAYS read as
	 * `WHERE question_id = ? ORDER BY ordering` — by the bank editor, by the runner when it
	 * hydrates a paper, and by the exporter. A standalone index on `ordering` could serve
	 * neither half of that and would only cost writes; one on `question_id` alone would be
	 * this key's leftmost prefix, and so equally redundant.
	 *
	 * No soft delete and no audit columns: an option belongs to its question and is
	 * rewritten wholesale whenever the question is saved. A trashed option would still be
	 * a row the runner has to filter out, on the hottest read path there is.
	 */
	private static function question_options(): string {
		$table   = Db::table( 'question_options' );
		$charset = Db::charset();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			question_id bigint(20) unsigned NOT NULL default 0,
			ordering int(11) NOT NULL default 0,
			option_text text,
			is_correct tinyint(1) NOT NULL default 0,
			match_key varchar(100) default NULL,
			feedback varchar(500) default NULL,
			PRIMARY KEY  (id),
			KEY question_ordering (question_id,ordering)
		) {$charset};";
	}

	/**
	 * Frozen content snapshots, one per save.
	 *
	 * WHY A QUESTION NEEDS HISTORY AT ALL: an attempt records which option ids were
	 * selected, not what those options said. Edit a question after somebody has sat the
	 * exam and their result page renders the NEW wording against their OLD choice — which
	 * at best confuses them and at worst makes a correct answer look wrong during an
	 * appeal. The snapshot is what a review screen can fall back to.
	 *
	 * Append-only, so no soft delete and no `modified_*`.
	 */
	private static function question_versions(): string {
		$table   = Db::table( 'question_versions' );
		$charset = Db::charset();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			question_id bigint(20) unsigned NOT NULL default 0,
			version int(11) NOT NULL default 1,
			snapshot longtext,
			created_at datetime default NULL,
			created_by bigint(20) unsigned NOT NULL default 0,
			PRIMARY KEY  (id),
			KEY question_version (question_id,version)
		) {$charset};";
	}
}
