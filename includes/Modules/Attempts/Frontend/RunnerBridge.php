<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Frontend;

use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;
use PlugixaExam\Modules\Attempts\Services\AccessGate;
use PlugixaExam\Modules\Attempts\Services\AttemptService;
use PlugixaExam\Modules\Attempts\Services\Ownership;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers Core's front-end questions about attempts.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * EVERY METHOD HERE IS REACHABLE BY AN ANONYMOUS VISITOR, so every one of them asks whose
 * attempt it is before it returns or writes anything.
 *
 * `admin-post.php` is a public endpoint and the attempt id is whatever the form said it was.
 * The nonce in `Core\Frontend\FormHandler` proves the request came from a page this site
 * rendered; it proves nothing about WHOSE attempt is being written to. {@see Ownership} is
 * what does that, and it is the same object the REST runner asks: the paper and the form
 * that saves it are the candidate's alone, the result may also be read by staff who may
 * view attempts.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class RunnerBridge {

	/**
	 * @param AttemptService    $service   The lifecycle.
	 * @param AttemptRepository $attempts  Attempts.
	 * @param AccessGate        $gate      The access gate.
	 * @param Ownership         $ownership Whose attempt it is, and who else may read it.
	 */
	public function __construct(
		private readonly AttemptService $service,
		private readonly AttemptRepository $attempts,
		private readonly AccessGate $gate,
		private readonly Ownership $ownership
	) {}

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_frontend_gate', array( $this, 'evaluate_gate' ), 10, 2 );
		add_filter( 'plugixa_exam_frontend_attempt', array( $this, 'attempt' ), 10, 2 );
		add_filter( 'plugixa_exam_frontend_result', array( $this, 'result' ), 10, 2 );
		add_filter( 'plugixa_exam_frontend_start', array( $this, 'start' ), 10, 3 );
		add_filter( 'plugixa_exam_frontend_save', array( $this, 'save' ), 10, 4 );
	}

	/**
	 * @param array<string, mixed>|null $verdict Current value.
	 * @param array<string, mixed>      $exam    The exam row.
	 *
	 * @return array<string, mixed>
	 */
	public function evaluate_gate( $verdict, array $exam ): array {
		unset( $verdict );

		return $this->gate->evaluate( $exam );
	}

	/**
	 * An attempt, hydrated for the runner, if the visitor is the one sitting it.
	 *
	 * @param array<string, mixed>|null $attempt Current value.
	 * @param int                       $id      Attempt id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function attempt( $attempt, int $id ): ?array {
		unset( $attempt );

		$row = $this->attempts->find( $id );

		// The paper: the candidate's alone.
		if ( null === $row || ! $this->ownership->is_candidate( $row ) ) {
			return null;
		}

		$hydrated = $this->service->hydrate( $id );

		return $hydrated instanceof WP_Error ? null : $hydrated;
	}

	/**
	 * An attempt's result, if the visitor may read it.
	 *
	 * The candidate, or staff who may view attempts: alerts and marking emails link a
	 * marker to this page. The release policy is applied inside `AttemptService::result()`,
	 * which withholds an unreleased score from the payload rather than leaving it there for
	 * the template to remember to hide.
	 *
	 * @param array<string, mixed>|null $result Current value.
	 * @param int                       $id     Attempt id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function result( $result, int $id ): ?array {
		unset( $result );

		$row = $this->attempts->find( $id );

		// The record: read-only, so staff who may view attempts are let in.
		if ( null === $row || ! $this->ownership->may_read( $row ) ) {
			return null;
		}

		$payload = $this->service->result( $id );

		return $payload instanceof WP_Error ? null : $payload;
	}

	/**
	 * Start an attempt from a posted gate form.
	 *
	 * No ownership check: there is no attempt yet. The access gate inside `start()` IS the
	 * authorisation, and it refuses closed windows, exhausted caps, cooldowns, missing
	 * access codes and unidentified guests.
	 *
	 * @param array<string, mixed>|WP_Error|null $result  Current value.
	 * @param int                                $exam_id Exam id.
	 * @param array<string, string>              $input   Gate credentials.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function start( $result, int $exam_id, array $input ): array|WP_Error {
		unset( $result );

		return $this->service->start( $exam_id, $input );
	}

	/**
	 * Save or submit answers from a posted form.
	 *
	 * @param array<string, mixed>|WP_Error|null $result     Current value.
	 * @param int                                $attempt_id Attempt id.
	 * @param array<int, mixed>                  $answers    Answers the form handler has already cleaned; the service cleans them again.
	 * @param bool                               $submitting Whether to finalise.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function save( $result, int $attempt_id, array $answers, bool $submitting ): array|WP_Error {
		unset( $result );

		$attempt = $this->attempts->find( $attempt_id );

		/*
		 * THE CANDIDATE, AND NOBODY ELSE. This used to let in anybody who may view
		 * attempts, which is a permission to look: a marker could post this form with a
		 * learner's attempt id and save, or hand in, their paper. Nothing renders such a
		 * form for them; the request is all it took.
		 */
		if ( null === $attempt || ! $this->ownership->is_candidate( $attempt ) ) {
			return new WP_Error(
				'plugixa_exam_forbidden',
				__( 'You are not allowed to do that.', 'plugixa-exam' ),
				array( 'status' => 403 )
			);
		}

		// The same stop the REST runner makes: a candidate who has since lost the exam can
		// neither save nor submit from the no-JS form. See `AccessGate::revoked()`.
		$refusal = $this->gate->revoked( $attempt );

		if ( null !== $refusal ) {
			return new WP_Error(
				'plugixa_exam_denied',
				(string) $refusal['message'],
				array(
					'status'  => 403,
					'exam_id' => (int) ( $attempt['exam_id'] ?? 0 ),
				)
			);
		}

		if ( $submitting ) {
			return $this->service->submit( $attempt_id, array( 'answers' => $answers ) );
		}

		return $this->service->autosave( $attempt_id, array( 'answers' => $answers ) );
	}
}
