<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Http;

use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Core\Security\GuestToken;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;
use PlugixaExam\Modules\Attempts\Services\AccessGate;
use PlugixaExam\Modules\Attempts\Services\AttemptService;
use PlugixaExam\Modules\Attempts\Services\Ownership;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The routes an examinee's browser talks to while sitting an exam.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE ONLY PUBLIC ROUTES IN THE PLUGIN, AND THE ONLY ONES THAT NEED CAREFUL READING.
 *
 * A guest with no WordPress account must be able to start, autosave and submit — so the
 * capability checks every other controller uses cannot apply here. What replaces them is
 * NOT `__return_true`. Every route that names an attempt authorises on WHOSE IT IS:
 *
 *   • a logged-in user is the candidate of an attempt whose `user_id` is theirs;
 *   • a guest is the candidate of one whose `guest_token` matches their signed cookie.
 *
 * For the PAPER — state, autosave, pause, resume, submit, and the premium routes beside
 * them — nothing else is accepted: not a marker, not an administrator. Passing somebody
 * else's attempt id gets a 403, not their paper. For the RESULT, staff who may view
 * attempts are let in as well, because that is a record and reading it changes nothing.
 * The rule is {@see Ownership}, which says why the two are kept apart.
 *
 * WHY THE GUEST TOKEN IS ENOUGH. It is a 32-character random value, signed with the site's
 * auth salt, in an HttpOnly SameSite=Lax cookie (see {@see GuestToken}). Holding it IS being
 * that guest — the same standing a session id would have, without the caching penalty or
 * the wp.org rejection that `session_start()` brings.
 *
 * THE ROUTES DO NOT *REQUIRE* A NONCE, BUT THE CLIENT ALWAYS SENDS ONE.
 *
 * Requiring one would make guest exams impossible: a logged-out visitor has no session for a
 * nonce to authenticate. So `permission_callback` never asks for it, and CSRF is bounded
 * instead by what these routes can do — they only ever write to an attempt the caller
 * already owns, and the worst a forged cross-site request achieves is saving an answer the
 * victim was going to save anyway. Nothing here escalates, reads another person's data, or
 * changes site state.
 *
 * The client sends `X-WP-Nonce` regardless, and that is NOT belt-and-braces — it is load
 * bearing for logged-in examinees. WordPress only authenticates a cookie-based REST request
 * when the nonce is present and valid; without it `determine_current_user()` returns 0, so a
 * signed-in examinee's autosave arrives looking anonymous and fails {@see is_candidate()}
 * against their own attempt. Every save then 401s from a browser that is plainly logged in.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class RunnerController extends RestController {

	/**
	 * @param AttemptService    $service   The lifecycle.
	 * @param AttemptRepository $attempts  The attempt a request names.
	 * @param AccessGate        $gate      Whether the candidate may still carry on.
	 * @param Ownership         $ownership Whose attempt it is, and who else may read it.
	 */
	public function __construct(
		private readonly AttemptService $service,
		private readonly AttemptRepository $attempts,
		private readonly AccessGate $gate,
		private readonly Ownership $ownership
	) {}

	protected function feature(): string {
		return 'attempts';
	}

	public function register_routes(): void {
		// An id in the URL is a positive whole number before any callback reads it.
		$id = array(
			'type'              => 'integer',
			'required'          => true,
			'minimum'           => 1,
			'sanitize_callback' => 'absint',
		);

		register_rest_route(
			$this->namespace,
			'/runner/gate/(?P<exam>[\d]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'gate' ),
					/*
					 * Public by design: this is the route a visitor uses to ASK whether
					 * they may sit an exam, so it cannot require that they already can.
					 * It answers with that exam's own access rules — the same ones its
					 * landing page prints — and with a refusal's reason. It never returns
					 * a question, an answer or anybody's attempt, and the only thing it
					 * can create is the caller's own attempt, on the terms `start` enforces.
					 */
					'permission_callback' => '__return_true',
					'args'                => array( 'exam' => $id ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/runner/start/(?P<exam>[\d]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'start' ),
					/*
					 * Public by design: a guest with no account must be able to begin an
					 * exam its owner opened to guests, so no capability can be asked for.
					 * The access gate inside `start()` IS the authorisation: it refuses
					 * unpublished exams, closed windows, exhausted attempt caps, cooldowns,
					 * missing access codes and unidentified guests, and what it creates
					 * belongs to the caller alone. Every route after this one names an
					 * attempt and asks whose it is.
					 */
					'permission_callback' => '__return_true',
					'args'                => array( 'exam' => $id ),
				),
			)
		);

		foreach ( array( 'state', 'autosave', 'pause', 'resume', 'submit', 'result' ) as $action ) {
			register_rest_route(
				$this->namespace,
				'/runner/' . $action . '/(?P<id>[\d]+)',
				array(
					array(
						'methods'             => 'state' === $action || 'result' === $action ? 'GET' : 'POST',
						'callback'            => array( $this, $action ),
						/*
						 * The PAPER is the candidate's alone, and only while they may still
						 * sit it — `may_continue()`. The RESULT is a record: it stays
						 * readable after access is lost, and staff who may view attempts
						 * may read it too — `may_read_result()`.
						 */
						'permission_callback' => array( $this, 'result' === $action ? 'may_read_result' : 'may_continue' ),
						'args'                => array( 'id' => $id ),
					),
				)
			);
		}
	}

	/**
	 * Whether the caller is the person sitting the attempt they name.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function is_candidate( WP_REST_Request $request ): bool|WP_Error {
		$attempt = $this->attempts->find( (int) $request['id'] );

		if ( null === $attempt ) {
			return $this->not_found();
		}

		return $this->ownership->is_candidate( $attempt ) ? true : $this->forbidden();
	}

	/**
	 * Whether the caller may read the result of the attempt they name.
	 *
	 * The candidate, or staff who may view attempts. For routes that READ a finished
	 * record and change nothing — never for one that touches the paper.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function may_read_result( WP_REST_Request $request ): bool|WP_Error {
		$attempt = $this->attempts->find( (int) $request['id'] );

		if ( null === $attempt ) {
			return $this->not_found();
		}

		return $this->ownership->may_read( $attempt ) ? true : $this->forbidden();
	}

	/**
	 * The candidate, and the candidate has not since lost the exam.
	 *
	 * For every route that reads or changes the PAPER — state, autosave, pause, resume,
	 * submit, and the premium routes beside them. Without the second half a learner removed
	 * from an exam was refused by the landing page and `take/` but could go on loading,
	 * saving and submitting here. See {@see AccessGate::revoked()} for exactly who is
	 * stopped and why the attempt is left open.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function may_continue( WP_REST_Request $request ): bool|WP_Error {
		$owns = $this->is_candidate( $request );

		if ( true !== $owns ) {
			return $owns;
		}

		$refusal = $this->gate->revoked( (array) $this->attempts->find( (int) $request['id'] ) );

		if ( null === $refusal ) {
			return true;
		}

		return new WP_Error(
			'plugixa_exam_denied',
			(string) $refusal['message'],
			array(
				'status'  => 403,
				'verdict' => $refusal,
			)
		);
	}

	/**
	 * Ask whether an exam can be started, and what is missing.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function gate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->start( (int) $request['exam'], $this->credentials( $request ) );

		if ( $result instanceof WP_Error ) {
			$data = $result->get_error_data();

			// A refusal is a legitimate answer to this question, not a failure. It comes
			// back as 200 with the verdict, so the landing page can render "you have used
			// all 3 attempts" or an access-code form rather than an error.
			return $this->ok(
				array(
					'allowed' => false,
					'verdict' => $data['verdict'] ?? array(
						'reason'  => $result->get_error_code(),
						'message' => $result->get_error_message(),
					),
				)
			);
		}

		return $this->ok( array( 'allowed' => true ) + $result );
	}

	/**
	 * Start or resume an attempt.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->start( (int) $request['exam'], $this->credentials( $request ) );

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * The current state of an attempt.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function state( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->hydrate( (int) $request['id'] );

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Save answers mid-attempt.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function autosave( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->autosave( (int) $request['id'], (array) $request->get_json_params() );

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Pause an attempt.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function pause( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->pause( (int) $request['id'] );

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Resume a paused attempt.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function resume( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->resume( (int) $request['id'] );

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Submit an attempt.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function submit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->submit( (int) $request['id'], (array) $request->get_json_params() );

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * An attempt's result.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function result( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->service->result( (int) $request['id'] );

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * The access-gate credentials a request may carry.
	 *
	 * Whitelisted rather than passed through wholesale: everything else in the body belongs
	 * to the attempt, and the gate has no business reading it.
	 *
	 * Cleaned HERE, where they are read, with the functions the gate and the service use
	 * again further in — the same three the no-JavaScript form applies to the same three
	 * fields, so both doors hand on the same thing. A value that is not a string (a body
	 * can carry an array where a name was expected) becomes an empty one.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, string>
	 */
	private function credentials( WP_REST_Request $request ): array {
		$body = (array) $request->get_json_params();

		$text = static fn( string $key ): string => is_scalar( $body[ $key ] ?? null ) ? (string) $body[ $key ] : '';

		return array(
			'access_code' => sanitize_text_field( $text( 'access_code' ) ),
			'guest_name'  => sanitize_text_field( $text( 'guest_name' ) ),
			'guest_email' => sanitize_email( $text( 'guest_email' ) ),
		);
	}
}
