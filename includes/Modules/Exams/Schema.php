<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Exams;

use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exams, their sections, and how those sections are filled.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'exams', 'exam_sections', 'exam_questions', 'exam_pool_rules' );
	}

	/**
	 * @return array<string, string> What dbDelta changed.
	 */
	public static function install(): array {
		$changes = Db::delta(
			self::exams(),
			self::exam_sections(),
			self::exam_questions(),
			self::exam_pool_rules()
		);

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * The exam.
	 *
	 * Wide on purpose — 40-odd columns, one per configurable rule. The alternative, a JSON
	 * `settings` blob, was tried by the system this ports and abandoned for everything that
	 * matters: the auto-close sweep filters on `status` and `expires_at`, the catalogue
	 * filters on `published` and the open window, and a pool draw reads `shuffle_questions`
	 * — none of which can use an index inside a JSON document.
	 *
	 * `settings` survives as an overflow column for third-party modules, deliberately
	 * unused by anything here. See the specification, §12.2.
	 *
	 * EVERY `default_*` SETTING SEEDS A COLUMN AT CREATION AND IS THEN IRRELEVANT. The
	 * runner and the sweep read the exam row, never the setting, so changing a default can
	 * never alter the rules of an exam somebody is already sitting.
	 */
	private static function exams(): string {
		$table   = Db::table( 'exams' );
		$charset = Db::charset();
		$audit   = Repository::audit_columns();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			title varchar(255) NOT NULL default '',
			slug varchar(255) NOT NULL default '',
			description text,
			intro mediumtext,
			subject_id bigint(20) unsigned NOT NULL default 0,
			time_limit int(11) NOT NULL default 0,
			max_attempts int(11) NOT NULL default 0,
			cooldown_minutes int(11) NOT NULL default 0,
			open_from datetime default NULL,
			open_until datetime default NULL,
			pass_mark decimal(5,2) NOT NULL default 50.00,
			shuffle_questions tinyint(1) NOT NULL default 0,
			shuffle_options tinyint(1) NOT NULL default 0,
			access_type varchar(20) NOT NULL default 'access_level',
			access_level varchar(60) default NULL,
			access_code varchar(100) default NULL,
			result_release varchar(20) NOT NULL default 'immediate',
			release_at datetime default NULL,
			show_answers tinyint(1) NOT NULL default 1,
			pass_message mediumtext,
			fail_message mediumtext,
			lock_policy varchar(20) NOT NULL default 'auto',
			attempt_mode varchar(20) NOT NULL default 'exam',
			practice_reveal varchar(20) NOT NULL default 'on_submit',
			layout varchar(20) NOT NULL default 'single_page',
			review_before_submit tinyint(1) NOT NULL default 1,
			allow_early_finish tinyint(1) NOT NULL default 1,
			fullscreen_mode varchar(20) NOT NULL default 'off',
			require_answer varchar(10) NOT NULL default 'off',
			allow_pause tinyint(1) NOT NULL default 0,
			max_pauses int(11) NOT NULL default 0,
			max_pause_minutes int(11) NOT NULL default 0,
			auto_close_action varchar(20) NOT NULL default 'submit',
			idle_timeout_minutes int(11) NOT NULL default 0,
			window_close_action varchar(20) NOT NULL default 'close',
			auto_close_held tinyint(1) NOT NULL default 0,
			auto_close_time_spent varchar(20) NOT NULL default 'capped',
			auto_close_notify varchar(20) NOT NULL default 'auto',
			track_integrity tinyint(1) NOT NULL default 1,
			certificate_template_id bigint(20) unsigned NOT NULL default 0,
			settings longtext,
			custom_fields longtext,
			published tinyint(1) NOT NULL default 1,
			{$audit}
			PRIMARY KEY  (id),
			KEY subject_id (subject_id),
			KEY access_type (access_type),
			KEY slug (slug(191)),
			KEY deleted_at (deleted_at),
			KEY catalogue (published,open_from,open_until)
		) {$charset};";
	}

	/**
	 * A section: either an explicit list of questions, or a rule for drawing them.
	 */
	private static function exam_sections(): string {
		$table   = Db::table( 'exam_sections' );
		$charset = Db::charset();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			exam_id bigint(20) unsigned NOT NULL default 0,
			title varchar(255) NOT NULL default '',
			description text,
			ordering int(11) NOT NULL default 0,
			section_type varchar(10) NOT NULL default 'fixed',
			points_per_question decimal(8,2) default NULL,
			PRIMARY KEY  (id),
			KEY exam_ordering (exam_id,ordering)
		) {$charset};";
	}

	/**
	 * Questions explicitly placed in a fixed section.
	 *
	 * `UNIQUE (section_id, question_id)` rather than `(exam_id, question_id)`: the same
	 * question may legitimately appear in two different sections of one exam — a warm-up
	 * and a review, say — but twice in the same section is always a mistake.
	 */
	private static function exam_questions(): string {
		$table   = Db::table( 'exam_questions' );
		$charset = Db::charset();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			exam_id bigint(20) unsigned NOT NULL default 0,
			section_id bigint(20) unsigned NOT NULL default 0,
			question_id bigint(20) unsigned NOT NULL default 0,
			ordering int(11) NOT NULL default 0,
			points decimal(8,2) default NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY section_question (section_id,question_id),
			KEY exam_id (exam_id),
			KEY question_id (question_id)
		) {$charset};";
	}

	/**
	 * A draw rule for a pool section.
	 *
	 * `subject_id` matches the whole SUBTREE, not one node — a rule saying "any question in
	 * Mathematics" is expected to reach Algebra and Quadratics. That expansion happens in
	 * the service, through the `plugixa_exam_subject_subtree_ids` filter, so this module
	 * never names the Subjects module.
	 */
	private static function exam_pool_rules(): string {
		$table   = Db::table( 'exam_pool_rules' );
		$charset = Db::charset();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			exam_id bigint(20) unsigned NOT NULL default 0,
			section_id bigint(20) unsigned NOT NULL default 0,
			subject_id bigint(20) unsigned default NULL,
			difficulty tinyint(3) unsigned default NULL,
			question_type varchar(30) default NULL,
			draw_count int(11) NOT NULL default 1,
			points_per_question decimal(8,2) default NULL,
			filter_json longtext,
			PRIMARY KEY  (id),
			KEY exam_id (exam_id),
			KEY section_id (section_id)
		) {$charset};";
	}
}
