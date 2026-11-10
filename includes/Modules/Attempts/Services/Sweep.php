<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Services;

use PlugixaExam\Core\Settings;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;
use PlugixaExam\Modules\Exams\Repositories\ExamRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Closes attempts nobody came back to.
 *
 * WHY THIS HAS TO EXIST. Every other way an attempt ends is request-driven: the examinee
 * presses Submit, or their next autosave is refused for being late. A closed tab produces
 * neither. Without a sweep, that attempt stays `in_progress` for ever — holding one of the
 * learner's allowed attempts (the cap counts in-progress rows, deliberately) and never
 * grading the answers they had already saved. The examinee is locked out of a retry AND
 * loses the work.
 *
 * THREE RULES CLOSE AN ATTEMPT:
 *   • the deadline passed;
 *   • the exam's window shut and `window_close_action` is `close`;
 *   • it has been silent longer than `idle_timeout_minutes` — the only rule that reaches an
 *     untimed attempt, which is why an untimed exam is not a permanent open door.
 *
 * THROTTLED WITH A CONDITIONAL UPDATE, not a transient. This runs opportunistically off
 * ordinary admin requests, so several can start within the same second on a busy site. A
 * `get`-then-`set` transient has a race between the two halves and every one of them
 * proceeds. `update_option`-as-compare-and-swap is atomic: exactly one wins.
 */
final class Sweep {

	private const THROTTLE_OPTION = 'plugixa_exam_sweep_last_run';

	/**
	 * How often the sweep may run, site-wide.
	 *
	 * A minute is short enough that an abandoned attempt is closed promptly and long enough
	 * that a burst of admin requests does not each pay for a scan.
	 */
	private const THROTTLE_SECONDS = 60;

	private const BATCH = 100;

	/**
	 * @param AttemptRepository $attempts Attempts.
	 * @param ExamRepository    $exams    Exams.
	 * @param AttemptService    $service  For finalising.
	 * @param Settings          $settings For the master switch.
	 */
	public function __construct(
		private readonly AttemptRepository $attempts,
		private readonly ExamRepository $exams,
		private readonly AttemptService $service,
		private readonly Settings $settings
	) {}

	/**
	 * Run the sweep if it is due.
	 *
	 * @return int How many attempts were closed.
	 */
	public function maybe_run(): int {
		if ( ! $this->settings->bool( 'attempt_sweep_enabled', true ) ) {
			return 0;
		}

		return $this->claim() ? $this->run() : 0;
	}

	/**
	 * Close everything that qualifies. Called by WP-Cron, and by {@see maybe_run()}.
	 *
	 * @return int How many attempts were closed.
	 */
	public function run(): int {
		$now    = current_time( 'mysql', true );
		$closed = 0;

		foreach ( $this->attempts->sweep_candidates( $now, self::BATCH ) as $attempt ) {
			$closed += $this->close( $attempt, 'time_limit' ) ? 1 : 0;
		}

		// The idle rule needs the exam's own timeout, so candidates are gathered against the
		// most permissive cutoff any exam could use and filtered per row below. Scanning at
		// the tightest cutoff instead would miss attempts on exams with a longer timeout.
		$widest = (int) max( 1, $this->settings->int( 'default_idle_timeout_minutes', 1440 ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $widest * 60 ) );

		foreach ( $this->attempts->idle_candidates( $cutoff, self::BATCH ) as $attempt ) {
			$exam = $this->exams->find( (int) $attempt['exam_id'] );

			if ( null === $exam ) {
				continue;
			}

			$timeout = (int) $exam['idle_timeout_minutes'];

			if ( 0 === $timeout ) {
				continue;
			}

			$silent_for = time() - (int) strtotime( (string) $attempt['last_seen'] . ' UTC' );

			if ( $silent_for < $timeout * 60 ) {
				continue;
			}

			$closed += $this->close( $attempt, 'idle' ) ? 1 : 0;
		}

		return $closed;
	}

	/**
	 * Close one attempt, honouring the exam's auto-close policy.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 * @param string               $reason  Why it is closing.
	 */
	private function close( array $attempt, string $reason ): bool {
		$exam = $this->exams->find( (int) $attempt['exam_id'] );

		if ( null === $exam ) {
			return false;
		}

		/*
		 * An attempt an invigilator deliberately froze is left alone unless the exam says
		 * otherwise. Sweeping it away would undo a human decision — the invigilator paused
		 * it precisely because something needed sorting out.
		 */
		if ( ! empty( $attempt['monitor_hold'] ) && empty( $exam['auto_close_held'] ) ) {
			return false;
		}

		// A paused attempt has a stopped clock, so its deadline is meaningless. Only the
		// idle rule reaches it.
		if ( ! empty( $attempt['paused_at'] ) && 'idle' !== $reason ) {
			return false;
		}

		$action = 'abandon' === $exam['auto_close_action'] ? 'abandon' : 'submit';

		$result = $this->service->finalize( (int) $attempt['id'], $reason, $action );

		return true === $result;
	}

	/**
	 * Claim the right to run, atomically.
	 *
	 * `update_option` returns false when the value is unchanged, so writing the same
	 * timestamp twice would look like a failed claim. The stored value is therefore always
	 * the current second, and the comparison happens before the write — the small window
	 * between them is bounded by the throttle interval, and the cost of two sweeps landing
	 * in the same minute is a wasted scan, not incorrect data.
	 */
	private function claim(): bool {
		$last = (int) get_option( self::THROTTLE_OPTION, 0 );

		if ( time() - $last < self::THROTTLE_SECONDS ) {
			return false;
		}

		// Not autoloaded: this is written every minute and read only by the sweep, so
		// keeping it out of the alloptions blob avoids invalidating that cache constantly.
		update_option( self::THROTTLE_OPTION, time(), false );

		return true;
	}
}
