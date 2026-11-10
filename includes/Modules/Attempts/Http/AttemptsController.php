<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Http;

use PlugixaExam\Core\Database\QueryBuilder;
use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;
use PlugixaExam\Modules\Attempts\Services\AttemptService;
use PlugixaExam\Modules\Attempts\Services\Summary;
use PlugixaExam\Modules\Exams\Repositories\ExamRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The administrator's view of attempts.
 *
 * Distinct from {@see RunnerController}, which is the examinee's. These routes take ordinary
 * capability checks; those take ownership checks. Keeping them in separate classes is what
 * stops a public route and an administrative one from ever sharing a permission callback by
 * accident.
 */
final class AttemptsController extends RestController {

	/**
	 * @param AttemptRepository $attempts Attempts.
	 * @param ExamRepository    $exams    Exams, for titles.
	 * @param AttemptService    $service  For the detail view.
	 * @param Summary           $summary  Site-wide totals, for the dashboard.
	 */
	public function __construct(
		private readonly AttemptRepository $attempts,
		private readonly ExamRepository $exams,
		private readonly AttemptService $service,
		private readonly Summary $summary
	) {}

	protected function feature(): string {
		return 'attempts';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/attempts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'attempts', 'view' ) ),
					'args'                => array_merge(
						$this->get_collection_params(),
						array(
							'exam_id' => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
							'status'  => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
						)
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/attempts/summary',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_summary' ),
					/*
					 * REGISTERED BEFORE `/attempts/(?P<id>…)`, and the order is load-bearing:
					 * WordPress matches routes in registration order, and `summary` is not
					 * digits so it would fall through anyway — but relying on that means the
					 * day somebody loosens the id pattern to `[\w]+`, this route silently
					 * becomes a request for attempt "summary". Registering the literal first
					 * costs nothing and removes the question.
					 */
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'attempts', 'view' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/attempts/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'attempts', 'view' ) ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'attempts', 'delete' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/attempts/(?P<id>[\d]+)/grade',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'grade_item' ),
					// `grade`, not `edit`. Marking is its own permission in the matrix so a
					// Grader can mark papers without also being able to delete them.
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'attempts', 'grade' ) ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		/**
		 * Fires when an administrator looks at the attempts list.
		 *
		 * The opportunistic trigger for the auto-close sweep: the person most likely to be
		 * confused by an abandoned attempt still showing as "in progress" is the one looking
		 * at this screen. Throttled to once a minute site-wide.
		 */
		do_action( 'plugixa_exam_attempts_listed' );

		$exam_titles = array();

		foreach ( $this->exams->query()->select( 't.id', 't.title' )->paginate( 500 )->get() as $row ) {
			$exam_titles[ (int) $row['id'] ] = (string) $row['title'];
		}

		return $this->paged_list(
			$request,
			$this->attempts,
			// No search columns: a search here is answered by `search_examinees()` below, which
			// has to reach the users table as well as the attempt's own guest columns.
			array(),
			function ( QueryBuilder $query, WP_REST_Request $req ): void {
				$exam_id = absint( $req->get_param( 'exam_id' ) ?? 0 );

				if ( 0 !== $exam_id ) {
					$query->where( 't.exam_id = %d', array( $exam_id ) );
				}

				$status = sanitize_key( (string) ( $req->get_param( 'status' ) ?? '' ) );

				if ( 'needs_grading' === $status ) {
					/*
					 * A DERIVED STATE, and its definition lives on the repository rather
					 * than here — see `AttemptRepository::AWAITING_MARK`. It used to read
					 * `status = 'submitted' AND is_graded = 0` inline, which excluded
					 * `locked_review` and therefore excluded almost everything: that is the
					 * status the DEFAULT lock policy gives an attempt that still needs a
					 * marker.
					 */
					$this->attempts->only_awaiting_mark( $query );
				} elseif ( '' !== $status ) {
					$query->where( 't.status = %s', array( $status ) );
				}

				// LAST, after the filters: its OR group is then the final clause, where only its
				// own parentheses keep it from swallowing the conditions before it — so
				// `AttemptsSearchTest` can see them go missing.
				$term = sanitize_text_field( (string) ( $req->get_param( 'search' ) ?? '' ) );

				if ( '' !== $term ) {
					$this->search_examinees( $query, $term );
				}
			},
			function ( array $row ) use ( $exam_titles ): array {
				$row['exam_title'] = $exam_titles[ (int) $row['exam_id' ] ] ?? '';
				$row['examinee']   = $this->examinee_name( $row );

				// The paper and the guest token are not list data. The token is a bearer
				// credential and must never leave the server.
				unset( $row['question_set'], $row['guest_token'], $row['integrity_signals'] );

				return $row;
			}
		);
	}

	/**
	 * Site-wide totals for the dashboard.
	 *
	 * NAMED FOR WHAT IT RETURNS, not `get_items`-style. `WP_REST_Controller` already
	 * declares `get_items`, `get_item`, `create_item`, `update_item` and `delete_item` with
	 * UNTYPED parameters, and a method of the same name with a typed signature is a fatal at
	 * class-load time that `php -l` cannot see.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_summary( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok( $this->summary->overview() );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		// `paper()`, not `result()` — this route is reached with `attempts.view`, so the
		// caller is staff. See the note on `AttemptService::paper()` for why the examinee's
		// release gating would make the grading queue unusable.
		$result = $this->service->paper( (int) $request['id'] );

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->attempts->find( $id ) ) {
			return $this->not_found();
		}

		// Attempts do not soft-delete, so this is final — and the responses go with it.
		// See Attempts\Schema for why a trashed attempt would be worse than no attempt.
		$this->attempts->purge( $id );

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Record a marker's scores.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function grade_item( $request ): WP_REST_Response|WP_Error {
		$result = $this->service->grade( (int) $request['id'], (array) $request->get_json_params() );

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * Narrow to attempts sat by somebody whose name or email contains the term.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * A REGISTERED LEARNER WAS UNFINDABLE BY NAME, AND MOST LEARNERS ARE REGISTERED.
	 *
	 * The search used to look in `guest_name` and `guest_email` only — the columns an
	 * attempt carries itself. A logged-in examinee's name lives in the users table, so a
	 * marker typing "Karim" found Karim's paper only if Karim had sat it as a guest, and the
	 * box under the search said "Guest name or email" as though that were a choice rather
	 * than a gap. The header's search palette would have inherited the same blind spot.
	 *
	 * Users are resolved through `get_users()` — display name, email, login — rather than a
	 * join, so the statement stays one literal and a user search follows WordPress's own
	 * rules. They join the guest columns in ONE parenthesised OR group, AND-ed onto every
	 * other filter, exactly as `QueryBuilder::search()` shapes a plain search. `blog_id` 0
	 * because an attempt keeps its `user_id` whether or not that user is still a member of
	 * this site. Capped at 200 matches: a term that broad is not a search for somebody.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param QueryBuilder $query The attempts query.
	 * @param string       $term  The sanitised search term.
	 */
	private function search_examinees( QueryBuilder $query, string $term ): void {
		global $wpdb;

		$users = get_users(
			array(
				'blog_id'        => 0,
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'display_name', 'user_email', 'user_login' ),
				'fields'         => 'ID',
				'number'         => 200,
			)
		);

		$ids  = implode( ',', array_map( 'absint', $users ) );
		$like = '%' . $wpdb->esc_like( $term ) . '%';

		$query->where(
			'( t.guest_name LIKE %s OR t.guest_email LIKE %s OR FIND_IN_SET( t.user_id, %s ) )',
			array( $like, $like, '' === $ids ? '0' : $ids )
		);
	}

	/**
	 * A display name for whoever sat the attempt.
	 *
	 * @param array<string, mixed> $row The attempt row.
	 */
	private function examinee_name( array $row ): string {
		if ( ! empty( $row['user_id'] ) ) {
			$user = get_userdata( (int) $row['user_id'] );

			if ( false !== $user ) {
				return $user->display_name;
			}
		}

		if ( ! empty( $row['guest_name'] ) ) {
			return (string) $row['guest_name'];
		}

		return __( 'Guest', 'plugixa-exam' );
	}
}
