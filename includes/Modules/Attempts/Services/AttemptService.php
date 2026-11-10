<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Services;

use PlugixaExam\Core\Security\GuestToken;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;
use PlugixaExam\Modules\Exams\Repositories\ExamRepository;
use PlugixaExam\Modules\Questions\Repositories\QuestionRepository;
use PlugixaExam\Modules\Questions\Services\QuestionTypes;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The attempt lifecycle: start, autosave, pause, submit, finalize.
 */
final class AttemptService {

	/**
	 * @param AttemptRepository  $attempts  Attempts.
	 * @param ExamRepository     $exams     Exams.
	 * @param QuestionRepository $questions The bank.
	 * @param QuestionTypes      $types     Type registry.
	 * @param PaperBuilder       $papers    Paper builder.
	 * @param AccessGate         $gate      Access gate.
	 * @param Grader             $grader    Grading engine.
	 */
	public function __construct(
		private readonly AttemptRepository $attempts,
		private readonly ExamRepository $exams,
		private readonly QuestionRepository $questions,
		private readonly QuestionTypes $types,
		private readonly PaperBuilder $papers,
		private readonly AccessGate $gate,
		private readonly Grader $grader
	) {}

	/**
	 * Start an attempt, or resume the one already open.
	 *
	 * @param int                  $exam_id Exam id.
	 * @param array<string, mixed> $input   Access-gate credentials.
	 *
	 * @return array<string, mixed>|WP_Error The attempt row.
	 */
	public function start( int $exam_id, array $input = array() ): array|WP_Error {
		$exam = $this->exams->find( $exam_id );

		if ( null === $exam ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Exam not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		$verdict = $this->gate->evaluate( $exam, $input );

		if ( ! $verdict['allowed'] ) {
			return new WP_Error(
				'plugixa_exam_' . $verdict['reason'],
				$verdict['message'],
				array(
					'status'  => 403,
					'verdict' => $verdict,
				)
			);
		}

		if ( isset( $verdict['resume'] ) ) {
			return $this->hydrate( (int) $verdict['resume'] );
		}

		$user_id = get_current_user_id();
		$token   = 0 === $user_id ? GuestToken::ensure() : null;

		$paper = $this->papers->build( $exam );

		if ( array() === $paper['questions'] ) {
			return new WP_Error(
				'plugixa_exam_no_questions',
				__( 'This exam has no questions yet.', 'plugixa-exam' ),
				array( 'status' => 409 )
			);
		}

		$started = current_time( 'mysql', true );

		/*
		 * THE DEADLINE IS COMPUTED HERE, ON THE SERVER, AND NEVER RECOMPUTED FROM CLIENT
		 * INPUT. Every write checks it. The countdown the examinee sees is a convenience;
		 * changing the system clock, or editing the JavaScript, buys nothing.
		 */
		$expires_at = (int) $exam['time_limit'] > 0
			? gmdate( 'Y-m-d H:i:s', strtotime( $started . ' UTC' ) + ( (int) $exam['time_limit'] * 60 ) )
			: null;

		/*
		 * `window_close_action` decides whether the exam's own closing time is a hard stop.
		 * `close` truncates a late starter's time to the window; `run_out` lets them keep
		 * their full allowance past it. Without this, starting two minutes before the window
		 * shuts silently grants a full-length attempt that the sweep then closes at once.
		 */
		if ( null !== $expires_at && ! empty( $exam['open_until'] ) && 'close' === $exam['window_close_action'] ) {
			$expires_at = min( $expires_at, (string) $exam['open_until'] );
		}

		$number = $this->attempts->count_attempts( $exam_id, $user_id ?: null, $token ) + 1;

		$id = $this->attempts->insert(
			array(
				'exam_id'        => $exam_id,
				'user_id'        => $user_id ?: null,
				'guest_token'    => $token,
				'guest_name'     => sanitize_text_field( (string) ( $input['guest_name'] ?? '' ) ) ?: null,
				'guest_email'    => sanitize_email( (string) ( $input['guest_email'] ?? '' ) ) ?: null,
				'attempt_number' => $number,
				'status'         => 'in_progress',
				'started'        => $started,
				'expires_at'     => $expires_at,
				'last_seen'      => $started,
				'question_set'   => (string) wp_json_encode( $paper ),
				'review_flags'   => (string) wp_json_encode( array() ),
			)
		);

		if ( 0 === $id ) {
			return new WP_Error(
				'plugixa_exam_start_failed',
				__( 'Sorry — this exam could not be started. Please try again, or contact the site administrator.', 'plugixa-exam' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires when an attempt begins.
		 *
		 * @param int                  $id   Attempt id.
		 * @param array<string, mixed> $exam The exam row.
		 */
		do_action( 'plugixa_exam_attempt_started', $id, $exam );

		return $this->hydrate( $id );
	}

	/**
	 * The attempt plus everything the runner needs to render it.
	 *
	 * Reads the FROZEN question set, never the exam's current composition — that is what
	 * makes an edit mid-session harmless.
	 *
	 * @param int $attempt_id Attempt id.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function hydrate( int $attempt_id ): array|WP_Error {
		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Attempt not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		$paper     = (array) ( $attempt['question_set'] ?? array() );
		$seed      = (string) ( $paper['seed'] ?? '' );
		$responses = $this->attempts->responses( $attempt_id );

		$reveal   = 'practice' === ( $paper['mode'] ?? 'exam' );
		$rendered = array();

		foreach ( (array) ( $paper['questions'] ?? array() ) as $entry ) {
			$question = $this->questions->find( (int) $entry['id'] );

			if ( null === $question ) {
				continue;
			}

			$options = $this->questions->options( (int) $entry['id'] );

			// Deterministic per (attempt seed, question) — a reload shows the same order.
			if ( ! empty( $paper['shuffle_options'] ) && ! empty( $question['shuffle_options'] ) ) {
				$options = Shuffle::order( $options, $seed, (int) $entry['id'] );
			}

			/*
			 * FILTERED AGAIN ON THE WAY OUT, not only on the way in.
			 *
			 * `QuestionService` already runs every stem and option through `wp_kses` when it
			 * is saved, so what is stored is clean. This is the second layer, and it is here
			 * because the runner renders this HTML as markup — `dangerouslySetInnerHTML` in
			 * React, `wp_kses` in the no-JS template — and a row can reach the database by
			 * routes that never touched `QuestionService`: a direct SQL edit, a restore from
			 * an older backup, an importer written before the allow-list existed.
			 *
			 * Doing it here rather than shipping a sanitiser to the browser is deliberate.
			 * DOMPurify is ~45 KB on a page an examinee may be loading on a phone over a
			 * room's shared wifi, and the server already has the allow-list.
			 */
			$payload = array(
				'id'       => (int) $entry['id'],
				'type'     => (string) $entry['type'],
				'points'   => (float) $entry['points'],
				'stem'     => wp_kses( (string) $question['stem'], plugixa_exam_allowed_html( 'stem' ) ),
				'options'  => array_map(
					static fn( array $option ): array => array(
						'id'   => (int) $option['id'],
						'text' => wp_kses( (string) $option['option_text'], plugixa_exam_allowed_html() ),
					),
					$options
				),
				'response' => $responses[ (int) $entry['id'] ]['response'] ?? null,
			);

			/**
			 * Add what a question type needs the examinee's browser to know.
			 *
			 * Some types cannot be rendered from options alone: cloze needs to know how many
			 * blanks its stem has, EMQ needs its list of stems. Both of those live in the
			 * answer key, and the answer key is the one thing that must never reach the
			 * browser.
			 *
			 * ⚠️ SO THE CONTRACT IS: ADD ONLY WHAT DOES NOT REVEAL THE ANSWER. A listener
			 * receives the whole question row, including `answer_key`, because it has to
			 * read it to derive the shape — and it must return only the shape. Sending the
			 * accepted values for a cloze blank would put every answer in the page source of
			 * a live exam, where anybody can read it.
			 *
			 * ⚠️ AND `$paper` CARRIES THE FROZEN LOCALE, BECAUSE A LISTENER THAT TRANSLATES A
			 * QUESTION MUST NOT READ THE CURRENT LANGUAGE. The paper froze one when the
			 * candidate started; reading the live one would reword the exam underneath
			 * somebody mid-sitting, and an answer they had already given would then belong
			 * to a question they never saw.
			 *
			 * @param array<string, mixed>            $payload  What the runner will receive.
			 * @param array<string, mixed>            $question The full question row, answer key included.
			 * @param array<int, array<string, mixed>> $options The full option rows, `match_key` included.
			 * @param array<string, mixed>            $entry    This question's entry in the frozen paper.
			 * @param array<string, mixed>            $paper    The whole frozen paper, including its `locale`.
			 */
			$rendered[] = (array) apply_filters( 'plugixa_exam_runner_question', $payload, $question, $options, $entry, $paper );
		}

		unset( $reveal );

		$runner_paper = array(
			'mode'                 => (string) ( $paper['mode'] ?? 'exam' ),
			'layout'               => (string) ( $paper['layout'] ?? 'single_page' ),
			'require_answer'       => (string) ( $paper['require_answer'] ?? 'off' ),
			'review_before_submit' => (bool) ( $paper['review_before_submit'] ?? true ),
			'allow_early_finish'   => (bool) ( $paper['allow_early_finish'] ?? true ),
			'fullscreen_mode'      => (string) ( $paper['fullscreen_mode'] ?? 'off' ),
			'practice_reveal'      => (string) ( $paper['practice_reveal'] ?? 'on_submit' ),
			/*
			 * Read from the FROZEN paper, like everything else here, so a runner never
			 * starts watching somebody who began under terms that said it would not.
			 *
			 * The client uses this only to decide whether to attach its listeners. The
			 * server decides what is STORED, and applies a stricter test — see the
			 * premium `Recorder::capturing()`. A site that turns tracking off mid-paper
			 * therefore stops recording immediately, even though runners already open
			 * go on offering signals nothing writes down.
			 */
			'track_integrity'      => (bool) ( $paper['track_integrity'] ?? false ),
		);

		/**
		 * What the runner is told about the paper as a whole.
		 *
		 * The sibling of `plugixa_exam_runner_question`, under the same contract: nothing a
		 * candidate may not read. Whatever is added here reaches the page source of a live
		 * exam, so an answer, a transcript or a private file path must never be.
		 *
		 * @param array<string, mixed> $runner_paper What the runner will receive.
		 * @param array<string, mixed> $paper        The whole frozen paper.
		 * @param array<string, mixed> $attempt      The attempt row.
		 */
		$runner_paper = (array) apply_filters( 'plugixa_exam_runner_paper', $runner_paper, $paper, $attempt );

		return array(
			'attempt'   => $this->public_attempt( $attempt ),
			'paper'     => $runner_paper,
			'questions' => $rendered,
		);
	}

	/**
	 * Persist answers mid-attempt.
	 *
	 * @param int                  $attempt_id Attempt id.
	 * @param array<string, mixed> $input      `answers`, `flags`, `current_question_id`.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function autosave( int $attempt_id, array $input ): array|WP_Error {
		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Attempt not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		if ( 'in_progress' !== $attempt['status'] ) {
			return new WP_Error(
				'plugixa_exam_not_open',
				__( 'This attempt has already been submitted.', 'plugixa-exam' ),
				array( 'status' => 409 )
			);
		}

		$held = $this->held( $attempt );

		if ( null !== $held ) {
			return $held;
		}

		/*
		 * A WRITE AFTER THE DEADLINE IS REFUSED AND THE ATTEMPT IS CLOSED, in that order.
		 *
		 * Accepting it would let anybody keep answering for as long as they liked simply by
		 * ignoring the client timer. Refusing it and leaving the attempt open would leave
		 * the paper unmarked. So the late write is dropped and the attempt is finalised with
		 * whatever was already saved — which is exactly what the sweep would have done a
		 * moment later.
		 */
		if ( $this->is_expired( $attempt ) ) {
			$this->finalize( $attempt_id, 'time_limit' );

			return new WP_Error(
				'plugixa_exam_expired',
				__( 'Your time is up. The answers you had saved have been submitted.', 'plugixa-exam' ),
				array( 'status' => 409 )
			);
		}

		if ( ! empty( $attempt['paused_at'] ) ) {
			return new WP_Error(
				'plugixa_exam_paused',
				__( 'This attempt is paused. Resume it to carry on.', 'plugixa-exam' ),
				array( 'status' => 409 )
			);
		}

		$this->write_answers( $attempt, (array) ( $input['answers'] ?? array() ) );

		$this->attempts->update(
			$attempt_id,
			array(
				'last_seen'           => current_time( 'mysql', true ),
				'current_question_id' => absint( $input['current_question_id'] ?? 0 ) ?: null,
				'review_flags'        => (string) wp_json_encode( array_map( 'absint', (array) ( $input['flags'] ?? array() ) ) ),
			)
		);

		/**
		 * An attempt was autosaved.
		 *
		 * The seam every module that wants to record something ALONGSIDE an answer hangs
		 * on. It fires after the answers and the heartbeat are written, so a listener that
		 * reads the attempt back sees the same picture the examinee just posted.
		 *
		 * `$attempt` is the row as it was BEFORE this save — a listener needing the fresh
		 * one re-reads it. Handing over a stale row costs a query in the rare case; handing
		 * over a fresh one would cost one on every autosave in the room.
		 *
		 * @param array<string, mixed> $attempt The attempt as it stood before this save.
		 * @param array<string, mixed> $input   The raw posted body.
		 */
		do_action( 'plugixa_exam_attempt_autosaved', $attempt, $input );

		return array(
			'saved'             => true,
			// The client resyncs its countdown from this on every save, so a drifting or
			// tampered clock corrects itself within one debounce interval.
			'seconds_remaining' => $this->seconds_remaining( $this->attempts->find( $attempt_id ) ?? $attempt ),
		);
	}

	/**
	 * Pause an attempt, freezing the clock.
	 *
	 * THE REMAINING TIME IS CAPTURED, NOT THE PAUSE MOMENT. Storing "paused at 14:03" and
	 * subtracting on resume works only if nothing else ever touches `expires_at`; storing
	 * the seconds left makes resume a pure recomputation and survives an admin extending the
	 * deadline while the attempt is paused.
	 *
	 * @param int $attempt_id Attempt id.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function pause( int $attempt_id ): array|WP_Error {
		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Attempt not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		if ( 'in_progress' !== $attempt['status'] || ! empty( $attempt['paused_at'] ) ) {
			return new WP_Error( 'plugixa_exam_not_open', __( 'This attempt cannot be paused.', 'plugixa-exam' ), array( 'status' => 409 ) );
		}

		$exam = $this->exams->find( (int) $attempt['exam_id'] );

		if ( null === $exam || empty( $exam['allow_pause'] ) ) {
			return new WP_Error( 'plugixa_exam_no_pause', __( 'This exam cannot be paused.', 'plugixa-exam' ), array( 'status' => 403 ) );
		}

		$max = (int) $exam['max_pauses'];

		if ( $max > 0 && (int) $attempt['pause_count'] >= $max ) {
			return new WP_Error(
				'plugixa_exam_pause_limit',
				__( 'You have used all the pauses allowed for this exam.', 'plugixa-exam' ),
				array( 'status' => 409 )
			);
		}

		$now = current_time( 'mysql', true );

		$this->attempts->update(
			$attempt_id,
			array(
				'paused_at'      => $now,
				'time_remaining' => $this->seconds_remaining( $attempt ),
				'pause_count'    => (int) $attempt['pause_count'] + 1,
				'last_seen'      => $now,
			)
		);

		return array(
			'paused'            => true,
			'seconds_remaining' => $this->seconds_remaining( $attempt ),
		);
	}

	/**
	 * Resume a paused attempt.
	 *
	 * @param int $attempt_id Attempt id.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function resume( int $attempt_id ): array|WP_Error {
		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Attempt not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		if ( empty( $attempt['paused_at'] ) ) {
			return new WP_Error( 'plugixa_exam_not_paused', __( 'This attempt is not paused.', 'plugixa-exam' ), array( 'status' => 409 ) );
		}

		if ( ! empty( $attempt['monitor_hold'] ) ) {
			// An invigilator froze this deliberately. The examinee may not release it
			// themselves — that is the entire point of a hold.
			return new WP_Error(
				'plugixa_exam_held',
				__( 'An invigilator has paused this attempt. Please wait for them to release it.', 'plugixa-exam' ),
				array( 'status' => 403 )
			);
		}

		$now     = current_time( 'mysql', true );
		$paused  = (int) strtotime( (string) $attempt['paused_at'] . ' UTC' );
		$elapsed = max( 0, time() - $paused );

		$remaining = $attempt['time_remaining'];

		$this->attempts->update(
			$attempt_id,
			array(
				'paused_at'      => null,
				'time_remaining' => null,
				'paused_total'   => (int) $attempt['paused_total'] + $elapsed,
				// The deadline moves forward by exactly the time that was frozen, so a pause
				// neither grants nor costs the examinee anything.
				'expires_at'     => null === $remaining
					? $attempt['expires_at']
					: gmdate( 'Y-m-d H:i:s', time() + (int) $remaining ),
				'last_seen'      => $now,
			)
		);

		return array(
			'resumed'           => true,
			'seconds_remaining' => $remaining,
		);
	}

	/**
	 * Submit an attempt.
	 *
	 * @param int                  $attempt_id Attempt id.
	 * @param array<string, mixed> $input      Final answers.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function submit( int $attempt_id, array $input ): array|WP_Error {
		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Attempt not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		if ( 'in_progress' !== $attempt['status'] ) {
			// Not an error. A double-submit — two clicks, or a retry after a dropped
			// response — should return the result, not an alarming failure.
			return $this->result( $attempt_id );
		}

		$held = $this->held( $attempt );

		if ( null !== $held ) {
			return $held;
		}

		// Late answers are still written: the examinee pressed Submit, and the paper in
		// front of them is what they intend to hand in.
		$this->write_answers( $attempt, (array) ( $input['answers'] ?? array() ) );

		$blank = $this->blank_refusal( $attempt );

		if ( null !== $blank ) {
			return $blank;
		}

		/*
		 * BEFORE `finalize()`, and that ordering is load-bearing. Finalising flips the
		 * status to `submitted`, and a listener recording something about an attempt in
		 * progress will refuse to write to one that is closed. The last stretch of a paper
		 * — between the final autosave and the Submit button — would otherwise be the one
		 * part of every sitting nothing was recorded for.
		 *
		 * Documented on the autosave firing above.
		 */
		do_action( 'plugixa_exam_attempt_autosaved', $attempt, $input );

		$closed = $this->finalize( $attempt_id, 'submitted' );

		if ( $closed instanceof WP_Error ) {
			return $closed;
		}

		return $this->result( $attempt_id );
	}

