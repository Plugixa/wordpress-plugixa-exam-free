<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Repositories;

use PlugixaExam\Core\Database\InList;
use PlugixaExam\Core\Database\QueryBuilder;
use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for attempts and their responses.
 */
final class AttemptRepository extends Repository {

	public function table(): string {
		return 'attempts';
	}

	public function entity_type(): string {
		return 'attempt';
	}

	/**
	 * Attempts are never trashed. See Schema::attempts().
	 */
	public function soft_deletes(): bool {
		return false;
	}

	/**
	 * @return string[]
	 */
	public function fillable(): array {
		return array(
			'exam_id', 'user_id', 'guest_token', 'guest_name', 'guest_email',
			'attempt_number', 'status', 'started', 'expires_at', 'paused_at',
			'time_remaining', 'pause_count', 'paused_total', 'submitted', 'time_spent',
			'score', 'max_score', 'percent', 'passed', 'is_graded',
			'extra_attempts_granted', 'grader_comment', 'last_graded_by', 'last_graded_at',
			'question_set', 'integrity_signals', 'review_flags',
			'last_seen', 'current_question_id', 'monitor_note', 'monitor_hold',
			'closure_reason',
		);
	}

	/**
	 * @return string[]
	 */
	public function sortable(): array {
		return array( 'id', 'started', 'submitted', 'percent', 'status', 'created_at' );
	}

	/**
	 * The statuses a paper can be in when it is finished and still needs a person.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * `submitted` IS NOT THE ONLY ONE, AND ASSUMING IT WAS HID FOUR PAPERS IN FIVE.
	 *
	 * The marking queue used to be `status = 'submitted' AND is_graded = 0`, which reads
	 * correctly and is almost always wrong. `AttemptService::closing_status()` returns
	 * `locked_review` rather than `submitted` whenever an attempt is not fully graded under
	 * the lock policy `auto` — and `auto` is the DEFAULT. So under the shipped configuration
	 * every essay awaiting a marker lands in the one status the queue excluded, and the
	 * screen a grader works from showed them nothing while the work piled up.
	 *
	 * Found by building a dashboard tile beside the queue and noticing the two numbers
	 * disagreed with the database.
	 *
	 * Stated once, here, because two callers ask this question — the list route's
	 * `needs_grading` filter and the dashboard summary — and a queue whose count and whose
	 * rows come from different definitions is worse than either being wrong.
	 *
	 * A LIST RATHER THAN `status <> 'abandoned'`: the leading column of `status_graded` can
	 * seek an `IN`, and cannot seek a negation. The vocabulary is closed — `closing_status()`
	 * returns exactly `graded`, `submitted` or `locked_review` — so a new member is a
	 * deliberate addition here rather than something to be caught by a scan.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @var string[]
	 */
	public const AWAITING_MARK = array( 'submitted', 'locked_review' );

	/**
	 * Two, and `Services\Summary` writes `IN (%s, %s)` as a literal on the strength of it.
	 *
	 * A generated placeholder list costs a `PreparedSQL` suppression, which is the one kind
	 * of suppression a WordPress.org reviewer reads closely — so the query spells the pair
	 * out, and `SummaryTest` asserts this count so a third status makes a test fail rather
	 * than making a total silently wrong.
	 */
	public const AWAITING_MARK_COUNT = 2;

	/**
	 * Narrow a query to the papers waiting for a marker.
	 *
	 * @param QueryBuilder $query The query being built.
	 */
	public function only_awaiting_mark( QueryBuilder $query ): void {
		$query
			->where( 't.is_graded = %d', array( 0 ) )
			->where_in( 't.status', self::AWAITING_MARK, '%s' );
	}

	/**
	 * How many attempts an examinee has on an exam.
	 *
	 * COUNTS `in_progress` TOO, and that is not an oversight. An examinee who opens a paper,
	 * sees the questions and closes the tab has used an attempt — otherwise the cap is
	 * trivially defeated by never submitting. The auto-close sweep is what stops that from
	 * being unfair: an abandoned attempt gets finalised rather than sitting open for ever.
	 *
	 * @param int         $exam_id Exam id.
	 * @param int|null    $user_id User id, or null for a guest.
	 * @param string|null $token   Guest token, when there is no user.
	 */
	public function count_attempts( int $exam_id, ?int $user_id, ?string $token ): int {
		$query = $this->query()->where( 't.exam_id = %d', array( $exam_id ) );

		if ( null !== $user_id && $user_id > 0 ) {
			$query->where( 't.user_id = %d', array( $user_id ) );
		} elseif ( null !== $token && '' !== $token ) {
			$query->where( 't.guest_token = %s', array( $token ) );
		} else {
			return 0;
		}

		return $query->count();
	}

