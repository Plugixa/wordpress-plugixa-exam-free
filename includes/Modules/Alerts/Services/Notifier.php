<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Alerts\Services;

use PlugixaExam\Core\Security\Permissions;
use PlugixaExam\Modules\Alerts\Repositories\AlertRepository;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns things that happen into rows in somebody's inbox.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════════
 * THIS IS A SECOND CHANNEL, NOT A SECOND POLICY.
 *
 * The email module already decides who is told what, and the temptation is to re-derive that
 * here — which is how two systems that are supposed to agree start disagreeing, silently, on a
 * site nobody is watching. So this listens to the SAME two actions `AttemptNotifier` listens to
 * and applies the SAME release gate, and where it differs it differs on purpose:
 *
 *   - an alert carries NO SCORE, ever. It says the result is ready and links to the page that
 *     checks permission properly. A row in a database table read by a polling endpoint is a
 *     worse place to put a mark than an email is, and the email at least had a template an
 *     administrator could see;
 *   - and it is only written for people who HAVE AN ACCOUNT. Guest attempts are keyed by a
 *     signed cookie, not a user id, so a guest has nowhere to put an inbox and asking for one
 *     would mean inventing an identity for somebody who declined to give one.
 * ════════════════════════════════════════════════════════════════════════════════════════════
 */
final class Notifier {

	/**
	 * The exam is never injected: both events hand over the exam ROW as it was when they fired,
	 * which is the snapshot this table wants. See `on_submitted()`.
	 *
	 * @param AlertRepository   $alerts   The inboxes.
	 * @param AttemptRepository $attempts For the attempt behind an event.
	 */
	public function __construct(
		private readonly AlertRepository $alerts,
		private readonly AttemptRepository $attempts
	) {}

	/**
	 * Write one alert.
	 *
	 * The only way in, and it is deliberately a plain method rather than a repository call, so
	 * that every producer — including `do_action( 'plugixa_exam_alert', … )` from another
	 * plugin — goes through the same normalisation.
	 *
	 * @param int    $user_id      Who is being told.
	 * @param string $type         What kind of thing happened, e.g. `result_ready`.
	 * @param string $subject      What it was about, as it reads NOW.
	 * @param string $context_type Entity type, e.g. `attempt`.
	 * @param int    $context_id   Entity id.
	 */
	public function notify(
		int $user_id,
		string $type,
		string $subject,
		string $context_type = '',
		int $context_id = 0
	): int {
		// A user id of 0 is a guest, a cron run or a bug, and all three want the same answer.
		if ( $user_id <= 0 || '' === $type ) {
			return 0;
		}

		return $this->alerts->insert(
			array(
				'user_id'      => $user_id,
				'alert_type'   => sanitize_key( $type ),
				// Trimmed to the column rather than truncated by MySQL, which on a strict server
				// is an error and on a lenient one is silent data loss.
				'subject'      => mb_substr( sanitize_text_field( $subject ), 0, 255 ),
				'context_type' => sanitize_key( $context_type ),
				'context_id'   => $context_id,
				'read_at'      => null,
			)
		);
	}

	/**
	 * A paper was submitted: tell whoever has to mark it.
	 *
	 * Only when a human is actually needed. An auto-graded paper that closed itself is not news,
	 * and a bell that rings for every submission on a busy site is a bell people learn to ignore
	 * — which costs more than it gives, because the marking alert is the one that matters.
	 *
	 * THE EXAM ARRIVES AS A ROW, not an id, which is worth noticing rather than working around:
	 * the event hands over the exam as it was AT THE MOMENT the paper closed, which is exactly
	 * the snapshot this table wants. Looking the exam up again here would read a title that may
	 * already have been edited, and would fail entirely on an exam deleted between the two.
	 *
	 * @param int                  $attempt_id The attempt.
	 * @param array<string, mixed> $exam       The exam row.
	 * @param string               $reason     Why it closed.
	 */
	public function on_submitted( int $attempt_id, array $exam, string $reason = '' ): void {
		unset( $reason );

		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt || ! empty( $attempt['is_graded'] ) ) {
			return;
		}

