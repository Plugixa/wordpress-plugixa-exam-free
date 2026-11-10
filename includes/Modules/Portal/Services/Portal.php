<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Portal\Services;

use PlugixaExam\Core\Database\Schema as Db;
use PlugixaExam\Core\Frontend\Router;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;
use PlugixaExam\Modules\Attempts\Services\Outcome;
use PlugixaExam\Modules\Exams\Repositories\ExamRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a learner sees about their own exams, results and certificates.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * A SCORE IS RELEASED OR IT IS NOT, AND "NOT YET" IS A STATE THIS RENDERS ON PURPOSE.
 *
 * Every row carries `released`, and an unreleased row carries NO percent, NO pass flag and
 * no link to a result page. Sending the number with a flag saying "do not show this" would
 * put every withheld score in the page source of a learner's own dashboard, which is the
 * one place somebody is certain to look.
 *
 * The release rule is duplicated from `AttemptService::results_released()` rather than
 * called, because that method is private to the free build's grading path. The Joomla
 * original duplicates it too and its copy was missing `after_close`, so an exam whose
 * window had closed said "awaiting results" for ever. If you change one, change the other —
 * and there is a test that fails when they disagree.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ASSIGNMENTS AND CERTIFICATES COME THROUGH FILTERS, not through the modules that own them.
 * "Which exams am I expected to sit" is `exam_access`, which belongs to Cohorts; "what have
 * I earned" belongs to Certificates. Either may be switched off, and a portal that fatally
 * depended on both would be a screen that disappears when an unrelated licence lapses.
 * Absent module, empty section, no branch.
 */
final class Portal {

	/**
	 * @param Router $router Front-end links.
	 */
	/**
	 * @param Router            $router   Link builder.
	 * @param ExamRepository    $exams    Where the release policy lives.
	 * @param AttemptRepository $attempts Where a learner's own history is counted.
	 */
	public function __construct(
		private readonly Router $router,
		private readonly ExamRepository $exams,
		private readonly AttemptRepository $attempts
	) {}

	/**
	 * Everything the portal screen renders, for one learner.
	 *
	 * @param int $user_id The learner.
	 *
	 * @return array<string, mixed>
	 */
	public function overview( int $user_id ): array {
		if ( 0 === $user_id ) {
			return array(
				'exams'        => array( 'available' => array(), 'upcoming' => array(), 'closed' => array() ),
				'results'      => array(),
				'certificates' => array(),
			);
		}

		$overview = array(
			'exams'        => $this->assignments( $user_id ),
			'results'      => $this->results( $user_id ),
			'certificates' => $this->certificates( $user_id ),
		);

		/**
		 * Extra sections on the learner's dashboard.
		 *
		 * ────────────────────────────────────────────────────────────────────────────────
		 * A GENERIC SEAM, NOT ONE NAMED AFTER ITS FIRST LISTENER.
		 *
		 * The two filters above are named for the DATA they carry, because the portal
		 * renders both itself and knows their shape. This one carries whatever a module
		 * wants to add, and it stays generic on purpose: a filter name is a string, and a
		 * free build must not ship strings naming the features it does not have. Naming a
		 * seam after the one listener that exists today also makes the second one a
		 * second seam.
		 * ────────────────────────────────────────────────────────────────────────────────
		 *
		 * @param array<string, mixed> $overview What the portal has assembled so far.
		 * @param int                  $user_id  The learner.
		 */
		return (array) apply_filters( 'plugixa_exam_portal_overview', $overview, $user_id );
	}