	/**
	 * Where one learner stands on many exams, in ONE query.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * ⚠️ THIS IS A LIST LOOKUP, AND A PER-ROW VERSION OF IT WOULD BE A HUNDRED QUERIES.
	 *
	 * The catalogue paginates to 100 exams and the portal draws every assignment somebody
	 * has. Answering "have I done this one?" per card is the shape that looks harmless in
	 * development, where the seed data is six rows. `KEY exam_user (exam_id,user_id)` has
	 * existed since phase 1 and is exactly the index this needs.
	 *
	 * The list is `exam_id BETWEEN %d AND %d AND FIND_IN_SET( exam_id, %s )` — three bound
	 * values whatever the number of exams, so the statement is one literal. The `BETWEEN` is
	 * what lets that index serve it; see {@see InList}.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * ⚠️ NOT A REPLACEMENT FOR `Prerequisites\Services\Requirements::best_attempt()`, which
	 * asks a similar-looking question for a different purpose — see the note there. That one
	 * gates ACCESS, covers guests, and counts an ungraded pass; this one feeds a BADGE, is
	 * account-only, and does not.
	 *
	 * ⚠️ IT RETURNS COUNTS, NOT A VERDICT. Whether a mark may be SHOWN depends on the exam's
	 * release policy, which is not on this table — {@see \PlugixaExam\Modules\Attempts\Services\Outcome}
	 * applies that. A repository that guessed would be a second place the rule lives.
	 *
	 * @param int        $user_id  The learner. Zero returns nothing: a signed-out visitor has
	 *                             no history, and asking would scan the table for user 0.
	 * @param array<int> $exam_ids The exams on screen.
	 *
	 * @return array<int, array<string, mixed>> Keyed by exam id; absent means never attempted.
	 */
	public function outcomes_for_user( int $user_id, array $exam_ids ): array {
		global $wpdb;

		[ $lowest, $highest, $set ] = InList::ids( $exam_ids );

		if ( $user_id <= 0 || '' === $set ) {
			return array();
		}

		/*
		 * `in_progress` is written into the literal rather than bound six times: it is a fixed
		 * state name, not a value that varies, and a bound constant is six more arguments for
		 * the placeholder count to get wrong.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT exam_id,
					SUM( CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END ) AS open,
					SUM( CASE WHEN status <> 'in_progress' THEN 1 ELSE 0 END ) AS finished,
					SUM( CASE WHEN status <> 'in_progress' AND is_graded = 0 THEN 1 ELSE 0 END ) AS ungraded,
					SUM( CASE WHEN status <> 'in_progress' AND is_graded = 1 AND passed = 1 THEN 1 ELSE 0 END ) AS passed,
					MAX( CASE WHEN status <> 'in_progress' AND is_graded = 1 THEN percent ELSE NULL END ) AS best_percent,
					MAX( CASE WHEN status <> 'in_progress' THEN submitted ELSE NULL END ) AS last_submitted
				FROM %i
				WHERE user_id = %d AND exam_id BETWEEN %d AND %d AND FIND_IN_SET( exam_id, %s )
				GROUP BY exam_id",
				Db::table( 'attempts' ),
				$user_id,
				$lowest,
				$highest,
				$set
			),
			ARRAY_A
		);

		$out = array();

		foreach ( $rows as $row ) {
			$out[ (int) $row['exam_id'] ] = array(
				'open'           => (int) $row['open'],
				'finished'       => (int) $row['finished'],
				'ungraded'       => (int) $row['ungraded'],
				'passed'         => (int) $row['passed'],
				'best_percent'   => null === $row['best_percent'] ? null : (float) $row['best_percent'],
				'last_submitted' => (string) ( $row['last_submitted'] ?? '' ),
			);
		}

		return $out;
	}

	/**
	 * The examinee's most recent attempt on an exam.
	 *
	 * @param int         $exam_id Exam id.
	 * @param int|null    $user_id User id.
	 * @param string|null $token   Guest token.
	 *
	 * @return array<string, mixed>|null
	 */
	public function latest_for( int $exam_id, ?int $user_id, ?string $token ): ?array {
		$query = $this->query()->where( 't.exam_id = %d', array( $exam_id ) );

		if ( null !== $user_id && $user_id > 0 ) {
			$query->where( 't.user_id = %d', array( $user_id ) );
		} elseif ( null !== $token && '' !== $token ) {
			$query->where( 't.guest_token = %s', array( $token ) );
		} else {
			return null;
		}

		$row = $query->order_by( 't.id', 'DESC' )->first();

		return null === $row ? null : $this->cast( $row );
	}

	/**
	 * An attempt still open for this examinee on this exam.
	 *
	 * @param int         $exam_id Exam id.
	 * @param int|null    $user_id User id.
	 * @param string|null $token   Guest token.
	 *
	 * @return array<string, mixed>|null
	 */
	public function open_attempt( int $exam_id, ?int $user_id, ?string $token ): ?array {
		$latest = $this->latest_for( $exam_id, $user_id, $token );

		return null !== $latest && 'in_progress' === $latest['status'] ? $latest : null;
	}

	/**
	 * Finalise an attempt exactly once.
	 *
	 * A CONDITIONAL UPDATE USED AS A LOCK, and the whole reason submission is safe.
	 *
	 * An attempt can be finalised by two things at the same moment: the examinee pressing
	 * Submit, and the auto-close sweep deciding the deadline has passed. Both read the row,
	 * both see `in_progress`, and both write a result — so the examinee gets two "your
	 * submission has been received" emails, two webhook deliveries, and possibly two
	 * certificates.
	 *
	 * `WHERE status = 'in_progress'` makes the transition atomic: MySQL applies one UPDATE
	 * or the other first, and the loser matches zero rows. The caller fires notifications
	 * only when this returns true.
	 *
	 * @param int                  $id     Attempt id.
	 * @param array<string, mixed> $data   Columns to write.
	 *
	 * @return bool Whether this caller was the one that closed it.
	 */
	public function finalize_once( int $id, array $data ): bool {
		global $wpdb;

		$data['modified_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->update prepares. The extra WHERE is the lock.
		$updated = $wpdb->update(
			$this->full_table(),
			$data,
			array(
				'id'     => $id,
				'status' => 'in_progress',
			)
		);

		return is_int( $updated ) && $updated > 0;
	}

	/* -------------------------------------------------------------- responses */

	/**
	 * Write a paper's answers in as few statements as a literal query allows.
	 *
	 * `INSERT … ON DUPLICATE KEY UPDATE`, which the UNIQUE key on
	 * `(attempt_id, question_id)` exists to make possible. Autosave runs on a debounce timer
	 * for every examinee in the room simultaneously, so a statement per question is the shape
	 * that does not scale.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THREE FIXED-SIZE STATEMENTS — 25 ROWS, 5 ROWS, 1 ROW — WRITTEN OUT AS LITERALS.
	 *
	 * This used to be ONE statement whose `VALUES` groups were generated from the number of
	 * answers. That bound every value and was still a query assembled at runtime, which is the
	 * form WordPress.org's review rejects whatever it evaluates to. A literal has a fixed number
	 * of rows, so a paper is written as full 25-row blocks, then 5-row blocks, then single rows:
	 * a 100-question paper is 4 statements, and no paper size ever needs more than 8 beyond its
	 * 25-row blocks. plugixa-crm's queue made the same move with one 50-row block and single
	 * rows; a middle size is here because a debounced autosave writes a remainder EVERY time.
	 *
	 * Short blocks are never padded with repeated rows: the second copy would be an update of
	 * the first, which is harmless today and one schema change away from not being.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param int                              $attempt_id Attempt id.
	 * @param array<int, array<string, mixed>> $rows       Response rows.
	 */
	public function save_responses( int $attempt_id, array $rows ): void {
		if ( array() === $rows ) {
			return;
		}

		$values = array();

		foreach ( $rows as $row ) {
			$values[] = array(
				$attempt_id,
				(int) $row['question_id'],
				(string) $row['question_type'],
				null === $row['response'] ? null : (string) wp_json_encode( $row['response'] ),
				(int) ( $row['ordering'] ?? 0 ),
			);
		}

		$table = Db::table( 'attempt_responses' );

		foreach ( array_chunk( $values, 25 ) as $block ) {
			if ( 25 === count( $block ) ) {
				$this->write_responses_25( $table, array_merge( ...$block ) );
				continue;
			}

			foreach ( array_chunk( $block, 5 ) as $small ) {
				if ( 5 === count( $small ) ) {
					$this->write_responses_5( $table, array_merge( ...$small ) );
					continue;
				}

				foreach ( $small as $single ) {
					$this->write_response( $table, $single );
				}
			}
		}
	}

	/**
	 * Exactly twenty-five response rows in one statement.
	 *
	 * Only the answer and its position are updated on a duplicate. Scores are NOT touched: an
	 * autosave arriving after grading must not blank a mark a human has given. The same rule is
	 * written into all three statements.
	 *
	 * @param string            $table  The responses table.
	 * @param array<int, mixed> $values Five values per row, in column order, for twenty-five rows.
	 */
	private function write_responses_25( string $table, array $values ): void {
		global $wpdb;

		/*
		 * ReplacementsWrongNumber is ignored on the prepare() line, and only there: the sniff
		 * counts an array of arguments as ONE replacement, so 126 real arguments — the table and
		 * five values for each of twenty-five rows — read as 1 of 126. It is the placeholder-COUNT
		 * sniff, not a prepared-SQL one; the statement is a literal.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->insert() cannot write many rows or express ON DUPLICATE KEY UPDATE.
		$wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- see above: 126 arguments passed as one array.
				'INSERT INTO %i (attempt_id, question_id, question_type, response, ordering) VALUES
				(%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d),
				(%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d),
				(%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d),
				(%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d),
				(%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d)
				ON DUPLICATE KEY UPDATE response = VALUES(response), ordering = VALUES(ordering)',
				array_merge( array( $table ), $values )
			)
		);
	}

	/**
	 * Exactly five response rows in one statement.
	 *
	 * @param string            $table  The responses table.
	 * @param array<int, mixed> $values Five values per row, in column order, for five rows.
	 */
	private function write_responses_5( string $table, array $values ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->insert() cannot write many rows or express ON DUPLICATE KEY UPDATE.
		$wpdb->query(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- 26 arguments passed as one array; see write_responses_25().
				'INSERT INTO %i (attempt_id, question_id, question_type, response, ordering) VALUES
				(%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d), (%d, %d, %s, %s, %d)
				ON DUPLICATE KEY UPDATE response = VALUES(response), ordering = VALUES(ordering)',
				array_merge( array( $table ), $values )
			)
		);
	}

	/**
	 * One response row.
	 *
	 * @param string            $table The responses table.
	 * @param array<int, mixed> $row   The row's five values, in column order.
	 */
	private function write_response( string $table, array $row ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->insert() cannot express ON DUPLICATE KEY UPDATE.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (attempt_id, question_id, question_type, response, ordering) VALUES (%d, %d, %s, %s, %d)
				ON DUPLICATE KEY UPDATE response = VALUES(response), ordering = VALUES(ordering)',
				$table,
				$row[0],
				$row[1],
				$row[2],
				$row[3],
				$row[4]
			)
		);
	}

