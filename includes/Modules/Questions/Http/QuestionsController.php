<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Questions\Http;

use PlugixaExam\Core\Database\QueryBuilder;
use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Modules\Questions\Repositories\QuestionRepository;
use PlugixaExam\Modules\Questions\Services\QuestionService;
use PlugixaExam\Modules\Questions\Services\QuestionTypes;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Question bank REST routes.
 */
final class QuestionsController extends RestController {

	/**
	 * @param QuestionRepository $questions The repository.
	 * @param QuestionService    $service   The service.
	 * @param QuestionTypes      $types     The type registry.
	 */
	public function __construct(
		private readonly QuestionRepository $questions,
		private readonly QuestionService $service,
		private readonly QuestionTypes $types
	) {}

	protected function feature(): string {
		return 'questions';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/questions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'view' ) ),
					'args'                => array_merge(
						$this->get_collection_params(),
						array(
							'subject_id'    => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
							'question_type' => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ),
							'difficulty'    => array( 'type' => 'integer', 'sanitize_callback' => 'absint' ),
							'published'     => array( 'type' => 'string', 'enum' => array( 'any', '1', '0' ) ),

							/*
							 * A comma-separated id list, for resolving questions somebody
							 * already chose. The exam builder stores only ids in a fixed
							 * section, so reopening an exam needs the titles back; without
							 * this it would either show bare numbers or fetch each question
							 * one at a time.
							 */
							'include'       => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
						)
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'create' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/questions/types',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_types' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'view' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/questions/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'view' ) ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'may_edit_row' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'delete' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/questions/(?P<id>[\d]+)/duplicate',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'duplicate_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'create' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/questions/(?P<id>[\d]+)/versions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_versions' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'view' ) ),
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
				$row = $this->questions->find( $id, true );

				return null === $row ? null : (int) ( $row['created_by'] ?? 0 );
			}
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$options_by_question = array();

		$response = $this->paged_list(
			$request,
			$this->questions,
			array( 't.title', 't.stem' ),
			static function ( QueryBuilder $query, WP_REST_Request $req ): void {
				$subject_id = absint( $req->get_param( 'subject_id' ) ?? 0 );

				if ( 0 !== $subject_id ) {
					/**
					 * Narrow a question list to a subject.
					 *
					 * A filter rather than a direct subtree lookup, because this module
					 * must not name the Subjects module. Subjects answers it with the
					 * subtree, so filtering by "Mathematics" includes Algebra — which is
					 * what anybody picking a subject in a filter expects.
					 *
					 * @param int[] $ids        Subject ids to match.
					 * @param int   $subject_id The chosen subject.
					 */
					$ids = (array) apply_filters( 'plugixa_exam_subject_subtree_ids', array( $subject_id ), $subject_id );

					$query->where_in( 't.subject_id', array_map( 'intval', $ids ) );
				}

				$type = sanitize_key( (string) ( $req->get_param( 'question_type' ) ?? '' ) );

				if ( '' !== $type ) {
					$query->where( 't.question_type = %s', array( $type ) );
				}

				$difficulty = absint( $req->get_param( 'difficulty' ) ?? 0 );

				if ( 0 !== $difficulty ) {
					$query->where( 't.difficulty = %d', array( $difficulty ) );
				}

				$published = (string) ( $req->get_param( 'published' ) ?? 'any' );

				if ( '1' === $published || '0' === $published ) {
					$query->where( 't.published = %d', array( (int) $published ) );
				}

				$include = array_values(
					array_filter(
						array_map( 'absint', explode( ',', (string) ( $req->get_param( 'include' ) ?? '' ) ) )
					)
				);

				if ( array() !== $include ) {
					/*
					 * Capped, because this arrives from the browser and `where_in` builds one
					 * placeholder per id. An exam with more questions than this pages through
					 * the list instead — a limit the builder respects when it asks.
					 */
					$query->where_in( 't.id', array_slice( $include, 0, 200 ) );
				}
			},
			function ( array $row ) use ( &$options_by_question ): array {
				$row['option_count'] = count( $options_by_question[ (int) $row['id'] ] ?? array() );

				// The list does not need full option text, and a bank of a thousand
				// questions would ship a great deal of HTML nobody renders.
				unset( $row['stem'], $row['explanation'] );

				return $row;
			}
		);

		if ( $response instanceof WP_Error ) {
			return $response;
		}

		/*
		 * The decorator above ran before this, so the counts were all zero. Rather than
		 * threading a pre-fetch through `paged_list`, the rows are patched here — one query
		 * for the whole page instead of one per row.
		 */
		$data = $response->get_data();
		$rows = (array) ( $data['data'] ?? array() );
		$ids  = array_map( static fn( array $r ): int => (int) $r['id'], $rows );

		$options_by_question = $this->questions->options_for( $ids );

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['option_count'] = count( $options_by_question[ (int) $row['id'] ] ?? array() );
		}

		/** This filter is documented in includes/Modules/Questions/Services/QuestionService.php */
		$rows = (array) apply_filters( 'plugixa_exam_question_list_output', $rows );

		$data['data'] = $rows;
		$response->set_data( $data );

		return $response;
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_types( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$types = array();

		foreach ( $this->types->all() as $key => $type ) {
			$types[] = array(
				'id'          => $key,
				'label'       => $type['label'],
				'description' => $type['description'] ?? '',
				'gradedBy'    => $type['graded_by'] ?? 'manual',
				// How the authoring screen should edit this type's option list. Sent so the
				// screen never has to name a type — see the client's `OptionsEditor`.
				'option_editor' => $type['option_editor'] ?? 'correct_single',
				'options'     => $type['options'] ?? 'none',
			);
		}

		return $this->ok( $types );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$question = $this->service->find( (int) $request['id'] );

		return null === $question ? $this->not_found() : $this->ok( $question );
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
		$result = $this->service->delete( (int) $request['id'] );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function duplicate_item( $request ): WP_REST_Response|WP_Error {
		$id = $this->service->duplicate( (int) $request['id'] );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		return $this->ok( $this->service->find( $id ), array(), 201 );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_versions( $request ): WP_REST_Response {
		return $this->ok( $this->questions->versions( (int) $request['id'] ) );
	}
}
