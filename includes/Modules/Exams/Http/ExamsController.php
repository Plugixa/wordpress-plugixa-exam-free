<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Exams\Http;

use PlugixaExam\Core\Database\QueryBuilder;
use PlugixaExam\Core\Frontend\Router;
use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Modules\Exams\Repositories\ExamRepository;
use PlugixaExam\Modules\Exams\Services\ExamService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exam authoring routes.
 */
final class ExamsController extends RestController {

	/**
	 * @param ExamRepository $exams   The repository.
	 * @param ExamService    $service The service.
	 * @param Router         $router  Where each exam is on the front end.
	 */
	public function __construct(
		private readonly ExamRepository $exams,
		private readonly ExamService $service,
		private readonly Router $router
	) {}

	protected function feature(): string {
		return 'exams';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/exams',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'exams', 'view' ) ),
					'args'                => array_merge(
						$this->get_collection_params(),
						array(
							/*
							 * TRI-STATE, WHICH IS WHY IT IS NOT A BOOLEAN. Absent means
							 * "every exam"; `1` and `0` each mean one half. Declaring it
							 * `boolean` would make an absent parameter arrive as `false`
							 * and turn the default listing into a drafts folder.
							 */
							'published' => array(
								'type' => 'integer',
								'enum' => array( 0, 1 ),
							),
						)
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'exams', 'create' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/exams/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'exams', 'view' ) ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'may_edit_row' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'exams', 'delete' ) ),
				),
			)
		);
	}

	/**
	 * Who may change one existing row.
	 *
	 * The route's permission callback. {@see edit_or_own()} asks WordPress for `edit`, then
	 * for `edit.own` on a row this caller created. `find()` is asked WITH trashed rows
	 * included: a soft-deleted row still has an author, and refusing to resolve one would
	 * make "restore my own deleted item" impossible to express.
	 *
	 * @param WP_REST_Request $request The request; its `id` names the row.
	 *
	 * @return true|\WP_Error
	 */
	public function may_edit_row( WP_REST_Request $request ): bool|\WP_Error {
		return $this->edit_or_own(
			$request,
			function ( int $id ): ?int {
				$row = $this->exams->find( $id, true );

				return null === $row ? null : (int) ( $row['created_by'] ?? 0 );
			}
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		return $this->paged_list(
			$request,
			$this->exams,
			array( 't.title' ),
			static function ( QueryBuilder $query, WP_REST_Request $req ): void {
				$published = $req->get_param( 'published' );

				// `null` is "did not ask", and must not be coerced into a zero — see the
				// argument declaration above.
				if ( null !== $published ) {
					$query->where( 't.published = %d', array( (int) $published ) );
				}
			},
			/*
			 * WHERE EACH EXAM IS, ASKED OF THE ROUTER ONE ROW AT A TIME. The list used to glue
			 * `<catalogue URL><slug>/` together in JavaScript, which is only an exam's address
			 * on pretty permalinks with no page mapped: once a site maps its exam view to a page
			 * of its own, the exam lives at `…/that-page/?pxe_exam=<slug>`, and "View on site"
			 * opened a 404 (`/learning-exams/<slug>/`, caught on the dev site). Empty for a
			 * draft, which has no public page to open.
			 */
			fn ( array $row ): array => $row + array(
				'view_url' => empty( $row['published'] ) ? '' : $this->router->exam_url( (string) $row['slug'] ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$exam = $this->service->find( (int) $request['id'] );

		return null === $exam ? $this->not_found() : $this->ok( $exam );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$id = $this->service->create( (array) $request->get_json_params() );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		return $this->ok( $this->service->find( $id ), array(), 201 );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$id     = (int) $request['id'];
		$result = $this->service->update( $id, (array) $request->get_json_params() );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $this->ok( $this->service->find( $id ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->exams->find( $id ) ) {
			return $this->not_found();
		}

		// Trashed, not purged. Attempts point at this exam and a result page must still be
		// able to name the exam somebody sat.
		$this->exams->delete( $id );

		return $this->ok( array( 'deleted' => true ) );
	}
}