	/**
	 * Grade and close an attempt.
	 *
	 * @param int    $attempt_id Attempt id.
	 * @param string $reason     Why it closed: `submitted`, `time_limit`, `idle`, `window`.
	 * @param string $action     `submit` grades what was saved; `abandon` voids it.
	 *
	 * @return true|WP_Error
	 */
	public function finalize( int $attempt_id, string $reason = 'submitted', string $action = 'submit' ): bool|WP_Error {
		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Attempt not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		$exam = $this->exams->find( (int) $attempt['exam_id'] );

		if ( null === $exam ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Exam not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		$now = current_time( 'mysql', true );

		if ( 'abandon' === $action ) {
			$closed = $this->attempts->finalize_once(
				$attempt_id,
				array(
					'status'         => 'abandoned',
					'submitted'      => $now,
					'closure_reason' => $reason,
				)
			);

			return true;
		}

		$grading = $this->grade_all( $attempt, $exam );

		$percent = $grading['max_score'] > 0
			? round( $grading['score'] / $grading['max_score'] * 100, 2 )
			: 0.0;

		$passed = $percent >= (float) $exam['pass_mark'];

		$status = $this->closing_status( $exam, $grading['fully_graded'], $attempt );

		$time_spent = $this->time_spent( $attempt, $exam, $reason, $now );

		/*
		 * EXACTLY-ONCE. `finalize_once()` is a conditional UPDATE used as a lock — see the
		 * repository. Submit and the sweep can reach this at the same instant; only one of
		 * them gets `true`, and only that one fires the notifications and webhooks below.
		 * Without this an examinee gets two "results are ready" emails and, later, two
		 * certificates.
		 */
		$closed = $this->attempts->finalize_once(
			$attempt_id,
			array(
				'status'         => $status,
				'submitted'      => $now,
				'time_spent'     => $time_spent,
				'score'          => $grading['score'],
				'max_score'      => $grading['max_score'],
				'percent'        => $percent,
				'passed'         => $passed ? 1 : 0,
				'is_graded'      => $grading['fully_graded'] ? 1 : 0,
				'closure_reason' => $reason,
			)
		);

		if ( ! $closed ) {
			return true;
		}

		/**
		 * Fires once, when an attempt is closed and scored.
		 *
		 * @param int                  $attempt_id Attempt id.
		 * @param array<string, mixed> $exam       The exam row.
		 * @param string               $reason     Why it closed.
		 */
		do_action( 'plugixa_exam_attempt_submitted', $attempt_id, $exam, $reason );

		if ( $grading['fully_graded'] ) {
			/**
			 * Fires once an attempt needs no further human grading.
			 *
			 * @param int                  $attempt_id Attempt id.
			 * @param array<string, mixed> $exam       The exam row.
			 */
			do_action( 'plugixa_exam_attempt_graded', $attempt_id, $exam );
		}

		return true;
	}

	/**
	 * Record a human marker's scores for an attempt.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE ONLY WAY A SCORE CHANGES AFTER AN ATTEMPT IS CLOSED.
	 *
	 * Auto-grading happens once, inside `finalize()`. Everything a person marks afterwards
	 * comes through here — which is why this recomputes the whole attempt rather than
	 * adjusting the total by the difference. A marker who fixes the same essay twice, or
	 * whose first save raced the auto-close sweep, must not be able to drift the total away
	 * from the sum of its parts.
	 *
	 * THE TOTAL IS RECOMPUTED FROM THE FROZEN PAPER, NOT FROM THE RESPONSE ROWS. A question
	 * the examinee never opened may have no response row, and summing rows would quietly
	 * shrink `max_score` — turning a blank paper into 0 out of 0, which is 0%, which for a
	 * pass mark of 0 is a pass.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param int                  $attempt_id Attempt id.
	 * @param array<string, mixed> $input      Scores and comments.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function grade( int $attempt_id, array $input ): array|WP_Error {
		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Attempt not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		/*
		 * An open paper is not gradable. The examinee is still typing, `finalize()` has not
		 * run, and every response row is either missing or provisional — a mark written now
		 * would be overwritten by auto-grading the moment they submit.
		 */
		if ( in_array( (string) $attempt['status'], array( 'in_progress', 'paused' ), true ) ) {
			return new WP_Error(
				'plugixa_exam_attempt_open',
				__( 'This attempt is still in progress. It can be marked once it is submitted.', 'plugixa-exam' ),
				array( 'status' => 409 )
			);
		}

		$exam = $this->exams->find( (int) $attempt['exam_id'] );

		if ( null === $exam ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Exam not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		$responses = $this->attempts->responses( $attempt_id );

		foreach ( (array) ( $input['scores'] ?? array() ) as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$question_id = absint( $entry['question_id'] ?? 0 );

			// Only questions actually on this paper. A supplied id that is not here is
			// either a stale form or somebody probing, and neither deserves a row.
			if ( ! isset( $responses[ $question_id ] ) ) {
				continue;
			}

			$possible = (float) $responses[ $question_id ]['points_possible'];
			$raw      = $entry['points_awarded'] ?? null;

			/*
			 * Null means "still unmarked" and is a legitimate value — it is how a marker
			 * leaves a question for a colleague. It is NOT the same as 0, which is a mark.
			 * An empty string arrives from a cleared number field and means the same thing.
			 */
			$current = $responses[ $question_id ];
			$stored  = $current['points_awarded'];

			/*
			 * THE MARK ALREADY STORED IS RECOGNISED BEFORE ANY LIMIT IS APPLIED. The screen posts
			 * the whole paper back, so a limit that ran first would read an untouched -0.33 as
			 * an edit to 0: marking the essay beside it would quietly remove the penalty and
			 * raise the total. A stored mark stays valid even if the question's minimum has
			 * since been changed; only a mark somebody actually changes meets the limits.
			 */
			if ( null === $raw || '' === $raw ) {
				$awarded = null;
			} elseif ( null !== $stored && abs( round( (float) $raw, 2 ) - (float) $stored ) < 0.005 ) {
				$awarded = (float) $stored;
			} else {
				$awarded = max( $this->lowest_mark( $question_id ), min( $possible, round( (float) $raw, 2 ) ) );
			}

			$comment = array_key_exists( 'grader_comment', $entry )
				? sanitize_textarea_field( (string) $entry['grader_comment'] )
				: null;

			/*
			 * ────────────────────────────────────────────────────────────────────────────
			 * AN UNCHANGED MARK IS NOT A MARK, AND MUST NOT BE RECORDED AS ONE.
			 *
			 * The marking screen posts every question on the paper, not only the ones the
			 * marker touched — it has no way to know which fields a person actually edited,
			 * and asking it to track that would put the audit trail at the mercy of the UI.
			 * So the comparison happens here, where the stored values are.
			 *
			 * Without it, opening a paper and pressing Save relabelled every
			 * automatically-marked question as marked by hand. That is a false audit trail,
			 * and it destroys the one signal telling a marker which answers still need them:
			 * after one save, everything claimed a person had marked it.
			 *
			 * Two things follow, and they are not the same thing. Skipping the write below
			 * avoids a pointless UPDATE per question per save. What actually protects
			 * `auto_graded` is the narrower test further down — that one is load bearing,
			 * and `ManualGradingTest` fails only when BOTH are removed.
			 * ────────────────────────────────────────────────────────────────────────────
			 */
			$same_points = ( null === $awarded && null === $current['points_awarded'] )
				|| ( null !== $awarded
					&& null !== $current['points_awarded']
					// Floats, so compared by tolerance rather than identity — the value has
					// been through JSON and a decimal(8,2) column since it was written.
					&& abs( $awarded - (float) $current['points_awarded'] ) < 0.005 );

			$same_comment = null === $comment || $comment === (string) ( $current['grader_comment'] ?? '' );

			if ( $same_points && $same_comment ) {
				continue;
			}

			$data = array(
				'points_awarded' => $awarded,
				// Clamped above, so `>=` can only be true at full marks.
				'is_correct'     => null === $awarded ? null : ( $awarded >= $possible ? 1 : 0 ),
			);

			// Only a CHANGED mark makes this a hand-marked answer. A note left on an
			// auto-marked answer is a note, not a re-mark.
			if ( ! $same_points ) {
				$data['auto_graded'] = 0;
			}

			if ( null !== $comment ) {
				$data['grader_comment'] = $comment;
			}

			$this->attempts->score_response( $attempt_id, $question_id, $data );
		}

		$paper = (array) ( $attempt['question_set'] ?? array() );
		$fresh = $this->attempts->responses( $attempt_id );

		$score        = 0.0;
		$max          = 0.0;
		$fully_graded = true;

		foreach ( (array) ( $paper['questions'] ?? array() ) as $entry ) {
			$question_id = (int) $entry['id'];
			$max        += (float) $entry['points'];

			$awarded = $fresh[ $question_id ]['points_awarded'] ?? null;

			if ( null === $awarded ) {
				$fully_graded = false;

				continue;
			}

			$score += (float) $awarded;
		}

		$percent = $max > 0 ? round( $score / $max * 100, 2 ) : 0.0;
		$passed  = $percent >= (float) $exam['pass_mark'];

		$was_graded = ! empty( $attempt['is_graded'] );

		$update = array(
			'score'          => round( $score, 2 ),
			'max_score'      => round( $max, 2 ),
			'percent'        => $percent,
			'passed'         => $passed ? 1 : 0,
			'is_graded'      => $fully_graded ? 1 : 0,
			'status'         => $this->closing_status( $exam, $fully_graded, $attempt ),
			'last_graded_by' => get_current_user_id(),
			'last_graded_at' => current_time( 'mysql', true ),
		);

		if ( array_key_exists( 'grader_comment', $input ) ) {
			$update['grader_comment'] = sanitize_textarea_field( (string) $input['grader_comment'] );
		}

		$this->attempts->update( $attempt_id, $update );

		/*
		 * Fired on the transition only. Re-marking an already-graded attempt must not send
		 * the examinee a second "your result is ready" email, and this is the same event
		 * `finalize()` fires when auto-grading alone completes an attempt.
		 */
		if ( $fully_graded && ! $was_graded ) {
			/** This action is documented in includes/Modules/Attempts/Services/AttemptService.php */
			do_action( 'plugixa_exam_attempt_graded', $attempt_id, $exam );
		}

		if ( $fully_graded && $was_graded ) {
			/**
			 * An attempt that was already graded has been marked again.
			 *
			 * `plugixa_exam_attempt_graded` fires once, on the transition, so that the
			 * examinee is not emailed twice — which left anything that acts on the VERDICT
			 * deaf to a re-mark: a fail turned into a pass never completed the linked LMS
			 * course (measured on the LMS audit sites). This carries the verdict from before
			 * the re-mark, so a listener can tell a change from a correction that changed
			 * nothing, and decide for itself what a change means.
			 *
			 * The fourth argument is the whole result from before — score, maximum, percent and
			 * verdict — for a listener that reports the change rather than acts on it (the
			 * `attempt.regraded` webhook). Added after the first three; a listener registered
			 * for three is unaffected.
			 *
			 * @param int                  $attempt_id The attempt.
			 * @param array<string, mixed> $exam       The exam row.
			 * @param bool                 $was_passed The verdict before this re-mark.
			 * @param array<string, mixed> $previous   `score`, `max_score`, `percent` and `passed` before it.
			 */
			do_action(
				'plugixa_exam_attempt_regraded',
				$attempt_id,
				$exam,
				! empty( $attempt['passed'] ),
				array(
					'score'     => null === $attempt['score'] ? null : (float) $attempt['score'],
					'max_score' => null === $attempt['max_score'] ? null : (float) $attempt['max_score'],
					'percent'   => null === $attempt['percent'] ? null : (float) $attempt['percent'],
					'passed'    => ! empty( $attempt['passed'] ),
				)
			);
		}

		return $this->paper( $attempt_id );
	}

	/**
	 * An attempt as a MARKER sees it.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * DELIBERATELY NOT `result()`, AND THE DIFFERENCE IS THE WHOLE POINT.
	 *
	 * `result()` answers "what may this examinee be shown", so it withholds an unreleased
	 * score entirely and omits the answer review when the exam has `show_answers` off. Both
	 * are correct for the person who sat the paper and useless for the person marking it: a
	 * grading queue built on `result()` shows "Results are not available yet" on exactly the
	 * exams whose results are waiting on the marker to produce them.
	 *
	 * Nothing here is gated on release, because the gate is the ROUTE — reaching this needs
	 * `attempts.view`, which an examinee does not have over anybody's paper including their
	 * own.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param int $attempt_id Attempt id.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function paper( int $attempt_id ): array|WP_Error {
		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Attempt not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		$exam = $this->exams->find( (int) $attempt['exam_id'] );

		if ( null === $exam ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Exam not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		return array(
			// `examinee` is added HERE as well as in the list controller's decorator. The
			// marking screen names the person whose paper it is, and a row straight from
			// `public_attempt()` carries a user id and a guest name but no single field a
			// heading can print.
			'attempt'  => $this->public_attempt( $attempt ) + array(
				'examinee'          => $this->examinee_name( $attempt ),
				// Added back for staff. `public_attempt()` strips it because the examinee
				// must not read it; this route is reached with `attempts.view`, and an
				// invigilator reviewing a paper is exactly who it is for. Null when nothing
				// recorded, which the panel renders differently from all-zeroes.
				'integrity_signals' => $attempt['integrity_signals'] ?? null,
			),
			'exam'     => array(
				'id'        => (int) $exam['id'],
				'title'     => (string) $exam['title'],
				'pass_mark' => (float) $exam['pass_mark'],
			),
			// What the examinee would see, so a marker can tell whether their own marking is
			// already visible to the candidate.
			'released' => $this->results_released( $exam, $attempt ),
			// Added here and not in `review()`, which the examinee's result page shares.
			'review'   => array_map(
				fn( array $item ): array => $item + array( 'points_minimum' => $this->lowest_mark( (int) $item['id'] ) ),
				$this->review( $attempt )
			),
		);
	}

	/**
	 * The lowest mark a marker may give a question.
	 *
	 * Zero, unless the question uses negative marking and sets a minimum below zero.
	 *
	 * @param int $question_id The question.
	 */
	private function lowest_mark( int $question_id ): float {
		$question = $this->questions->find( $question_id );
		$key      = null === $question ? array() : (array) ( $question['answer_key'] ?? array() );

		return empty( $key['negative_marking'] ) ? 0.0 : min( 0.0, (float) ( $key['min_score'] ?? 0 ) );
	}

	/**
	 * An attempt's result, as the result page renders it.
	 *
	 * @param int $attempt_id Attempt id.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function result( int $attempt_id ): array|WP_Error {
		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Attempt not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		$exam = $this->exams->find( (int) $attempt['exam_id'] );

		if ( null === $exam ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Exam not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		$released = $this->results_released( $exam, $attempt );

		$payload = array(
			'attempt'  => $this->public_attempt( $attempt ),
			'exam'     => array(
				'id'        => (int) $exam['id'],
				'title'     => (string) $exam['title'],
				'pass_mark' => (float) $exam['pass_mark'],
			),
			'released' => $released,
		);

		if ( ! $released ) {
			// The score is WITHHELD, not merely hidden. An unreleased result must not travel
			// to the browser at all — a value in a JSON response is public however the UI
			// chooses to render it.
			unset(
				$payload['attempt']['score'],
				$payload['attempt']['max_score'],
				$payload['attempt']['percent'],
				$payload['attempt']['passed']
			);

			$payload['message'] = __( 'Your submission has been received. Results are not available yet.', 'plugixa-exam' );

			return $payload;
		}

		$payload['message'] = $attempt['passed']
			? (string) $exam['pass_message']
			: (string) $exam['fail_message'];

		if ( ! empty( $exam['show_answers'] ) ) {
			$payload['review'] = $this->review( $attempt );
		}

		return $payload;
	}

	/* ------------------------------------------------------------------ internals */

	/**
	 * Write answers, restricted to questions actually on this paper.
	 *
	 * A CLIENT MAY ONLY ANSWER WHAT IT WAS ASKED. Accepting an arbitrary question id would
	 * let somebody submit answers to questions that are not on their paper — which, in a
	 * pool section, means answering the whole bank and being marked on the subset they were
	 * drawn.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 * @param array<int, mixed>    $answers Question id => answer.
	 */
	private function write_answers( array $attempt, array $answers ): void {
		if ( array() === $answers ) {
			return;
		}

		$this->attempts->save_responses( (int) $attempt['id'], $this->answer_rows( $attempt, $answers ) );
	}

	/**
	 * The answers that belong on an attempt's paper, each cleaned for its question's type.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * FOR A CALLER THAT HOLDS A RAW REQUEST AND HAS SOMEWHERE TO SEND IT BEFORE HERE.
	 *
	 * `autosave()` and `submit()` clean whatever they are given, and for the REST runner
	 * that is the first thing that happens to an answer. The no-JavaScript form is
	 * different: between reading `$_POST` and calling this service it fires hooks, and a
	 * hook must not be handed a request nobody has cleaned. So the form asks for the
	 * cleaning FIRST, through {@see \PlugixaExam\Core\Frontend\AnswerSanitizer}, and
	 * everything after it sees this method's output.
	 *
	 * The rules are `write_answers()`'s own, because they are one function: an answer to a
	 * question that is not on this paper is dropped, and each of the rest goes through the
	 * sanitiser for its type. An answer therefore passes through that sanitiser TWICE on the
	 * form path — here, and again when it is saved — which is harmless only because every
	 * type's sanitiser gives the same result the second time. `AnswerSanitisingTest` holds
	 * each of them to that.
	 *
	 * Nothing is read back and nothing is written: an unknown attempt has no paper, so
	 * every answer to it is dropped. Whose attempt it is remains the save's question.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param int                      $attempt_id The attempt.
	 * @param array<int|string, mixed> $answers    Question id => answer, as received.
	 *
	 * @return array<int, mixed> Question id => cleaned answer.
	 */
	public function sanitize_answers( int $attempt_id, array $answers ): array {
		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return array();
		}

		$clean = array();

		foreach ( $this->answer_rows( $attempt, $answers ) as $row ) {
			$clean[ $row['question_id'] ] = $row['response'];
		}

		return $clean;
	}

