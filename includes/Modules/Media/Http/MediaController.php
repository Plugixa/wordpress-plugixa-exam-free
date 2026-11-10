<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Media\Http;

use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Modules\Media\Services\Uploader;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uploading and finding exam images.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * GATED ON AUTHORING A QUESTION, NOT ON `upload_files`.
 *
 * The people who write exam questions are not necessarily the people a site trusts with its
 * media library — an Author role in this plugin may hold no WordPress upload capability at
 * all. Requiring `upload_files` would mean an exam author could write a question and not
 * illustrate it.
 *
 * That is safe only because the gate below is narrower than the site's: four image formats,
 * name and content agreeing, decodable, ten megabytes. Someone who can author a question can
 * add a picture to it and can do nothing else with the uploads folder.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * DELETION IS DELIBERATELY ABSENT. An image may be sitting in a published exam, in an
 * attempt's frozen paper, and in a version snapshot — none of which this module can see. The
 * media manager already removes attachments, with WordPress's own warnings; adding a second
 * delete button here would be a quieter way to break a live paper.
 */
final class MediaController extends RestController {

	/**
	 * @param Uploader $uploader The gate.
	 */
	public function __construct( private readonly Uploader $uploader ) {}

	protected function feature(): string {
		return 'questions';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/media',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_images' ),
					'permission_callback' => array( $this, 'may_author' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'upload' ),
					'permission_callback' => array( $this, 'may_author' ),
				),
			)
		);
	}

	/**
	 * Whoever may write or edit a question or an exam. The permission callback of both
	 * routes above.
	 *
	 * @return true|WP_Error
	 */
	public function may_author(): bool|WP_Error {
		foreach ( array( 'create', 'edit' ) as $action ) {
			if ( current_user_can( Capabilities::ACCESS, 'questions', $action ) || current_user_can( Capabilities::ACCESS, 'exams', $action ) ) {
				return true;
			}
		}

		return $this->forbidden();
	}

	/**
	 * The images this plugin has uploaded, newest first.
	 *
	 * SCOPED TO THIS PLUGIN'S OWN, by the meta key the uploader sets. An exam author looking
	 * for the diagram they added last week should not have to scroll past every photograph
	 * the marketing team has ever published — and the site's library is reachable through the
	 * media manager for anybody who actually holds `upload_files`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_images( WP_REST_Request $request ): WP_REST_Response {
		$per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ?: 40 ) );
		$page     = max( 1, absint( $request->get_param( 'page' ) ) ?: 1 );
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array_values( array_unique( Uploader::ALLOWED ) ),
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				's'              => $search,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the whole point is to scope to this plugin's uploads; the alternative is listing the site's entire media library to an exam author.
				'meta_query'     => array(
					array(
						'key'     => Uploader::OWNED,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$images = array_map(
			fn( int $id ): array => $this->uploader->present( $id ),
			wp_list_pluck( $query->posts, 'ID' )
		);

		return $this->ok(
			$images,
			array(
				'total' => (int) $query->found_posts,
				'pages' => (int) $query->max_num_pages,
			)
		);
	}

	/**
	 * Take an uploaded image.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$files = $request->get_file_params();

		if ( ! isset( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return $this->fail(
				'plugixa_exam_media_no_file',
				__( 'No image arrived.', 'plugixa-exam' ),
				400
			);
		}

		$result = $this->uploader->accept( $files['file'], (string) $request->get_param( 'alt' ) );

		return $result instanceof WP_Error ? $result : $this->ok( $result, array(), 201 );
	}
}
