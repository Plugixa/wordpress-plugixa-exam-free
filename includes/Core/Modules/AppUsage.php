<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Modules;

use PlugixaExam\Core\Database\InList;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How much finished work a format-owning app is holding up.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THIS EXISTS SO ONE TOGGLE CANNOT QUIETLY ZERO A ROOM FULL OF CANDIDATES.
 *
 * A module contributes its question formats through a hook, so a module whose hooks are not
 * registered contributes none — and `AttemptService::sanitize_response()` DISCARDS an answer
 * whose format nobody recognises, which grades it null and scores the candidate zero without
 * a word in any log. Measured, with a control: `app_advancedtypes` on, a perfect answer to a
 * real ordering question grades 1; switched off, the same answer grades null.
 *
 * That is the same thing a free downgrade does, and is accepted there — but a downgrade is a
 * build change somebody plans, and this is a switch on a settings screen. So the screen has
 * to be able to say what the switch costs BEFORE it is thrown.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ THE EXAM COUNT IS A FLOOR, NOT A TOTAL, AND THE WORDING DEPENDS ON KNOWING THAT.
 *
 * It counts published exams that PLACE one of these questions explicitly. An exam can also
 * reach them through a random pool rule, which selects by subject, tag, difficulty or format
 * at the moment an attempt starts — a rule naming no format draws whatever the bank holds,
 * so whether it would draw one of these depends on a question that has not been asked yet.
 * There is no honest way to count that in advance.
 *
 * The QUESTION count is exact, which is why the warning leads with it.
 */
final class AppUsage {

	/**
	 * Question and published-exam counts for every app that owns formats.
	 *
	 * One query for the formats, one for the exams, rather than a pair per app: three apps
	 * today is not a reason to write something that degrades when there are ten.
	 *
	 * @param array<string, array<string, mixed>> $registry {@see AppRegistry::registry()}.
	 *
	 * @return array<string, array{questions: int, exams: int, types: string[]}>
	 */
	public function for_apps( array $registry ): array {
		global $wpdb;

		$owned = array();

		foreach ( $registry as $id => $app ) {
			$types = array_values( (array) ( $app['types'] ?? array() ) );

			if ( array() !== $types ) {
				$owned[ $id ] = $types;
			}
		}

		if ( array() === $owned ) {
			return array();
		}

		/*
		 * One comma-joined set of type slugs for `FIND_IN_SET()`, so both statements below are
		 * literals — see `InList` for why a generated `IN ( %s, %s, … )` is not. No `BETWEEN`
		 * half: a slug has no range an index could serve.
		 */
		$set = InList::strings( array_merge( ...array_values( $owned ) ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$by_type = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT question_type, COUNT(*) AS n FROM %i WHERE deleted_at IS NULL AND FIND_IN_SET( question_type, %s ) GROUP BY question_type',
				Db::table( 'questions' ),
				$set
			),
			ARRAY_A
		);

		/*
		 * DISTINCT on the exam, not on the row: an exam placing four ordering questions is
		 * one exam at risk, not four. Deleted and unpublished exams are excluded because a
		 * draft nobody can sit is not work this switch would break.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables, no object cache to read from.
		$exam_rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT q.question_type, COUNT( DISTINCT e.id ) AS n FROM %i q INNER JOIN %i p ON p.question_id = q.id INNER JOIN %i e ON e.id = p.exam_id WHERE q.deleted_at IS NULL AND e.deleted_at IS NULL AND e.published = 1 AND FIND_IN_SET( q.question_type, %s ) GROUP BY q.question_type',
				Db::table( 'questions' ),
				Db::table( 'exam_questions' ),
				Db::table( 'exams' ),
				$set
			),
			ARRAY_A
		);

		$question_counts = array();
		foreach ( $by_type as $row ) {
			$question_counts[ (string) $row['question_type'] ] = (int) $row['n'];
		}

		$exam_counts = array();
		foreach ( $exam_rows as $row ) {
			$exam_counts[ (string) $row['question_type'] ] = (int) $row['n'];
		}

		$out = array();

		foreach ( $owned as $id => $types ) {
			$questions_total = 0;

			foreach ( $types as $type ) {
				$questions_total += $question_counts[ $type ] ?? 0;
			}

			/*
			 * The per-format exam counts cannot simply be added — one exam may ask an
			 * ordering question AND a matching one, and would be counted twice. The largest
			 * single format is the tightest figure derivable from these two queries without
			 * a third, and it is a floor, which is the direction a warning should err in.
			 */
			$exams_total = 0;

			foreach ( $types as $type ) {
				$exams_total = max( $exams_total, $exam_counts[ $type ] ?? 0 );
			}

			$out[ $id ] = array(
				'questions' => $questions_total,
				'exams'     => $exams_total,
				'types'     => $types,
			);
		}

		return $out;
	}
}
