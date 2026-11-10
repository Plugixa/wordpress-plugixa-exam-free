<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Subjects\Http;

use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Modules\Subjects\Repositories\SubjectRepository;
use PlugixaExam\Modules\Subjects\Services\SubjectService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subjects REST routes.
 */
final class SubjectsController extends RestController {

	/**
	 * @param SubjectRepository $subjects The repository.
	 * @param SubjectService    $service  The service.
	 */
	public function __construct(
		private readonly SubjectRepository $subjects,
		private readonly SubjectService $service
	) {}

	protected function feature(): string {
		return 'subjects';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/subjects',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'subjects', 'view' ) ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'subjects', 'create' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subjects/tree',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_tree' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'subjects', 'view' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subjects/reorder',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'reorder' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'subjects', 'edit' ) ),
					'args'                => array(
						'ids' => array(
							'type'     => 'array',
							'required' => true,
							'items'    => array( 'type' => 'integer' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/subjects/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'subjects', 'view' ) ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'subjects', 'edit' ) ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'subjects', 'delete' ) ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		return $this->paged_list( $request, $this->subjects, array( 't.title', 't.code' ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_tree( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok( $this->service->tree() );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_item( $request ): WP_REST_Response|WP_Error {
		$subject = $this->subjects->find( (int) $request['id'] );

		return null === $subject ? $this->not_found() : $this->ok( $subject );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$id = $this->service->create( (array) $request->get_json_params() );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		return $this->ok( $this->subjects->find( $id ), array(), 201 );
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

		return $this->ok( $this->subjects->find( $id ) );
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
	public function reorder( WP_REST_Request $request ): WP_REST_Response {
		$this->service->reorder( array_map( 'absint', (array) $request->get_param( 'ids' ) ) );

		return $this->ok( array( 'reordered' => true ) );
	}
}
