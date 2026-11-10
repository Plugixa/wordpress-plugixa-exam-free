<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Services;

use PlugixaExam\Core\Security\GuestToken;
use PlugixaExam\Core\Settings;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;
use PlugixaExam\Modules\Exams\Repositories\ExamRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides whether somebody may start or resume an exam, and why not.
 *
 * RETURNS A REASON, NEVER JUST A BOOLEAN. "You cannot take this exam" is useless to a
 * learner staring at it five minutes before a deadline; "you have used all 3 attempts" and
 * "this exam opens on Friday at 09:00" are things they can act on. Every outcome below is
 * rendered as its own message.
 *
 * NO PHP SESSIONS ANYWHERE. Access-code clearance lives in a transient keyed by the signed
 * guest cookie — see {@see GuestToken}. `session_start()` would make every exam page
 * uncacheable and is a documented WordPress.org rejection.
 */
final class AccessGate {

	/**
	 * @param ExamRepository    $exams    Exams.
	 * @param AttemptRepository $attempts Attempts.
	 * @param Settings          $settings For the anonymous-attempt policy.
	 */
	public function __construct(
		private readonly ExamRepository $exams,
		private readonly AttemptRepository $attempts,
		private readonly Settings $settings
	) {}

	/**
	 * Evaluate access to an exam.
	 *
	 * @param array<string, mixed> $exam The exam row.
	 * @param array<string, mixed> $input Optional `access_code`, `guest_name`, `guest_email`.
	 *
	 * @return array{
	 *     allowed: bool,
	 *     reason: string,
	 *     message: string,
	 *     needs?: string,
	 *     attempts_used?: int,
	 *     attempts_allowed?: int,
	 *     resume?: int,
	 *     cooldown_minutes?: int
	 * }
	 */
	public function evaluate( array $exam, array $input = array() ): array {
		if ( empty( $exam['published'] ) ) {
			return $this->deny( 'unpublished', __( 'This exam is not available.', 'plugixa-exam' ) );
		}

		$window = $this->check_window( $exam );

		if ( null !== $window ) {
			return $window;
		}

		$user_id = get_current_user_id();
		$token   = GuestToken::current();

		// An identity check comes before the attempt count, because counting attempts needs
		// to know whose they are.
		$identity = $this->check_identity( $exam, $input, $user_id, $token );

		if ( null !== $identity ) {
			return $identity;
		}

		// Re-read: a correct access code mints a token to record the clearance against.
		$token = GuestToken::current();

		// An attempt already open is a resume, and outranks the cap — otherwise somebody on
		// their last attempt could not finish the one they are sitting.
		$open = $this->attempts->open_attempt( (int) $exam['id'], $user_id ?: null, $token );

		if ( null !== $open ) {
			return array(
				'allowed' => true,
				'reason'  => 'resume',
				'message' => __( 'You have an attempt in progress.', 'plugixa-exam' ),
				'resume'  => (int) $open['id'],
			);
		}

		/**
		 * Additional requirements a learner must meet before starting.
		 *
		 * ────────────────────────────────────────────────────────────────────────────────
		 * FIRED AFTER THE RESUME CHECK, WHICH IS THE WHOLE POINT OF WHERE IT SITS.
		 *
		 * An attempt already in progress outranks this exactly as it outranks the attempt
		 * cap: somebody halfway through a paper must be able to finish it, whatever a rule
		 * added since they started now says. A requirement that could strand a live sitting
		 * is a requirement that loses somebody's work.
		 *
		 * It fires BEFORE the cap so that "you have not met the requirements" is preferred
		 * over "you have used all your attempts" — a learner who cannot start yet has not
		 * spent anything, and telling them they are out of attempts would be false.
		 *
		 * Listeners return a gate result to refuse, or null to say nothing. They are handed
		 * the resolved identity rather than being left to work it out, so every listener
		 * judges the same person this gate is judging.
		 * ────────────────────────────────────────────────────────────────────────────────
		 *
		 * @param array<string, mixed>|null $verdict A gate result, or null to continue.
		 * @param array<string, mixed>      $exam    The exam row.
		 * @param int                       $user_id The learner, or 0 for a guest.
		 * @param string|null               $token   The guest token, if any.
		 */
		$requirements = apply_filters( 'plugixa_exam_access_requirements', null, $exam, $user_id, $token );

		if ( is_array( $requirements ) ) {
			return $requirements;
		}

		$cap = $this->check_attempts( $exam, $user_id ?: null, $token );

		if ( null !== $cap ) {
			return $cap;
		}

		return array(
			'allowed' => true,
			'reason'  => 'ok',
			'message' => '',
		);
	}