	/**
	 * An attempt's responses, keyed by question id.
	 *
	 * @param int $attempt_id Attempt id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function responses( int $attempt_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE attempt_id = %d ORDER BY ordering ASC, id ASC',
				Db::table( 'attempt_responses' ),
				$attempt_id
			),
			ARRAY_A
		);

		$map = array();

		foreach ( $rows as $row ) {
			$decoded = json_decode( (string) $row['response'], true );

			$map[ (int) $row['question_id'] ] = array(
				'id'              => (int) $row['id'],
				'question_id'     => (int) $row['question_id'],
				'question_type'   => (string) $row['question_type'],
				'response'        => $decoded,
				'is_correct'      => null === $row['is_correct'] ? null : (int) $row['is_correct'],
				'points_awarded'  => null === $row['points_awarded'] ? null : (float) $row['points_awarded'],
				'points_possible' => (float) $row['points_possible'],
				'auto_graded'     => (bool) (int) $row['auto_graded'],
				'grader_comment'  => $row['grader_comment'],
				'ordering'        => (int) $row['ordering'],
			);
		}

		return $map;
	}

	/**
	 * Write a response's score.
	 *
	 * @param int                  $attempt_id  Attempt id.
	 * @param int                  $question_id Question id.
	 * @param array<string, mixed> $data        Score columns.
	 */
	public function score_response( int $attempt_id, int $question_id, array $data ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->update prepares.
		$wpdb->update(
			Db::table( 'attempt_responses' ),
			$data,
			array(
				'attempt_id'  => $attempt_id,
				'question_id' => $question_id,
			)
		);
	}