	/**
	 * Exams somebody has been assigned, split by whether they can sit them now.
	 *
	 * The effective window is the ACCESS RULE's, falling back to the exam's own: a rule with
	 * no dates means "whenever the exam is open", which is what an author who left them
	 * blank meant. See `Cohorts\Schema::access()`.
	 *
	 * @param int $user_id The learner.
	 *
	 * @return array{available: array<int, array<string, mixed>>, upcoming: array<int, array<string, mixed>>, closed: array<int, array<string, mixed>>}
	 */
	public function assignments( int $user_id ): array {
		$out = array( 'available' => array(), 'upcoming' => array(), 'completed' => array(), 'closed' => array() );

		/**
		 * Exams assigned to a learner.
		 *
		 * Answered by Cohorts, which owns `exam_access`. With that module absent nothing
		 * answers and the section is empty, which is the honest reading: no rules exist, so
		 * nothing has been assigned to anybody.
		 *
		 * @param array<int, array<string, mixed>> $rows    Accumulated assignments.
		 * @param int                              $user_id The learner.
		 */
		$rows = $this->fold( (array) apply_filters( 'plugixa_exam_portal_assignments', array(), $user_id ) );

		$now       = time();
		$outcomes  = $this->outcomes( $user_id, $rows );

		foreach ( $rows as $row ) {
			$from  = plugixa_exam_timestamp( $row['available_from'] ?? null )
				?: plugixa_exam_timestamp( $row['open_from'] ?? null );
			$until = plugixa_exam_timestamp( $row['available_until'] ?? null )
				?: plugixa_exam_timestamp( $row['open_until'] ?? null );

			$exam_id = (int) $row['exam_id'];
			$outcome = $outcomes[ $exam_id ] ?? array( 'state' => Outcome::NONE, 'percent' => null, 'attempts' => 0, 'submitted' => null );

			$entry = array(
				'exam_id' => $exam_id,
				'title'   => (string) $row['title'],
				'url'     => $this->router->exam_url( (string) $row['slug'] ),
				'from'    => $from > 0 ? gmdate( 'Y-m-d H:i:s', $from ) : null,
				'until'   => $until > 0 ? gmdate( 'Y-m-d H:i:s', $until ) : null,
				'due_at'  => $row['due_at'] ?? null,
				'outcome' => $outcome,
			);

			/*
			 * ⚠️ A PASSED EXAM LEAVES "READY TO SIT", AND THAT ORDER OF TESTS IS THE FIX.
			 *
			 * This list grouped by date window alone, so an exam somebody passed yesterday
			 * still sat under "Ready to sit" beside a live Start link — which is exactly the
			 * "why am I being asked to do this again?" that was reported.
			 *
			 * Only PASSED moves. A failed attempt with tries left genuinely is ready to sit,
			 * and burying it under "Completed" would hide the retake; it keeps its bucket
			 * and carries its badge instead. Closed still wins over everything, because a
			 * window that has shut is a fact about the exam rather than about the learner.
			 */
			if ( $until > 0 && $now > $until ) {
				$out['closed'][] = $entry;
			} elseif ( Outcome::PASSED === $outcome['state'] ) {
				$out['completed'][] = $entry;
			} elseif ( $from > 0 && $now < $from ) {
				$out['upcoming'][] = $entry;
			} else {
				$out['available'][] = $entry;
			}
		}

		return $out;
	}