	/**
	 * One row per answer that is on the paper, cleaned.
	 *
	 * @param array<string, mixed>     $attempt The attempt row.
	 * @param array<int|string, mixed> $answers Question id => answer.
	 *
	 * @return array<int, array{question_id: int, question_type: string, response: mixed, ordering: int}>
	 */
	private function answer_rows( array $attempt, array $answers ): array {
		$paper   = (array) ( $attempt['question_set'] ?? array() );
		$onpaper = array();

		foreach ( (array) ( $paper['questions'] ?? array() ) as $index => $entry ) {
			$onpaper[ (int) $entry['id'] ] = array(
				'type'     => (string) $entry['type'],
				'ordering' => $index + 1,
			);
		}

		$rows = array();

		foreach ( $answers as $question_id => $answer ) {
			$question_id = (int) $question_id;

			if ( ! isset( $onpaper[ $question_id ] ) ) {
				continue;
			}

			$rows[] = array(
				'question_id'   => $question_id,
				'question_type' => $onpaper[ $question_id ]['type'],
				'response'      => $this->sanitize_response( $onpaper[ $question_id ]['type'], $answer, (int) $attempt['id'], $question_id ),
				'ordering'      => $onpaper[ $question_id ]['ordering'],
			);
		}

		return $rows;
	}