	/**
	 * Delete an attempt's responses.
	 *
	 * @param int $attempt_id Attempt id.
	 */
	public function delete_responses( int $attempt_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->delete prepares.
		$wpdb->delete( Db::table( 'attempt_responses' ), array( 'attempt_id' => $attempt_id ) );
	}

	/**
	 * The marked papers of one exam that belong to an account, oldest first.
	 *
	 * For telling people a result is out when an exam's release rule changes. Guests have no
	 * account to tell. Capped: an exam with more marked papers than this is one whose author
	 * should be sending an announcement, not relying on a feed.
	 *
	 * @param int $exam_id Exam id.
	 * @param int $limit   Most rows to return.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function marked_for_exam( int $exam_id, int $limit = 2000 ): array {
		$rows = $this->query()
			->where( 't.exam_id = %d', array( $exam_id ) )
			->where( 't.is_graded = %d', array( 1 ) )
			->where( 't.user_id > %d', array( 0 ) )
			->order_by( 't.id', 'ASC' )
			->paginate( $limit )
			->get();

		return array_map( array( $this, 'cast' ), $rows );
	}

	/**
	 * Attempts the auto-close sweep should consider.
	 *
	 * Seeks on `(status, expires_at)` and `(status, last_seen)`. Without those indexes this
	 * scans every attempt ever taken, on every sweep, and the sweep runs off the monitor
	 * poll.
	 *
	 * @param string $now   Current UTC time, `Y-m-d H:i:s`.
	 * @param int    $limit Batch size.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sweep_candidates( string $now, int $limit = 100 ): array {
		$rows = $this->query()
			->where( 't.status = %s', array( 'in_progress' ) )
			->where( '(t.expires_at IS NOT NULL AND t.expires_at <= %s)', array( $now ) )
			->order_by( 't.expires_at', 'ASC' )
			->paginate( $limit )
			->get();

		return array_map( array( $this, 'cast' ), $rows );
	}

	/**
	 * Attempts that have gone quiet for longer than the exam allows.
	 *
	 * A separate query from {@see sweep_candidates()} because the two use different indexes
	 * and different predicates — and because the idle rule is the only one that reaches an
	 * attempt with no deadline at all.
	 *
	 * @param string $cutoff Oldest acceptable `last_seen`, `Y-m-d H:i:s`.
	 * @param int    $limit  Batch size.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function idle_candidates( string $cutoff, int $limit = 100 ): array {
		$rows = $this->query()
			->where( 't.status = %s', array( 'in_progress' ) )
			->where( 't.last_seen IS NOT NULL AND t.last_seen <= %s', array( $cutoff ) )
			->order_by( 't.last_seen', 'ASC' )
			->paginate( $limit )
			->get();

		return array_map( array( $this, 'cast' ), $rows );
	}

	/**
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		$row = parent::cast( $row );

		foreach ( array( 'exam_id', 'attempt_number', 'pause_count', 'paused_total', 'extra_attempts_granted' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		foreach ( array( 'user_id', 'time_remaining', 'time_spent', 'current_question_id', 'last_graded_by' ) as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = null === $row[ $column ] ? null : (int) $row[ $column ];
			}
		}

		foreach ( array( 'score', 'max_score', 'percent' ) as $column ) {
			if ( array_key_exists( $column, $row ) ) {
				$row[ $column ] = null === $row[ $column ] ? null : (float) $row[ $column ];
			}
		}

		if ( array_key_exists( 'passed', $row ) ) {
			$row['passed'] = null === $row['passed'] ? null : (bool) (int) $row['passed'];
		}

		foreach ( array( 'is_graded', 'monitor_hold' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (bool) (int) $row[ $column ];
			}
		}

		foreach ( array( 'question_set', 'integrity_signals', 'review_flags' ) as $column ) {
			if ( ! array_key_exists( $column, $row ) ) {
				continue;
			}

			$decoded        = json_decode( (string) $row[ $column ], true );
			$row[ $column ] = is_array( $decoded ) ? $decoded : null;
		}

		return $row;
	}
}
