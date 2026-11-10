<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a learner stands on one exam, said in a way that is safe to show them.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS: A FINISHED EXAM LOOKED EXACTLY LIKE ONE NOBODY HAD EVER OPENED.
 *
 * "When I terminate courses, why should I repeat it again in list of courses — normally I
 * want a mark to appear indicating that I have completed this." The catalogue asked only
 * which exams are published and open; `ExamBridge::catalogue()` did not call
 * `get_current_user_id()` at all. "My exams" grouped by date window alone, so an exam
 * passed yesterday still sat under "Ready to sit" with a live Start link.
 *
 * The state was never missing. `attempts` has stored `status`, `passed`, `percent` and
 * `is_graded` since phase 1, indexed by `exam_user (exam_id,user_id)` — the exact index
 * this lookup wants. Nothing had ever joined it to a list the learner reads.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ A BADGE IS A DISCLOSURE CHANNEL, NOT A DECORATION. An exam whose `result_release` is
 * `after_close`, `scheduled` or `manual` has a mark its candidate may not see yet, and
 * `AttemptService::result()` goes to the trouble of WITHHOLDING it from the payload rather
 * than hiding it in a template. A "✓ Passed" on a card would walk straight around that. So
 * an unreleased attempt reports {@see self::AWAITING} and carries NO percent — the state
 * says something happened, and nothing more.
 *
 * ⚠️ AND `self::released()` IS THE ONLY COPY OF THAT RULE. It was private inside
 * `AttemptService`; a second reading of "may this be shown yet" is how two answers start
 * disagreeing, and the one that disagrees quietly is the one that leaks.
 */
final class Outcome {

	/** Sat, graded, released, and above the pass mark. */
	public const PASSED = 'passed';

	/** Sat, graded, released, and below it. */
	public const FAILED = 'failed';

	/**
	 * Sat, but the mark cannot be shown yet — awaiting marking, or awaiting release.
	 *
	 * ⚠️ THE TWO CAUSES ARE DELIBERATELY ONE STATE. Separating them would tell a candidate
	 * "your paper is marked, you just cannot see it", which on a scheduled release is
	 * exactly the information the schedule exists to withhold.
	 */
	public const AWAITING = 'awaiting';

	/** An attempt is open right now and can be resumed. */
	public const IN_PROGRESS = 'in_progress';

	/** Never opened. */
	public const NONE = 'none';

	/**
	 * Whether an exam's marks may be shown at all yet.
	 *
	 * Status is the CALLER's business: an in-progress attempt has no result to release, and
	 * this is asked about attempts that are over.
	 *
	 * @param array<string, mixed> $exam The exam row.
	 */
	public static function released( array $exam ): bool {
		return match ( (string) ( $exam['result_release'] ?? '' ) ) {
			'immediate'   => true,
			'after_close' => ! empty( $exam['open_until'] ) && current_time( 'mysql', true ) > $exam['open_until'],
			'scheduled'   => ! empty( $exam['release_at'] ) && current_time( 'mysql', true ) > $exam['release_at'],
			/*
			 * `manual` means an administrator releases it, which is not built yet; until it
			 * is, withholding is the correct behaviour rather than quietly releasing.
			 */
			default       => false,
		};
	}

	/**
	 * Turn one exam plus one aggregate row into something a template can render.
	 *
	 * ⚠️ THE ORDER OF THESE TESTS IS THE DESIGN, AND EACH STEP IS ARGUABLE ON ITS OWN.
	 *
	 * 1. An OPEN attempt wins over everything. It is the only state with an action attached
	 *    — resume — and that is what the reader needs from a list they are scanning.
	 * 2. PASSED beats awaiting. Somebody who has passed has passed; a later attempt still
	 *    being marked cannot un-pass them, and "awaiting" would read as though it could.
	 * 3. AWAITING beats failed. With one attempt marked down and another still pending,
	 *    "Not passed" is a verdict the site has not actually reached yet.
	 *
	 * @param array<string, mixed>      $exam      The exam row.
	 * @param array<string, mixed>|null $aggregate One row from `AttemptRepository::outcomes_for_user()`.
	 *
	 * @return array<string, mixed>
	 */
	public static function of( array $exam, ?array $aggregate ): array {
		$none = array(
			'state'     => self::NONE,
			'percent'   => null,
			'attempts'  => 0,
			'submitted' => null,
		);

		if ( null === $aggregate ) {
			return $none;
		}

		$finished = (int) ( $aggregate['finished'] ?? 0 );
		$open     = (int) ( $aggregate['open'] ?? 0 );

		$state = self::state( $exam, $aggregate, $finished, $open );

		return array(
			'state'    => $state,
			/*
			 * ⚠️ THE PERCENT TRAVELS ONLY WITH A DECIDED STATE. Attaching it to `awaiting`
			 * would send the withheld mark to the browser flagged as unshowable, and a
			 * withheld mark that reaches the page has not been withheld.
			 */
			'percent'  => in_array( $state, array( self::PASSED, self::FAILED ), true )
				? self::percent( $aggregate )
				: null,
			'attempts' => $finished,
			// Safe in every state: that you sat it is not the same as how you did.
			'submitted' => isset( $aggregate['last_submitted'] ) && '' !== (string) $aggregate['last_submitted']
				? (string) $aggregate['last_submitted']
				: null,
		);
	}

	/**
	 * @param array<string, mixed> $exam      The exam row.
	 * @param array<string, mixed> $aggregate The counted attempts.
	 */
	private static function state( array $exam, array $aggregate, int $finished, int $open ): string {
		if ( $open > 0 ) {
			return self::IN_PROGRESS;
		}

		if ( 0 === $finished ) {
			return self::NONE;
		}

		if ( ! self::released( $exam ) ) {
			return self::AWAITING;
		}

		if ( (int) ( $aggregate['passed'] ?? 0 ) > 0 ) {
			return self::PASSED;
		}

		if ( (int) ( $aggregate['ungraded'] ?? 0 ) > 0 ) {
			return self::AWAITING;
		}

		return self::FAILED;
	}

	/**
	 * @param array<string, mixed> $aggregate The counted attempts.
	 */
	private static function percent( array $aggregate ): ?float {
		$best = $aggregate['best_percent'] ?? null;

		return null === $best ? null : (float) $best;
	}
}
