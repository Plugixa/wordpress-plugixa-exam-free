<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Frontend\AnswerSanitizer;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\Attempts\Calendar\AttemptCalendarSource;
use PlugixaExam\Modules\Attempts\Frontend\RunnerBridge;
use PlugixaExam\Modules\Attempts\Http\AttemptsController;
use PlugixaExam\Modules\Attempts\Http\RunnerController;
use PlugixaExam\Modules\Attempts\Privacy\AttemptPrivacy;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;
use PlugixaExam\Modules\Attempts\Services\AttemptService;
use PlugixaExam\Modules\Attempts\Services\Sweep;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Attempts: sitting an exam, and what comes of it.
 */
final class Module implements ModuleInterface {

	private const SWEEP_HOOK = 'plugixa_exam_sweep';

	public function id(): string {
		return 'attempts';
	}

	public function app(): array {
		return array(
			'category' => 'core',
			'core'     => true,
		);
	}

	public function migrate(): void {
		Schema::install();
	}

	public function capabilities(): array {
		return array();
	}

	public function register_services( Container $container ): void {
		/*
		 * The no-JavaScript form cleans what it read BEFORE it fires a hook, and only this
		 * module knows how: which questions are on the frozen paper and what each type's
		 * answer should look like. Core cannot name a module, so the cleaner is handed to it.
		 * Resolved inside the closure, so the service is built only if a form is posted.
		 */
		$container->get( AnswerSanitizer::class )->clean_with(
			static fn( int $attempt_id, array $answers ): array => $container->get( AttemptService::class )->sanitize_answers( $attempt_id, $answers )
		);
	}

	public function register_routes( Container $container ): void {
		$container->get( RunnerController::class )->register_routes();
		$container->get( AttemptsController::class )->register_routes();
	}

	public function register_hooks( Container $container ): void {
		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );

		// The front end's door into the attempt lifecycle. Every method on it checks
		// ownership, because admin-post.php is reachable by anyone.
		$container->get( RunnerBridge::class )->register_hooks();

		/*
		 * Attempts are the plugin's main store of personal data — a name, an address and
		 * whatever somebody wrote in an essay box. This registers unconditionally, with no
		 * settings check of any kind, because a site's ability to answer a subject access
		 * or erasure request is not a feature it can be configured out of. See
		 * {@see AttemptPrivacy} for the erasure policy and why it anonymises rather than
		 * deletes.
		 */
		$container->get( AttemptPrivacy::class )->register_hooks();

