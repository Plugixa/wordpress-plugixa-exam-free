<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts;

use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attempts and their responses.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'attempts', 'attempt_responses' );
	}

	/**
	 * @return array<string, string> What dbDelta changed.
	 */
	public static function install(): array {
		$changes = Db::delta( self::attempts(), self::attempt_responses() );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * One sitting of an exam.
	 *
	 * NO SOFT DELETE. An attempt is a record of something that happened, and a trashed one
	 * still counts toward an examinee's attempt cap unless every count remembers to exclude
	 * it — which one of them eventually will not. Deleting an attempt is deliberate and
	 * final, and the admin UI says so.
	 *
	 * THE TWO COLUMNS THAT CARRY THE MOST WEIGHT:
	 *
	 * `expires_at` is the deadline, computed on the server at start. The client's countdown
	 * is decoration; every write checks this. An examinee who changes their system clock
	 * gains nothing.
	 *
	 * `question_set` freezes the paper — the drawn and ordered question ids, the shuffle
	 * seed, the per-question points and the runner mode — so that editing the exam, or its
	 * questions, cannot change what somebody already sitting it is answering. Without it a
	 * pool section would redraw on every page load.
	 */
	private static function attempts(): string {
		$table   = Db::table( 'attempts' );
		$charset = Db::charset();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			exam_id bigint(20) unsigned NOT NULL default 0,
			user_id bigint(20) unsigned default NULL,
			guest_token varchar(64) default NULL,
			guest_name varchar(255) default NULL,
			guest_email varchar(255) default NULL,
			attempt_number int(11) NOT NULL default 1,
			status varchar(20) NOT NULL default 'in_progress',
			started datetime default NULL,
			expires_at datetime default NULL,
			paused_at datetime default NULL,
			time_remaining int(11) default NULL,
			pause_count int(11) NOT NULL default 0,
			paused_total int(11) NOT NULL default 0,
			submitted datetime default NULL,
			time_spent int(11) default NULL,
			score decimal(8,2) default NULL,
			max_score decimal(8,2) default NULL,
			percent decimal(5,2) default NULL,
			passed tinyint(1) default NULL,
			is_graded tinyint(1) NOT NULL default 0,
			extra_attempts_granted int(11) NOT NULL default 0,
			grader_comment text,
			last_graded_by bigint(20) unsigned default NULL,
			last_graded_at datetime default NULL,
			question_set longtext,
			integrity_signals longtext,
			review_flags longtext,
			last_seen datetime default NULL,
			current_question_id bigint(20) unsigned default NULL,
			monitor_note varchar(255) default NULL,
			monitor_hold tinyint(1) NOT NULL default 0,
			closure_reason varchar(32) default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			created_by bigint(20) unsigned NOT NULL default 0,
			modified_by bigint(20) unsigned NOT NULL default 0,
			PRIMARY KEY  (id),
			KEY exam_user (exam_id,user_id),
			KEY exam_status (exam_id,status),
			KEY guest_token (guest_token),
			KEY submitted (submitted),
			KEY created_at (created_at),
			KEY status_graded (status,is_graded),
			KEY status_percent (status,percent),
			KEY sweep (status,expires_at),
			KEY status_seen (status,last_seen)
		) {$charset};";
	}

	/**
	 * One answer within an attempt.
	 *
	 * THE UNIQUE KEY IS LOAD-BEARING TWICE. It guarantees one row per question per attempt,
	 * and it is what lets the whole paper autosave with a single
	 * `INSERT … ON DUPLICATE KEY UPDATE` instead of one statement per question. On a room of
	 * thirty examinees autosaving every few seconds, that is the difference between one
	 * write and forty per person.
	 *
	 * There is deliberately NO separate index on `attempt_id`: it is this key's leftmost
	 * prefix, and this is by far the hottest write table in the schema.
	 *
	 * `question_type` is denormalised so grading never has to join back to the question —
	 * and so an attempt still grades correctly if the question's type is later unavailable.
	 */
	private static function attempt_responses(): string {
		$table   = Db::table( 'attempt_responses' );
		$charset = Db::charset();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			attempt_id bigint(20) unsigned NOT NULL default 0,
			question_id bigint(20) unsigned NOT NULL default 0,
			question_type varchar(30) NOT NULL default '',
			response longtext,
			is_correct tinyint(1) default NULL,
			points_awarded decimal(8,2) default NULL,
			points_possible decimal(8,2) NOT NULL default 0.00,
			auto_graded tinyint(1) NOT NULL default 0,
			grader_id bigint(20) unsigned default NULL,
			grader_comment text,
			graded_at datetime default NULL,
			rubric_scores longtext,
			ordering int(11) NOT NULL default 0,
			PRIMARY KEY  (id),
			UNIQUE KEY attempt_question (attempt_id,question_id),
			KEY question_id (question_id)
		) {$charset};";
	}
}