	/**
	 * Why the signed-in owner of an attempt may no longer carry on with it — or null.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * A PAPER WHOSE OWNER HAS LOST THE EXAM STOPS, EVERYWHERE AT ONCE.
	 *
	 * `evaluate()` settles identity before it looks for an attempt to resume, so a learner
	 * removed from the exam — unenrolled from the linked course, dropped from the list,
	 * stripped of the role — was already refused by the landing page, `take/` and `start`.
	 * The runner routes checked ownership only, so the same learner could still load the
	 * paper, save answers and submit: measured on the LMS audit sites, an unenrolled
	 * learner submitted and was graded 100 %. Two halves of the plugin disagreed about
	 * whether the exam was theirs.
	 *
	 * DELIBERATELY NARROW:
	 * - Registered owners only. A guest's identity is their cookie, which nobody revokes;
	 *   and staff are not the one sitting it — they are refused the paper before this is
	 *   asked (see {@see Ownership}), and reading a result does not ask it at all.
	 * - `access_level` and `restricted` only: the two types whose answer can change under
	 *   a learner's feet.
	 * - Reason `denied` only. A Cohorts rule whose WINDOW closes mid-sitting answers
	 *   `closed`, and stranding somebody at 16:59 because their slot ends at 17:00 is the
	 *   opposite of what a window is for; the exam's own close time is the sweep's job.
	 * - Identity only, never the requirements seam — prerequisites and paid access keep
	 *   their promise not to strand a paper already started (see `evaluate()`).
	 *
	 * The attempt itself is left exactly as it is. Access restored, it resumes.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 *
	 * @return array<string, mixed>|null The refusal, or null to carry on.
	 */
	public function revoked( array $attempt ): ?array {
		$user_id = (int) ( $attempt['user_id'] ?? 0 );

		if ( 0 === $user_id || get_current_user_id() !== $user_id ) {
			return null;
		}

		$exam = $this->exams->find( (int) ( $attempt['exam_id'] ?? 0 ) );

		if ( null === $exam || ! in_array( (string) ( $exam['access_type'] ?? '' ), array( 'access_level', 'restricted' ), true ) ) {
			return null;
		}

		$verdict = $this->check_identity( $exam, array(), $user_id, null );

		if ( ! is_array( $verdict ) || ! empty( $verdict['allowed'] ) || 'denied' !== ( $verdict['reason'] ?? '' ) ) {
			return null;
		}

		return $verdict;
	}