		/*
		 * Retention: anonymise finished attempts older than the site keeps them. The hook is
		 * only scheduled while a period is set (Ecosystem). A full batch means a backlog, so
		 * the next batch runs in five minutes rather than tomorrow — the first run after a
		 * site turns this on may have years of attempts to work through.
		 */
		add_action(
			'plugixa_exam_privacy_retention',
			static function () use ( $container ): void {
				if ( $container->get( Privacy\Retention::class )->run() >= Privacy\Retention::BATCH ) {
					wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'plugixa_exam_privacy_retention' );
				}
			}
		);

		// Daily sitting counts on the admin calendar. Aggregated rather than one event per
		// attempt — see {@see AttemptCalendarSource} for why that matters on exam day.
		$container->get( AttemptCalendarSource::class )->register_hooks();

		$repository = $container->get( AttemptRepository::class );

		add_action(
			'plugixa_exam_before_purge',
			static function ( string $entity_type, int $id ) use ( $repository ): void {
				if ( 'attempt' === $entity_type ) {
					$repository->delete_responses( $id );
				}
			},
			10,
			2
		);

		$sweep = $container->get( Sweep::class );

		/*
		 * TWO TRIGGERS, DELIBERATELY.
		 *
		 * WP-Cron is the scheduled one, and on a low-traffic site it is the only one that
		 * fires — but WP-Cron only runs when somebody visits, so on a site with no visitors
		 * it does not run at all. The opportunistic trigger below covers the case that
		 * matters most: an administrator looking at the attempts list should not see an
		 * abandoned attempt still sitting there as "in progress".
		 *
		 * Both go through `maybe_run()`, which is throttled to once a minute site-wide, so
		 * having two triggers costs nothing.
		 */
		add_action( self::SWEEP_HOOK, array( $sweep, 'run' ) );

		\PlugixaExam\Core\Cron::ensure( self::SWEEP_HOOK, 'hourly', MINUTE_IN_SECONDS );

		add_action(
			'plugixa_exam_attempts_listed',
			static function () use ( $sweep ): void {
				$sweep->maybe_run();
			}
		);
	}

	/**
	 * @param array<string, string> $strings Accumulated strings.
	 *
	 * @return array<string, string>
	 */
	public function strings( array $strings ): array {
		return array_merge(
			$strings,
			array(
				'attempts'         => __( 'Attempts', 'plugixa-exam' ),
				'attemptsDesc' => __( 'Every paper sat: the answers, the scores, and the work waiting to be marked.', 'plugixa-exam' ),
				'attempt'          => __( 'Attempt', 'plugixa-exam' ),
				'attemptsEmpty'    => __( 'Nobody has sat an exam yet.', 'plugixa-exam' ),
				'attemptsIntro'    => __( 'Every sitting of every exam, with what it scored and what still needs marking.', 'plugixa-exam' ),
				'examinee'         => __( 'Examinee', 'plugixa-exam' ),
				'guest'            => __( 'Guest', 'plugixa-exam' ),
				'score'            => __( 'Score', 'plugixa-exam' ),
				'percent'          => __( 'Percent', 'plugixa-exam' ),
				'outcome'          => __( 'Outcome', 'plugixa-exam' ),
				'passedLabel'      => __( 'Passed', 'plugixa-exam' ),
				'failedLabel'      => __( 'Not passed', 'plugixa-exam' ),
				'statusInProgress' => __( 'In progress', 'plugixa-exam' ),
				'statusSubmitted'  => __( 'Awaiting marking', 'plugixa-exam' ),
				'statusGraded'     => __( 'Graded', 'plugixa-exam' ),
				'statusLocked'     => __( 'Locked for review', 'plugixa-exam' ),
				'statusAbandoned'  => __( 'Abandoned', 'plugixa-exam' ),
				'needsGrading'     => __( 'Needs marking', 'plugixa-exam' ),
				'submittedAt'      => __( 'Submitted', 'plugixa-exam' ),
				'timeSpent'        => __( 'Time taken', 'plugixa-exam' ),
				'False'                    => __( 'False', 'plugixa-exam' ),
				'True'                     => __( 'True', 'plugixa-exam' ),
				'allExams'                 => __( 'All exams', 'plugixa-exam' ),
				'allStatuses'              => __( 'Any status', 'plugixa-exam' ),
				'attemptNotFound'          => __( 'This attempt could not be found.', 'plugixa-exam' ),
				'autoMarked'               => __( 'Marked automatically', 'plugixa-exam' ),
				'byHand'                   => __( 'By hand', 'plugixa-exam' ),
				'chose'                    => __( 'chose', 'plugixa-exam' ),
				'confirmDeleteAttempt'     => __( 'Delete this attempt?', 'plugixa-exam' ),
				'confirmDeleteAttemptBody' => __( 'The paper and every answer on it are removed for good. This cannot be undone.', 'plugixa-exam' ),
				'correct'                  => __( 'correct', 'plugixa-exam' ),
				'graderComment'            => __( 'Feedback on the whole paper', 'plugixa-exam' ),
				'graderCommentHelp'        => __( 'Shown to the examinee with their result.', 'plugixa-exam' ),
				'heldFromExaminee'         => __( 'Not released yet', 'plugixa-exam' ),
				/* translators: %d is a number. */
				'hoursShort'               => __( '%d h', 'plugixa-exam' ),
				'mark'                     => __( 'Mark', 'plugixa-exam' ),
				'markPaper'                => __( 'Mark paper', 'plugixa-exam' ),
				'markingSaved'             => __( 'Marking saved', 'plugixa-exam' ),
				/* translators: %d is a number. */
				'minutesShort'             => __( '%d min', 'plugixa-exam' ),
				/* translators: %d is a number. */
				'nUnmarked'                => __( '%d still unmarked', 'plugixa-exam' ),
				'needsMarking'             => __( 'Needs marking', 'plugixa-exam' ),
				'noAnswerGiven'            => __( '— no answer given —', 'plugixa-exam' ),
				'notPassed'                => __( 'Not passed', 'plugixa-exam' ),
				/* translators: %d is a number. */
				'outOfN'                   => __( 'out of %s', 'plugixa-exam' ),
				/* translators: %d is a number. */
				'passMarkIs'               => __( 'Pass mark %s%', 'plugixa-exam' ),
				'passed'                   => __( 'Passed', 'plugixa-exam' ),
				'pointsAwarded'            => __( 'Points', 'plugixa-exam' ),
				'provisional'              => __( 'Provisional', 'plugixa-exam' ),
				'questionFeedback'         => __( 'Note on this answer', 'plugixa-exam' ),
				// Must match the comment on the same msgid in `templates/frontend/take.php`
				// and `result.php`: gettext keys on the string, so two different translator
				// comments for one msgid become one warning and one arbitrary winner.
				/* translators: %d: question number. */
				'questionN'                => __( 'Question %d', 'plugixa-exam' ),
				'runningTotal'             => __( 'Running total', 'plugixa-exam' ),
				// Registered learners by name, email or login, and guests by name or email — see
				// `AttemptsController::search_examinees()`.
				'searchExaminees'          => __( 'Name or email…', 'plugixa-exam' ),
				'statusPaused'             => __( 'Paused', 'plugixa-exam' ),
				'theirAnswer'              => __( 'Their answer', 'plugixa-exam' ),
				'view'                     => __( 'View', 'plugixa-exam' ),
				'visibleToExaminee'        => __( 'Visible to the examinee', 'plugixa-exam' ),
				'wouldNotPass'             => __( 'Would not pass', 'plugixa-exam' ),
				'wouldPass'                => __( 'Would pass', 'plugixa-exam' ),
			)
		);
	}
}
