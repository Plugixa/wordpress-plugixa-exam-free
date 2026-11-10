<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Notifications\Services;

use PlugixaExam\Core\Settings;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Listens to the attempt lifecycle and queues the mail it deserves.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * NOTIFICATIONS LISTEN; THE ATTEMPTS MODULE DOES NOT KNOW THEY EXIST.
 *
 * Everything here hangs off `plugixa_exam_attempt_*`, which `AttemptService` fires whether or
 * not anybody is listening. Switch the Notifications app off and the actions still fire into
 * nothing — no branch anywhere in the exam runner says "if notifications". That is what makes
 * the module removable, and it is the same seam a third-party add-on would use.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class AttemptNotifier {

	/**
	 * Closure reasons that mean the site closed the attempt, not the examinee.
	 *
	 * @var string[]
	 */
	private const AUTOMATIC = array( 'time_limit', 'idle', 'window' );

	/**
	 * @param Mailer            $mailer   The queue.
	 * @param AttemptRepository $attempts To read the paper.
	 * @param Settings          $settings For the admin address.
	 */
	public function __construct(
		private readonly Mailer $mailer,
		private readonly AttemptRepository $attempts,
		private readonly Settings $settings
	) {}

	/**
	 * An attempt has been closed and scored.
	 *
	 * @param int                  $attempt_id Attempt id.
	 * @param array<string, mixed> $exam       The exam row.
	 * @param string               $reason     Why it closed.
	 */
	public function on_submitted( int $attempt_id, array $exam, string $reason ): void {
		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return;
		}

		$automatic = in_array( $reason, self::AUTOMATIC, true );

		if ( $automatic && 'silent' === (string) ( $exam['auto_close_notify'] ?? 'auto' ) ) {
			return;
		}

		$name  = $this->examinee( $attempt );
		$to    = $this->address( $attempt );
		$staff_to = $this->admin_address();

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * TOKENS ARE BUILT INSIDE THE RECIPIENT'S LOCALE, NOT OUTSIDE IT.
		 *
		 * This is the part that actually matters, and it is easy to put in the wrong place.
		 * `Tokens::for_attempt()` is where `wp_date()` formats `{submitted}` and where
		 * `{outcome}` is a real `__( 'Passed' )` — so wrapping only the queueing call
		 * switches the locale AFTER every locale-sensitive value has already been computed,
		 * and changes nothing at all. The first version of this fix did exactly that and
		 * the test caught it.
		 *
		 * The two audiences are wrapped SEPARATELY because they are two different people:
		 * a French candidate and a German administrator get one event and two languages.
		 * ════════════════════════════════════════════════════════════════════════════════
		 */
		Mailer::in_recipient_locale(
			$to,
			function () use ( $attempt, $exam, $automatic, $to, $name, $attempt_id ): void {
				$this->mailer->queue(
					$automatic ? 'attempt_auto_closed' : 'attempt_submitted',
					$to,
					$name,
					$this->tokens( $attempt, $exam ),
					'attempt',
					$attempt_id,
					Tokens::blanked( $this->released( $attempt, $exam ) )
				);
			}
		);

		Mailer::in_recipient_locale(
			$staff_to,
			function () use ( $attempt, $exam, $staff_to, $name, $attempt_id ): void {
				/*
				 * Staff mail is NOT blanked. The release policy governs what the examinee
				 * may see, not what the site's own administrator may — and a "needs
				 * marking" notice that cannot say how much of the paper is already marked
				 * is not much of a notice.
				 */
				$staff = Tokens::for_attempt( $attempt, $exam, $name, true );

				$this->mailer->queue( 'attempt_submitted_admin', $staff_to, $name, $staff, 'attempt', $attempt_id );

				/*
				 * The marking queue notice, sent only when a person is actually needed.
				 * `is_graded` is false whenever any question came back unmarked, which for
				 * an auto-marked paper never happens — so this does not fire on every
				 * submission.
				 */
				if ( empty( $attempt['is_graded'] ) ) {
					$this->mailer->queue( 'needs_grading', $staff_to, $name, $staff, 'attempt', $attempt_id );
				}
			}
		);
	}

	/**
	 * Marking is finished.
	 *
	 * @param int                  $attempt_id Attempt id.
	 * @param array<string, mixed> $exam       The exam row.
	 */
	public function on_graded( int $attempt_id, array $exam ): void {
		$attempt = $this->attempts->find( $attempt_id );

		if ( null === $attempt ) {
			return;
		}

		$to   = $this->address( $attempt );
		$name = $this->examinee( $attempt );

		// Inside the switch, for the reason `on_submitted()` sets out at length: the date
		// and the outcome are computed by `tokens()`, not by `queue()`.
		Mailer::in_recipient_locale(
			$to,
			function () use ( $attempt, $exam, $to, $name, $attempt_id ): void {
				$this->mailer->queue(
					'attempt_graded',
					$to,
					$name,
					$this->tokens( $attempt, $exam ),
					'attempt',
					$attempt_id,
					Tokens::blanked( $this->released( $attempt, $exam ) )
				);
			}
		);
	}

	/**
	 * Token values for an attempt, with the release rule applied.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 * @param array<string, mixed> $exam    The exam row.
	 *
	 * @return array<string, string>
	 */
	private function tokens( array $attempt, array $exam ): array {
		return Tokens::for_attempt( $attempt, $exam, $this->examinee( $attempt ), $this->released( $attempt, $exam ) );
	}

	/**
	 * Whether the mark may appear in an email.
	 *
	 * ────────────────────────────────────────────────────────────────────────────────────
	 * DELIBERATELY STRICTER THAN THE RESULT PAGE.
	 *
	 * The page can re-check release on every visit, so `after_close` and `scheduled` show the
	 * mark once the moment arrives. An email cannot be un-sent, and it is composed now: for
	 * anything other than `immediate` the honest answer at THIS instant is no. The examinee
	 * still gets the link, and the page shows the mark when it is due.
	 * ────────────────────────────────────────────────────────────────────────────────────
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 * @param array<string, mixed> $exam    The exam row.
	 */
	private function released( array $attempt, array $exam ): bool {
		unset( $attempt );

		return 'immediate' === (string) ( $exam['result_release'] ?? 'immediate' );
	}

	/**
	 * Where to send the examinee's copy.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 */
	private function address( array $attempt ): string {
		if ( ! empty( $attempt['user_id'] ) ) {
			$user = get_userdata( (int) $attempt['user_id'] );

			if ( false !== $user ) {
				return (string) $user->user_email;
			}
		}

		return (string) ( $attempt['guest_email'] ?? '' );
	}

	/**
	 * Who to tell on the staff side.
	 */
	private function admin_address(): string {
		$configured = trim( $this->settings->str( 'notifications_admin_email', '' ) );

		return '' === $configured ? (string) get_option( 'admin_email' ) : $configured;
	}

	/**
	 * @param array<string, mixed> $attempt The attempt row.
	 */
	private function examinee( array $attempt ): string {
		if ( ! empty( $attempt['user_id'] ) ) {
			$user = get_userdata( (int) $attempt['user_id'] );

			if ( false !== $user ) {
				return (string) $user->display_name;
			}
		}

		$name = trim( (string) ( $attempt['guest_name'] ?? '' ) );

		return '' === $name ? __( 'Guest', 'plugixa-exam' ) : $name;
	}
}
