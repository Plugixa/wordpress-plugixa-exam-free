<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Tags\Http;

use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Modules\Tags\Repositories\TagRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags REST routes.
 *
 * Authorised against the `questions` feature rather than a feature of its own. A tag exists
 * only to label a question, so anybody who may edit questions may manage the labels — and
 * NextExams' permission matrix, which this port follows, has no `tags` feature either.
 */
final class TagsController extends RestController {

	/**
	 * @param TagRepository $tags The repository.
	 */
	public function __construct( private readonly TagRepository $tags ) {}

	protected function feature(): string {
		return 'questions';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/tags',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'view' ) ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'create' ) ),
					'args'                => array(
						'title' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/tags/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'edit' ) ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'delete' ) ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_items( $request ): WP_REST_Response|WP_Error {
		$counts = $this->tags->usage_counts();

		return $this->paged_list(
			$request,
			$this->tags,
			array( 't.title' ),
			null,
			static function ( array $row ) use ( $counts ): array {
				$row['question_count'] = $counts[ (int) $row['id'] ] ?? 0;

				return $row;
			}
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$title = trim( (string) $request->get_param( 'title' ) );

		if ( '' === $title ) {
			return $this->fail( 'plugixa_exam_title_required', __( 'A tag needs a title.', 'plugixa-exam' ) );
		}

		$slug = $this->unique_slug( sanitize_title( $title ) );

		$id = $this->tags->insert(
			array(
				'title'     => $title,
				'slug'      => $slug,
				'published' => 1,
				'ordering'  => 0,
			)
		);

		if ( 0 === $id ) {
			return $this->fail( 'plugixa_exam_insert_failed', __( 'The tag could not be saved.', 'plugixa-exam' ), 500 );
		}

		return $this->ok( $this->tags->find( $id ), array(), 201 );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->tags->find( $id ) ) {
			return $this->not_found();
		}

		$body = (array) $request->get_json_params();
		$data = array();

		if ( isset( $body['title'] ) ) {
			$title = sanitize_text_field( (string) $body['title'] );

			if ( '' === trim( $title ) ) {
				return $this->fail( 'plugixa_exam_title_required', __( 'A tag needs a title.', 'plugixa-exam' ) );
			}

			$data['title'] = $title;
			$data['slug']  = $this->unique_slug( sanitize_title( $title ), $id );
		}

		if ( isset( $body['published'] ) ) {
			$data['published'] = empty( $body['published'] ) ? 0 : 1;
		}

		if ( isset( $body['ordering'] ) ) {
			$data['ordering'] = absint( $body['ordering'] );
		}

		$this->tags->update( $id, $data );

		return $this->ok( $this->tags->find( $id ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->tags->find( $id ) ) {
			return $this->not_found();
		}

		// Detach before trashing. A join row pointing at a tag that is gone makes a pool
		// rule filtering on it silently match nothing — a wrong exam rather than an error.
		$this->tags->detach_tag( $id );
		$this->tags->delete( $id );

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * A slug nothing else is using.
	 *
	 * @param string $slug    Base slug.
	 * @param int    $exclude Row being edited.
	 */
	private function unique_slug( string $slug, int $exclude = 0 ): string {
		$slug      = '' === $slug ? 'tag' : $slug;
		$candidate = $slug;
		$suffix    = 2;

		while ( $this->tags->slug_taken( $candidate, $exclude ) ) {
			$candidate = $slug . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}
}