		$title = (string) ( $exam['title'] ?? '' );

		foreach ( $this->markers() as $user_id ) {
			$this->notify( $user_id, 'needs_marking', $title, 'attempt', $attempt_id );
		}
	}

	/**
	 * A paper was graded: tell the candidate, if they are allowed to know yet.
	 *
	 * THE RELEASE GATE IS THE SAME ONE THE EMAIL USES, and it has to be. An alert reading "your
	 * result for Physics is ready" on an exam set to release in a fortnight tells the candidate
	 * their paper has been marked, which is a thing the release policy exists to not say.
	 *
	 * @param int                  $attempt_id The attempt.
	 * @param array<string, mixed> $exam       The exam row.
	 */
	public function on_graded( int $attempt_id, array $exam ): void {
		if ( 'immediate' !== (string) ( $exam['result_release'] ?? 'immediate' ) ) {
			return;
		}

		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return;
		}

		$user_id = (int) ( $attempt['user_id'] ?? 0 );

		if ( $user_id <= 0 ) {
			return;
		}

		$this->notify( $user_id, 'result_ready', (string) ( $exam['title'] ?? '' ), 'attempt', $attempt_id );
	}

	/**
	 * Results held back are let go: tell everybody whose paper is already marked.
	 *
	 * `on_graded()` stays silent for an exam that does not release at once, which is right
	 * at that moment and left a hole afterwards: when the author later switched the exam to
	 * release immediately, nobody who had been waiting heard about it. This is the other
	 * half. Only a change TO "immediately" counts, the one rule under which a result is
	 * certainly visible the moment this runs; the dated rules release by the clock.
	 *
	 * Somebody already told about a paper is not told again, so switching the rule back
	 * and forth cannot fill a feed.
	 *
	 * @param int    $exam_id Exam id.
	 * @param string $before  The rule the exam had.
	 * @param string $after   The rule it has now.
	 * @param string $title   The exam's title.
	 */
	public function on_release_changed( int $exam_id, string $before, string $after, string $title = '' ): void {
		if ( 'immediate' !== $after || 'immediate' === $before ) {
			return;
		}

		foreach ( $this->attempts->marked_for_exam( $exam_id ) as $attempt ) {
			$user_id    = (int) $attempt['user_id'];
			$attempt_id = (int) $attempt['id'];

			if ( $this->alerts->exists( $user_id, 'result_ready', 'attempt', $attempt_id ) ) {
				continue;
			}

			$this->notify( $user_id, 'result_ready', $title, 'attempt', $attempt_id );
		}
	}

	/**
	 * Who should hear that a paper is waiting.
	 *
	 * Asked of the permission layer rather than of a role name, because who may mark is
	 * configurable — the Roles module answers `plugixa_exam_user_can`, and a site that made
	 * "Marker" a custom role would otherwise be told nothing at all.
	 *
	 * Capped, and that is a real limit rather than an oversight: writing one row per
	 * administrator on a site with two thousand of them turns a submission into a bulk insert.
	 * A site that needs to fan out further should hook `plugixa_exam_alert_markers`.
	 *
	 * @return int[]
	 */
	private function markers(): array {
		$users = get_users(
			array(
				'capability__in' => array( 'plugixa_exam_manage', 'edit_posts' ),
				'number'         => 20,
				'fields'         => 'ID',
			)
		);

		$markers = array();

		foreach ( $users as $user_id ) {
			if ( Permissions::user_can( 'attempts', 'grade', (int) $user_id ) ) {
				$markers[] = (int) $user_id;
			}
		}

		/**
		 * Who is told that a paper needs marking.
		 *
		 * @param int[] $markers User ids.
		 */
		return array_unique( (array) apply_filters( 'plugixa_exam_alert_markers', $markers ) );
	}
}