	/**
	 * One row per exam, whatever the listeners sent.
	 *
	 * ⚠️ SEVERAL LISTENERS CAN NAME THE SAME EXAM, AND THE PAGE LISTED IT ONCE PER LISTENER.
	 * A learner whose cohort was assigned an exam that they had also been given access to
	 * some other way saw it twice — two identical rows with two Start links. So rows are
	 * folded here, by exam, into the WIDEST window they add up to: somebody entitled from
	 * two directions may sit it whenever either would allow. An open end (no date) is the
	 * widest there is and wins, on either side. The first row's `due_at` is kept, since only
	 * one listener sets one today.
	 *
	 * @param array<int, array<string, mixed>> $rows Assignment rows from the filter.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fold( array $rows ): array {
		$folded = array();

		foreach ( $rows as $row ) {
			$exam_id = (int) ( $row['exam_id'] ?? 0 );

			if ( $exam_id <= 0 ) {
				continue;
			}

			if ( ! isset( $folded[ $exam_id ] ) ) {
				$folded[ $exam_id ] = $row;
				continue;
			}

			$held = $folded[ $exam_id ];

			foreach ( array( 'available_from' => 'min', 'available_until' => 'max' ) as $column => $widest ) {
				$a = plugixa_exam_timestamp( $held[ $column ] ?? null );
				$b = plugixa_exam_timestamp( $row[ $column ] ?? null );

				$held[ $column ] = 0 === $a || 0 === $b
					? null
					: gmdate( 'Y-m-d H:i:s', 'min' === $widest ? min( $a, $b ) : max( $a, $b ) );
			}

			$held['due_at']     = $held['due_at'] ?? ( $row['due_at'] ?? null );
			$folded[ $exam_id ] = $held;
		}

		return array_values( $folded );
	}

	/**
	 * Where the learner stands on each assigned exam.
	 *
	 * ⚠️ THE EXAM ROWS ARE FETCHED RATHER THAN TAKEN FROM `$rows`, and that is not caution
	 * for its own sake. `plugixa_exam_portal_assignments` is a public seam: Cohorts answers
	 * it today with a projection carrying `title`, `slug` and the two window columns, and
	 * nothing obliges the next answerer to carry `result_release` or `release_at`. Reading
	 * the release policy off whatever a filter happened to send would make the badge depend
	 * on a shape nobody promised — and the failure would be silent and in the wrong
	 * direction only if the defaults were wrong, which is not a thing to rely on.
	 *
	 * @param int                              $user_id The learner.
	 * @param array<int, array<string, mixed>> $rows    Assignment rows from the filter.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function outcomes( int $user_id, array $rows ): array {
		$ids = array_values( array_unique( array_map( static fn ( array $row ): int => (int) $row['exam_id'], $rows ) ) );

		if ( $user_id <= 0 || array() === $ids ) {
			return array();
		}

		$aggregates = $this->attempts->outcomes_for_user( $user_id, $ids );
		$out        = array();

		foreach ( $ids as $exam_id ) {
			$exam = $this->exams->find( $exam_id );

			if ( null === $exam ) {
				continue;
			}

			$out[ $exam_id ] = Outcome::of( $exam, $aggregates[ $exam_id ] ?? null );
		}

		return $out;
	}

	/**
	 * A learner's own attempts, newest first.
	 *
	 * @param int $user_id The learner.
	 * @param int $limit   How many.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function results( int $user_id, int $limit = 50 ): array {
		global $wpdb;

		$attempts = Db::table( 'attempts' );
		$exams    = Db::table( 'exams' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables; a learner's own results must not be served from cache.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT a.id, a.exam_id, a.status, a.percent, a.passed, a.is_graded, a.submitted,
					e.title AS exam_title, e.result_release, e.release_at, e.open_until
				FROM %i a
				INNER JOIN %i e ON e.id = a.exam_id
				WHERE a.user_id = %d AND a.status <> %s
				ORDER BY a.submitted DESC, a.id DESC
				LIMIT %d',
				$attempts,
				$exams,
				$user_id,
				'in_progress',
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		);

		$out = array();

		foreach ( $rows as $row ) {
			$released = (bool) (int) $row['is_graded'] && $this->window_open( $row );

			$entry = array(
				'attempt_id' => (int) $row['id'],
				/*
				 * Carried so the title beside it can be spoken about. The assignments list
				 * above has always had an `exam_id` and this one had not, which made a
				 * result the one place on the portal where the exam could not be identified
				 * — a listener translating exam titles could decorate every section except
				 * this one. The value is the learner's own attempt's exam; nothing about it
				 * is more private than the title already printed next to it.
				 */
				'exam_id'    => (int) $row['exam_id'],
				'exam_title' => (string) $row['exam_title'],
				'status'     => (string) $row['status'],
				'submitted'  => $row['submitted'],
				'released'   => $released,
			);

			/*
			 * The numbers exist ONLY on a released row. A withheld score sent with a flag is
			 * still a withheld score in the page source, and this is a learner's own screen —
			 * the one page they will certainly open the network tab on.
			 */
			if ( $released ) {
				$entry['percent'] = null === $row['percent'] ? null : round( (float) $row['percent'], 1 );
				$entry['passed']  = null === $row['passed'] ? null : (bool) (int) $row['passed'];
				$entry['url']     = $this->router->result_url( (int) $row['id'] );
			}

			$out[] = $entry;
		}

		return $out;
	}

	/**
	 * Whether an exam's release moment has passed.
	 *
	 * Mirrors `AttemptService::results_released()`. `after_close` is spelled out because the
	 * original's copy of this omitted it and reported "awaiting results" for ever.
	 *
	 * @param array<string, mixed> $row An attempt joined to its exam.
	 */
	private function window_open( array $row ): bool {
		$now = current_time( 'mysql', true );

		return match ( (string) $row['result_release'] ) {
			'immediate'   => true,
			'after_close' => ! empty( $row['open_until'] ) && $now > $row['open_until'],
			'scheduled'   => ! empty( $row['release_at'] ) && $now > $row['release_at'],
			// `manual` withholds until somebody releases it, which is the safe direction.
			default       => false,
		};
	}

	/**
	 * Certificates a learner has earned.
	 *
	 * @param int $user_id The learner.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function certificates( int $user_id ): array {
		/**
		 * A learner's certificates.
		 *
		 * Answered by the Certificates module. Absent module, empty section — see the class
		 * docblock for why this is a filter rather than a call.
		 *
		 * @param array<int, array<string, mixed>> $rows    Accumulated certificates.
		 * @param int                              $user_id The learner.
		 */
		return (array) apply_filters( 'plugixa_exam_portal_certificates', array(), $user_id );
	}
}