	/**
	 * The exam's open/close window.
	 *
	 * @param array<string, mixed> $exam The exam row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function check_window( array $exam ): ?array {
		$now = current_time( 'mysql', true );

		if ( ! empty( $exam['open_from'] ) && $now < $exam['open_from'] ) {
			return $this->deny(
				'not_open',
				sprintf(
					/* translators: %s: the date and time the exam opens. */
					__( 'This exam opens on %s.', 'plugixa-exam' ),
					$this->local( (string) $exam['open_from'] )
				)
			);
		}

		if ( ! empty( $exam['open_until'] ) && $now > $exam['open_until'] ) {
			return $this->deny( 'closed', __( 'This exam is closed.', 'plugixa-exam' ) );
		}

		return null;
	}

	/**
	 * Who the examinee is, per the exam's access type.
	 *
	 * @param array<string, mixed> $exam    The exam row.
	 * @param array<string, mixed> $input   Supplied credentials.
	 * @param int                  $user_id Current user, 0 if none.
	 * @param string|null          $token   Existing guest token.
	 *
	 * @return array<string, mixed>|null Null when identity is settled.
	 */
	private function check_identity( array $exam, array $input, int $user_id, ?string $token ): ?array {
		$type = (string) $exam['access_type'];

		/**
		 * Override the access decision entirely.
		 *
		 * An authenticated LTI launch uses this to bypass the per-exam gate: the platform
		 * has already established who the learner is, and asking them for an access code
		 * inside an LMS iframe is both redundant and, in many LMS embeds, impossible.
		 *
		 * @param array<string, mixed>|null $override A gate result, or null to continue.
		 * @param array<string, mixed>      $exam     The exam row.
		 */
		$override = apply_filters( 'plugixa_exam_access_override', null, $exam );

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * A REFUSAL STANDS; AN ADMISSION SETTLES WHO THE EXAMINEE IS, AND NOTHING ELSE.
		 *
		 * Returned whole, an admission also skipped everything `evaluate()` does once identity
		 * is known. The resume check: `take/` never found the paper the learner had just
		 * started, sent them back to the landing page, and every press of Start opened another
		 * attempt. The requirements. And the attempt cap, so there was no cap at all. Found by
		 * launching from a real Moodle 5.2 on the audit site — every LTI learner was stuck on
		 * the landing page, burning an attempt per click.
		 * ════════════════════════════════════════════════════════════════════════════════
		 */
		if ( is_array( $override ) ) {
			return empty( $override['allowed'] ) ? $override : null;
		}

		if ( 'access_level' === $type ) {
			if ( 0 === $user_id ) {
				return $this->deny( 'login_required', __( 'Please log in to take this exam.', 'plugixa-exam' ) );
			}

			$required = (string) ( $exam['access_level'] ?? '' );

			if ( '' !== $required && ! user_can( $user_id, $required ) && ! in_array( $required, (array) wp_get_current_user()->roles, true ) ) {
				return $this->deny( 'denied', __( 'You do not have access to this exam.', 'plugixa-exam' ) );
			}

			return null;
		}

		if ( 'restricted' === $type ) {
			if ( 0 === $user_id ) {
				return $this->deny( 'login_required', __( 'Please log in to take this exam.', 'plugixa-exam' ) );
			}

			/**
			 * Whether a user matches an exam's access rules.
			 *
			 * Answered by the Cohorts module. With that module absent the default below
			 * stands: `restricted` denies everyone but administrators, which is the safe
			 * direction — an exam whose restriction list cannot be read should not become
			 * an open exam.
			 *
			 * A listener may answer with a BOOLEAN or with a full gate result ARRAY. The
			 * array exists so a module can say WHY: somebody on the list whose window opens
			 * on Friday is in a completely different position from somebody who is not on
			 * the list, and "you do not have access" is the wrong sentence for the first of
			 * them. Returning the reason is only safe for a user who MATCHED a rule — see
			 * `Cohorts\Services\Access::verdict()`, which never discloses a window to
			 * anybody it did not already authorise.
			 *
			 * @param bool                 $allowed Whether the user matches.
			 * @param array<string, mixed> $exam    The exam row.
			 * @param int                  $user_id The user.
			 */
			$verdict = apply_filters(
				'plugixa_exam_user_in_exam_access',
				user_can( $user_id, 'manage_options' ),
				$exam,
				$user_id
			);

			if ( is_array( $verdict ) ) {
				return $verdict;
			}

			return $verdict ? null : $this->deny( 'denied', __( 'You do not have access to this exam.', 'plugixa-exam' ) );
		}

		if ( 'code' === $type ) {
			$cleared = GuestToken::has_gate( (int) $exam['id'] );

			if ( ! $cleared ) {
				/*
				 * The function the stored code went through when the exam was saved, so that
				 * the two sides of the comparison below were cleaned the same way. `trim()`
				 * alone refused a candidate who typed the code exactly as the administrator
				 * had — two spaces and all — because the stored copy had one. Here rather
				 * than only at each door: the form and the REST route both arrive at this line.
				 */
				$supplied = sanitize_text_field( (string) ( $input['access_code'] ?? '' ) );

				if ( '' === $supplied ) {
					return $this->needs( 'code', __( 'This exam needs an access code.', 'plugixa-exam' ) );
				}

				$expected = $this->exams->access_code( (int) $exam['id'] );

				// Timing-safe: a plain `!==` leaks the code one character at a time to
				// anybody willing to measure. It costs nothing to do properly.
				if ( '' === $expected || ! hash_equals( $expected, $supplied ) ) {
					return $this->deny(
						'bad_code',
						__( 'That did not work. Please check your details and try again.', 'plugixa-exam' )
					);
				}

				GuestToken::grant_gate( (int) $exam['id'] );
			}

			// Clearing the code settles authorisation, not identity — fall through to the
			// anonymous policy for that.
		}

		if ( 0 !== $user_id ) {
			return null;
		}

		return $this->anonymous_identity( $exam, $input, $token );
	}

	/**
	 * How an anonymous visitor may proceed.
	 *
	 * @param array<string, mixed> $exam  The exam row.
	 * @param array<string, mixed> $input Supplied details.
	 * @param string|null          $token Existing guest token.
	 *
	 * @return array<string, mixed>|null
	 */
	private function anonymous_identity( array $exam, array $input, ?string $token ): ?array {
		$policy = $this->settings->str( 'anonymous_attempts', 'token' );

		// `guest` always collects a name and email, whatever the site-wide policy says —
		// that is the entire meaning of choosing it for an exam.
		if ( 'guest' === $exam['access_type'] ) {
			$policy = 'identify';
		}

		if ( 'login' === $policy ) {
			return $this->deny( 'login_required', __( 'Please log in to take this exam.', 'plugixa-exam' ) );
		}

		if ( 'identify' === $policy && null === $token ) {
			$name  = sanitize_text_field( (string) ( $input['guest_name'] ?? '' ) );
			$email = sanitize_email( (string) ( $input['guest_email'] ?? '' ) );

			if ( '' === trim( $name ) || ! is_email( $email ) ) {
				return $this->needs( 'identify', __( 'Please tell us who you are before starting.', 'plugixa-exam' ) );
			}
		}

		/*
		 * ⚠️ NO TOKEN IS MINTED HERE, AND THERE USED TO BE.
		 *
		 * `GuestToken::ensure()` sat on this line "so the attempt can be written against it
		 * in this same request". But this gate also draws every exam's landing page, so a
		 * first-time visitor who merely LOOKED at an exam was sent a `Set-Cookie` — and a
		 * response that sets a cookie is one Varnish, Nginx and managed-host page caches will
		 * not store, so no public exam page was ever served from cache. Measured with curl
		 * on the dev site before this changed.
		 *
		 * Nothing needed it. `AttemptService::start()` mints the token itself immediately
		 * before it writes the attempt, and every verdict above and below treats a missing
		 * token exactly as it treats a new one: no attempts, no resume, nothing used.
		 */
		return null;
	}

	/**
	 * The attempt cap and the cooldown.
	 *
	 * @param array<string, mixed> $exam    The exam row.
	 * @param int|null             $user_id Current user.
	 * @param string|null          $token   Guest token.
	 *
	 * @return array<string, mixed>|null
	 */
	private function check_attempts( array $exam, ?int $user_id, ?string $token ): ?array {
		$latest = $this->attempts->latest_for( (int) $exam['id'], $user_id, $token );

		if ( null !== $latest && 'locked_review' === $latest['status'] ) {
			return $this->deny(
				'locked',
				__( 'Your previous attempt is awaiting review. You cannot start a new attempt right now.', 'plugixa-exam' )
			);
		}

		$used = $this->attempts->count_attempts( (int) $exam['id'], $user_id, $token );

		$allowed = (int) $exam['max_attempts'];

		if ( $allowed > 0 ) {
			$allowed += null === $latest ? 0 : (int) $latest['extra_attempts_granted'];

			if ( $used >= $allowed ) {
				return array(
					'allowed'          => false,
					'reason'           => 'max_attempts',
					'message'          => __( 'You have reached the maximum number of attempts for this exam.', 'plugixa-exam' ),
					'attempts_used'    => $used,
					'attempts_allowed' => $allowed,
				);
			}
		}

		$cooldown = (int) $exam['cooldown_minutes'];

		if ( $cooldown > 0 && null !== $latest && ! empty( $latest['submitted'] ) ) {
			$elapsed = ( time() - (int) strtotime( (string) $latest['submitted'] . ' UTC' ) ) / 60;

			if ( $elapsed < $cooldown ) {
				$remaining = (int) ceil( $cooldown - $elapsed );

				return array(
					'allowed'          => false,
					'reason'           => 'cooldown',
					'message'          => sprintf(
						/* translators: %d: number of minutes. */
						_n(
							'You can retake this exam in %d minute.',
							'You can retake this exam in %d minutes.',
							$remaining,
							'plugixa-exam'
						),
						$remaining
					),
					'cooldown_minutes' => $remaining,
				);
			}
		}

		return null;
	}

	/**
	 * A refusal.
	 *
	 * @param string $reason  Machine-readable reason.
	 * @param string $message Human-readable message.
	 *
	 * @return array<string, mixed>
	 */
	private function deny( string $reason, string $message ): array {
		return array(
			'allowed' => false,
			'reason'  => $reason,
			'message' => $message,
		);
	}

	/**
	 * A refusal that the examinee can resolve by supplying something.
	 *
	 * Distinct from {@see deny()} so the front end knows to render a form rather than a
	 * dead end — the difference between "give us your name" and "you may not take this".
	 *
	 * @param string $needs   What is required: `code` or `identify`.
	 * @param string $message Human-readable message.
	 *
	 * @return array<string, mixed>
	 */
	private function needs( string $needs, string $message ): array {
		return array(
			'allowed' => false,
			'reason'  => 'needs_' . $needs,
			'needs'   => $needs,
			'message' => $message,
		);
	}

	/**
	 * A UTC datetime rendered in the site's timezone and format.
	 *
	 * @param string $utc `Y-m-d H:i:s` in UTC.
	 */
	private function local( string $utc ): string {
		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			(int) strtotime( $utc . ' UTC' )
		);
	}
}