	/**
	 * An essay answer, kept as the text it is.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * AN ESSAY IS TYPED INTO A PLAIN TEXT BOX AND PRINTED AS TEXT. IT WAS BEING CLEANED AS
	 * IF IT WERE MARKUP, AND THAT CHANGED WHAT CANDIDATES HAD WRITTEN.
	 *
	 * Both doors hand a candidate a `<textarea>`, and every screen that shows the answer
	 * back escapes it: the result page (`esc_html`), the runner's box (`esc_textarea`, or a
	 * React value), the result and marking screens (React text). The one place that
	 * disagreed was here, which ran the text through `wp_kses()`. Measured:
	 *
	 *   typed                          stored
	 *   x <= 3 && y >= 4               x = 4
	 *   if x<y then y>x                if xx
	 *   a < b and c > d                a <b> d
	 *   Tom & Jerry                    Tom &amp; Jerry   (shown like that in the React views)
	 *
	 * The first three are a marker reading something the candidate did not write, in an
	 * exam. `sanitize_textarea_field()` is no better here — it gives the same `x = 4`, turns
	 * `20%faster` into `20ster`, and discards the whole answer for one invalid byte.
	 *
	 * So what is removed is only what cannot be text at all: bytes that are not UTF-8
	 * (dropped, without losing the rest), and control characters other than tab and newline.
	 * Line endings are made `\n`. Everything a person can type stays, a `<script>` included,
	 * AS TEXT — which is why every place that prints an answer must go on escaping it, and
	 * `AnswerSanitisingTest` holds each of them to that.
	 *
	 * Running it twice changes nothing, which matters: the no-JavaScript form cleans what it
	 * read and the service cleans it again.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string $answer What was typed.
	 */
	private function essay_text( string $answer ): string {
		$text = wp_check_invalid_utf8( $answer, true );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text );
	}

	/**
	 * Clean one answer.
	 *
	 * @param string $type   Question type.
	 * @param mixed  $answer Raw answer.
	 */
	private function sanitize_response( string $type, mixed $answer, int $attempt_id = 0, int $question_id = 0 ): mixed {
		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * A VALUE OF THE WRONG SHAPE IS NO ANSWER. IT IS NOT COERCED INTO ONE.
		 *
		 * Every native type below except the multiple choice expects ONE value, and a
		 * request can put a list where that value should be: `answers[12][]=x` from a form,
		 * `{"12": ["x"]}` from the runner. Casting it used to give each type a different
		 * wrong answer — a short answer and an essay stored the word "Array" (and PHP said
		 * so in the log), a single choice became option 1, which is a real option on some
		 * paper, and a true/false became False, which is an answer the candidate never
		 * gave and one the grader marks. Found by posting one, not by reading this.
		 *
		 * So the shape is asked about first, and the wrong one is what an unanswered
		 * question already is for that type: null, or the empty string.
		 * ════════════════════════════════════════════════════════════════════════════════
		 */
		$single = is_scalar( $answer );

		switch ( $type ) {
			case 'mcq_single':
				return $single ? absint( $answer ) : null;

			case 'mcq_multiple':
				// A list of option ids. A list INSIDE the list is not one of them.
				return array_values( array_map( 'absint', array_filter( (array) $answer, 'is_scalar' ) ) );

			case 'true_false':
				return $single ? (bool) filter_var( $answer, FILTER_VALIDATE_BOOL ) : null;

			case 'short_answer':
				return $single ? sanitize_text_field( (string) $answer ) : '';

			case 'essay':
				return $single ? $this->essay_text( (string) $answer ) : '';

			default:
				/**
				 * Clean an answer for a type this build does not handle natively.
				 *
				 * The default is to DISCARD it. An unknown shape written straight to the
				 * database is stored user input with no validation — the premium module
				 * that owns the type is the only thing that knows what it should look like.
				 *
				 * ⚠️ THE ATTEMPT AND QUESTION ARE PASSED BECAUSE SOME ANSWERS ARE
				 * REFERENCES, NOT VALUES. A cloze answer is text and can be judged on its
				 * own; an answer that names a row — an uploaded file, say — cannot be
				 * cleaned without asking whether that row belongs to THIS candidate on THIS
				 * question. Without the pair, a listener would have to accept any id it was
				 * given, and the way to read somebody else's coursework would be to guess a
				 * number.
				 *
				 * @param mixed  $sanitized   The cleaned answer, null by default.
				 * @param mixed  $answer      The raw answer.
				 * @param string $type        Question type.
				 * @param int    $attempt_id  The attempt this answer belongs to.
				 * @param int    $question_id The question it answers.
				 */
				return apply_filters( 'plugixa_exam_sanitize_response', null, $answer, $type, $attempt_id, $question_id );
		}
	}

	/**
	 * Grade every response on an attempt.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 * @param array<string, mixed> $exam    The exam row.
	 *
	 * @return array{score: float, max_score: float, fully_graded: bool}
	 */
	private function grade_all( array $attempt, array $exam ): array {
		unset( $exam );

		$paper     = (array) ( $attempt['question_set'] ?? array() );
		$responses = $this->attempts->responses( (int) $attempt['id'] );

		$score        = 0.0;
		$max          = 0.0;
		$fully_graded = true;

		foreach ( (array) ( $paper['questions'] ?? array() ) as $entry ) {
			$question_id = (int) $entry['id'];
			$points      = (float) $entry['points'];
			$type        = (string) $entry['type'];

			$max += $points;

			$question = $this->questions->find( $question_id );
			$key      = null === $question ? array() : (array) ( $question['answer_key'] ?? array() );
			$options  = null === $question ? array() : $this->questions->options( $question_id );

			/**
			 * The answer key this response is graded against.
			 *
			 * ════════════════════════════════════════════════════════════════════════════
			 * IT IS FILTERED HERE, NOT INSIDE THE GRADER, AND THAT IS THE WHOLE POINT.
			 *
			 * `Grader` is stateless and database-free on purpose, and `plugixa_exam_grade_response`
			 * only fires for types it does NOT understand — `short_answer` and `cloze` are
			 * graded natively, so nothing downstream of this line can change what they accept.
			 * That left one gap with no seam in it: a French candidate typing `vitesse`
			 * against an English key scores zero, and no module could fix it.
			 *
			 * ⚠️ A LISTENER MAY CHANGE WHAT IS ACCEPTED. IT MUST NOT CHANGE HOW. The flags
			 * on a key — `numeric`, `use_regex`, `tolerance`, `trim`, `case_insensitive` —
			 * are how the comparison is made, and a listener that rewrote them would let one
			 * language be marked leniently and another strictly on what is meant to be one
			 * question with one statistic.
			 *
			 * ⚠️ AND `$paper` CARRIES THE FROZEN LOCALE, for the same reason
			 * `plugixa_exam_runner_question` does: the key a paper is marked against must be
			 * the key for the language it was SAT in, not for the language the site happens
			 * to be in when a sweep or a re-grade runs — which, off WP-Cron, is neither the
			 * candidate's nor the marker's.
			 * ════════════════════════════════════════════════════════════════════════════
			 *
			 * @param array<string, mixed> $key         The question's normalised answer key.
			 * @param string               $type        Question type.
			 * @param int                  $question_id The question.
			 * @param array<string, mixed> $paper       The whole frozen paper, including its `locale`.
			 */
			$key = (array) apply_filters( 'plugixa_exam_answer_key', $key, $type, $question_id, $paper );

			/**
			 * The option rows this response is graded against.
			 *
			 * ════════════════════════════════════════════════════════════════════════════
			 * THE TWIN OF THE FILTER ABOVE, AND IT EXISTS BECAUSE SOME ANSWERS ARE OPTIONS.
			 *
			 * A matching response is `{ optionId: the text they picked }`, compared against
			 * `match_key` — so for that one type the accepted answer lives on the OPTION row
			 * rather than in the key, and a listener that could only reach the key would
			 * translate the choices a candidate is shown while leaving the marker expecting
			 * the originals. Every French candidate would then score zero on every matching
			 * question, which is the one failure mode worth a second seam.
			 *
			 * ⚠️ CORRECTNESS IS NOT NEGOTIABLE HERE. `is_correct` decides an MCQ; a listener
			 * that changed it would not be translating a question, it would be rewriting the
			 * answer. Change the TEXT a candidate reads and matches against, nothing else.
			 * ════════════════════════════════════════════════════════════════════════════
			 *
			 * @param array<int, array<string, mixed>> $options     The canonical option rows.
			 * @param string                           $type        Question type.
			 * @param int                              $question_id The question.
			 * @param array<string, mixed>             $paper       The whole frozen paper, including its `locale`.
			 */
			$options = (array) apply_filters( 'plugixa_exam_question_options', $options, $type, $question_id, $paper );

			$response = $responses[ $question_id ]['response'] ?? null;

			$verdict = $this->grader->grade(
				$type,
				$key,
				$options,
				$response,
				$points,
				$this->types->is_auto_graded( $type )
			);

			if ( null === $verdict['points_awarded'] ) {
				$fully_graded = false;
			} else {
				$score += $verdict['points_awarded'];
			}

			/*
			 * An unanswered question may have no response row at all — the examinee never
			 * touched it. One is written now so the grading queue and the review screen see
			 * every question on the paper, not only the answered ones.
			 */
			if ( ! isset( $responses[ $question_id ] ) ) {
				$this->attempts->save_responses(
					(int) $attempt['id'],
					array(
						array(
							'question_id'   => $question_id,
							'question_type' => $type,
							'response'      => null,
							'ordering'      => 0,
						),
					)
				);
			}

			$this->attempts->score_response(
				(int) $attempt['id'],
				$question_id,
				array(
					'is_correct'      => $verdict['is_correct'],
					'points_awarded'  => $verdict['points_awarded'],
					'points_possible' => $points,
					'auto_graded'     => $verdict['auto_graded'],
				)
			);
		}

		return array(
			'score'        => round( $score, 2 ),
			'max_score'    => round( $max, 2 ),
			'fully_graded' => $fully_graded,
		);
	}

	/**
	 * What status a closing attempt should take.
	 *
	 * @param array<string, mixed> $exam         The exam row.
	 * @param bool                 $fully_graded Whether anything awaits a human.
	 * @param array<string, mixed> $attempt      The attempt row.
	 */
	private function closing_status( array $exam, bool $fully_graded, array $attempt ): string {
		// Practice never locks. The whole point of practice mode is unlimited retries.
		if ( 'practice' === $exam['attempt_mode'] ) {
			return $fully_graded ? 'graded' : 'submitted';
		}

		$policy = (string) $exam['lock_policy'];

		$cap_reached = (int) $exam['max_attempts'] > 0
			&& (int) $attempt['attempt_number'] >= (int) $exam['max_attempts'] + (int) $attempt['extra_attempts_granted'];

		$lock = match ( $policy ) {
			'auto'     => ! $fully_graded || $cap_reached,
			'cap_only' => $cap_reached,
			default    => false,
		};

		if ( $lock ) {
			return 'locked_review';
		}

		return $fully_graded ? 'graded' : 'submitted';
	}

	/**
	 * How long the attempt took.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 * @param array<string, mixed> $exam    The exam row.
	 * @param string               $reason  Why it closed.
	 * @param string               $now     Closing time, UTC.
	 */
	private function time_spent( array $attempt, array $exam, string $reason, string $now ): int {
		$started = (int) strtotime( (string) $attempt['started'] . ' UTC' );
		$closed  = (int) strtotime( $now . ' UTC' );

		$elapsed = max( 0, $closed - $started - (int) $attempt['paused_total'] );

		/*
		 * `capped` vs `wallclock` only differs for an auto-closed attempt, and the
		 * difference is not cosmetic: an examinee who closes their laptop on a two-hour exam
		 * and is swept a day later has a wallclock time of 26 hours. Reported in an
		 * analytics average, that one row makes the mean meaningless.
		 */
		if ( 'submitted' !== $reason
			&& 'capped' === $exam['auto_close_time_spent']
			&& ! empty( $attempt['expires_at'] )
		) {
			$deadline = (int) strtotime( (string) $attempt['expires_at'] . ' UTC' );
			$elapsed  = min( $elapsed, max( 0, $deadline - $started ) );
		}

		return $elapsed;
	}

	/**
	 * Whether an attempt is past its deadline.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 */
	/**
	 * The refusal for a paper an invigilator has put on hold, or null when it is not held.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * A HOLD THAT ONLY THE SCREEN OBEYS IS NOT A HOLD.
	 *
	 * `monitor_hold` was read in exactly one place, `resume()`, so it stopped a candidate
	 * who had paused from carrying on and nothing else. A paper that was simply open went
	 * on taking answers, and could be handed in, while the monitor board showed it frozen.
	 * Measured on the 1.1.3 builds on 10 Oct 2026: hold, then an autosave from the
	 * candidate, 200.
	 *
	 * Only the candidate's two doors come through here. An invigilator's own "submit now"
	 * and the auto-close sweep call `finalize()` directly, and must: a hold is the
	 * invigilator's decision, not something that binds the invigilator.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 */
	private function held( array $attempt ): ?WP_Error {
		if ( empty( $attempt['monitor_hold'] ) ) {
			return null;
		}

		return new WP_Error(
			'plugixa_exam_held',
			__( 'An invigilator has paused this attempt. Please wait for them to release it.', 'plugixa-exam' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * The refusal for handing in a paper with blanks on an exam that does not allow them.
	 *
	 * "Require an answer: block" was stored, sent to the runner, and enforced by nothing.
	 * It is enforced here, where no client can skip it, and only while the candidate still
	 * has time: when the clock has run out the paper is taken as it stands, as every other
	 * late paper is. What they sent has already been written, so nothing typed is lost by
	 * the refusal.
	 *
	 * @param array<string, mixed> $attempt The attempt row, as it was before this request.
	 */
	private function blank_refusal( array $attempt ): ?WP_Error {
		$paper = (array) ( $attempt['question_set'] ?? array() );

		if ( 'block' !== ( $paper['require_answer'] ?? 'off' ) || $this->is_expired( $attempt ) ) {
			return null;
		}

		$responses = $this->attempts->responses( (int) $attempt['id'] );
		$blank     = 0;

		foreach ( (array) ( $paper['questions'] ?? array() ) as $entry ) {
			$question_id = (int) ( $entry['id'] ?? 0 );

			if ( ! isset( $responses[ $question_id ] ) || Grader::is_unanswered( $responses[ $question_id ]['response'] ) ) {
				++$blank;
			}
		}

		if ( 0 === $blank ) {
			return null;
		}

		return new WP_Error(
			'plugixa_exam_answers_required',
			// One sentence with no number in it, on purpose: the no-JavaScript form shows the
			// same words from the error code alone, where the count is not known.
			__( 'Every question needs an answer before this exam can be handed in.', 'plugixa-exam' ),
			array(
				'status' => 409,
				'blank'  => $blank,
			)
		);
	}

	private function is_expired( array $attempt ): bool {
		if ( empty( $attempt['expires_at'] ) ) {
			return false;
		}

		// A paused attempt cannot expire: its clock is stopped, and the remaining time is
		// restored on resume.
		if ( ! empty( $attempt['paused_at'] ) ) {
			return false;
		}

		return current_time( 'mysql', true ) > $attempt['expires_at'];
	}

	/**
	 * Seconds left, or null when the exam is untimed.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 */
	private function seconds_remaining( array $attempt ): ?int {
		if ( ! empty( $attempt['paused_at'] ) ) {
			return $attempt['time_remaining'];
		}

		if ( empty( $attempt['expires_at'] ) ) {
			return null;
		}

		return max(
			0,
			(int) strtotime( (string) $attempt['expires_at'] . ' UTC' ) - time()
		);
	}

	/**
	 * Whether an attempt's score may be shown yet.
	 *
	 * @param array<string, mixed> $exam    The exam row.
	 * @param array<string, mixed> $attempt The attempt row.
	 */
	private function results_released( array $exam, array $attempt ): bool {
		if ( 'in_progress' === $attempt['status'] ) {
			return false;
		}

		/*
		 * ⚠️ THE POLICY ITSELF MOVED TO {@see Outcome::released()} AND MUST NOT COME BACK.
		 *
		 * It is now read in two places — here, deciding whether a result payload may carry
		 * a score, and by the completion badge on the catalogue, "My exams" and the exam
		 * landing page. Two readings of "may this be shown yet" is how two answers start
		 * disagreeing, and the disagreement that matters is silent: a badge saying "Passed"
		 * beside a result screen that is correctly withholding the mark.
		 *
		 * What stays here is the part that is genuinely about THIS attempt: an attempt still
		 * running has no result to release, whatever the exam's policy says.
		 */
		return Outcome::released( $exam );
	}

	/**
	 * Per-question review data.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function review( array $attempt ): array {
		$paper     = (array) ( $attempt['question_set'] ?? array() );
		$responses = $this->attempts->responses( (int) $attempt['id'] );
		$review    = array();

		foreach ( (array) ( $paper['questions'] ?? array() ) as $entry ) {
			$question_id = (int) $entry['id'];
			$question    = $this->questions->find( $question_id );

			if ( null === $question ) {
				continue;
			}

			$response = $responses[ $question_id ] ?? null;

			/*
			 * KSES ON THE WAY OUT, matching `hydrate()`.
			 *
			 * `QuestionService` already sanitises a stem when it is saved, so this is the
			 * second pass — and it is not redundant. This array has two consumers with
			 * different escaping stories: `result.php` runs `wp_kses` itself when it echoes,
			 * but the marking screen receives the same array as JSON and renders it through
			 * `dangerouslySetInnerHTML`, where nothing else stands between the database and
			 * the DOM. A row can reach the table by import, by a direct query, or from a
			 * version of this plugin whose allow-list was wider, and only the read path can
			 * be sure what is actually in it.
			 */
			$review[] = array(
				'id'              => $question_id,
				'type'            => (string) $entry['type'],
				'title'           => (string) $question['title'],
				'stem'            => wp_kses( (string) $question['stem'], plugixa_exam_allowed_html( 'stem' ) ),
				'explanation'     => wp_kses( (string) $question['explanation'], plugixa_exam_allowed_html( 'stem' ) ),
				'points_possible' => (float) $entry['points'],
				'points_awarded'  => $response['points_awarded'] ?? null,
				'is_correct'      => $response['is_correct'] ?? null,
				'response'        => $response['response'] ?? null,
				'grader_comment'  => $response['grader_comment'] ?? null,
				'auto_graded'     => (bool) ( $response['auto_graded'] ?? false ),
				'options'         => array_map(
					static fn( array $option ): array => array(
						'id'         => (int) $option['id'],
						'text'       => wp_kses( (string) $option['option_text'], plugixa_exam_allowed_html() ),
						'is_correct' => (bool) $option['is_correct'],
					),
					$this->questions->options( $question_id )
				),
			);

			/**
			 * Add what a question type needs a MARKER to see.
			 *
			 * The twin of `plugixa_exam_runner_question`, and the opposite audience. That one
			 * withholds the answer because it is read by the candidate; this one is read by
			 * somebody entitled to everything, so a type may add whatever helps them judge.
			 *
			 * IT EXISTS BECAUSE SOME ANSWERS ARE NOT LEGIBLE AS THEMSELVES. A response of
			 * `[41, 42]` is a pair of row ids, and a marker shown that has been shown
			 * nothing — the files those ids name have to be resolved before the screen can
			 * offer them.
			 *
			 * @param array<string, mixed> $item     The review row.
			 * @param array<string, mixed> $question The full question row.
			 * @param int                  $attempt  The attempt being reviewed.
			 */
			$review[ array_key_last( $review ) ] = (array) apply_filters(
				'plugixa_exam_review_item',
				$review[ array_key_last( $review ) ],
				$question,
				(int) $attempt['id']
			);
		}

		return $review;
	}

	/**
	 * An attempt row with nothing sensitive in it.
	 *
	 * `guest_token` is a bearer credential — anybody holding it IS that guest — so it must
	 * never travel in a response body, where it would sit in the browser's network log.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 *
	 * @return array<string, mixed>
	 */
	/**
	 * A display name for whoever sat the attempt.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 */
	private function examinee_name( array $attempt ): string {
		if ( ! empty( $attempt['user_id'] ) ) {
			$user = get_userdata( (int) $attempt['user_id'] );

			if ( false !== $user ) {
				return $user->display_name;
			}
		}

		if ( ! empty( $attempt['guest_name'] ) ) {
			return (string) $attempt['guest_name'];
		}

		return __( 'Guest', 'plugixa-exam' );
	}

	/**
	 * The attempt as the EXAMINEE may see it.
	 *
	 * Used by the runner's state, the result page and — with staff-only keys added back —
	 * the marking screen. Anything unset here is unset for the candidate.
	 *
	 * `integrity_signals` is among them. Handing a candidate a live count of their own
	 * focus losses turns a detection-only signal into a scoreboard to play against, and
	 * mid-paper is the worst possible moment to be told what is being counted. The GDPR
	 * exporter still discloses every one of them on request, which is where transparency
	 * about this belongs — after the exam, through a channel that authenticates the asker.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 *
	 * @return array<string, mixed>
	 */
	private function public_attempt( array $attempt ): array {
		unset( $attempt['guest_token'], $attempt['question_set'], $attempt['integrity_signals'] );

		$attempt['seconds_remaining'] = $this->seconds_remaining( $attempt );

		return $attempt;
	}
}
