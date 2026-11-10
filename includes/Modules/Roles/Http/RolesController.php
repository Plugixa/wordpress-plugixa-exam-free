<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Roles\Http;

use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Core\Security\Permissions;
use PlugixaExam\Modules\Roles\Repositories\RoleRepository;
use PlugixaExam\Modules\Roles\Services\PermissionResolver;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Roles and assignments REST routes.
 *
 * Every route here takes `may_configure()` — `manage_options`, not a matrix check. A role
 * that could edit roles could grant itself anything, so the matrix cannot be allowed to
 * govern its own editor.
 */
final class RolesController extends RestController {

	/**
	 * @param RoleRepository     $roles    The repository.
	 * @param PermissionResolver $resolver The resolver, for cache invalidation.
	 */
	public function __construct(
		private readonly RoleRepository $roles,
		private readonly PermissionResolver $resolver
	) {}

	protected function feature(): string {
		return 'configuration';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/roles',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'may_configure' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'may_configure' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/roles/features',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_features' ),
					'permission_callback' => array( $this, 'may_configure' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/roles/assignments',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_assignments' ),
					'permission_callback' => array( $this, 'may_configure' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'assign' ),
					'permission_callback' => array( $this, 'may_configure' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/roles/assignments/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'unassign' ),
					'permission_callback' => array( $this, 'may_configure' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/roles/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'may_configure' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'may_configure' ),
				),
			)
		);
	}

	/**
	 * The parameter is deliberately untyped.
	 *
	 * `WP_REST_Controller::get_items( $request )` declares no type, and PHP forbids a child
	 * from NARROWING a parameter — so `WP_REST_Request $request` here is a fatal error at
	 * class-declaration time, not a warning. It is also invisible to `php -l`, which checks
	 * syntax and not inheritance, which is why `SmokeTest` dispatches every registered route
	 * instead of trusting the linter.
	 *
	 * The same applies to `get_item`, `create_item`, `update_item` and `delete_item`.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_items( $request ): WP_REST_Response {
		unset( $request );

		return $this->ok( $this->roles->all() );
	}

	/**
	 * The permission vocabulary, so the Roles screen can render a complete grid.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_features( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok( Permissions::FEATURES );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function create_item( $request ): WP_REST_Response|WP_Error {
		$body  = (array) $request->get_json_params();
		$title = sanitize_text_field( (string) ( $body['title'] ?? '' ) );

		if ( '' === trim( $title ) ) {
			return $this->fail( 'plugixa_exam_title_required', __( 'A role needs a name.', 'plugixa-exam' ) );
		}

		$id = $this->roles->insert(
			array(
				'title'       => $title,
				'description' => sanitize_textarea_field( (string) ( $body['description'] ?? '' ) ),
				'permissions' => (string) wp_json_encode( $this->clean_permissions( (array) ( $body['permissions'] ?? array() ) ) ),
				'is_builtin'  => 0,
				'published'   => empty( $body['published'] ) ? 0 : 1,
			)
		);

		if ( 0 === $id ) {
			return $this->fail( 'plugixa_exam_insert_failed', __( 'The role could not be saved.', 'plugixa-exam' ), 500 );
		}

		$this->resolver->invalidate();

		return $this->ok( $this->roles->find( $id ), array(), 201 );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function update_item( $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->roles->find( $id ) ) {
			return $this->not_found();
		}

		$body = (array) $request->get_json_params();
		$data = array();

		if ( isset( $body['title'] ) ) {
			$title = sanitize_text_field( (string) $body['title'] );

			if ( '' === trim( $title ) ) {
				return $this->fail( 'plugixa_exam_title_required', __( 'A role needs a name.', 'plugixa-exam' ) );
			}

			$data['title'] = $title;
		}

		if ( isset( $body['description'] ) ) {
			$data['description'] = sanitize_textarea_field( (string) $body['description'] );
		}

		if ( isset( $body['permissions'] ) ) {
			$data['permissions'] = (string) wp_json_encode( $this->clean_permissions( (array) $body['permissions'] ) );
		}

		if ( isset( $body['published'] ) ) {
			$data['published'] = empty( $body['published'] ) ? 0 : 1;
		}

		$this->roles->update( $id, $data );
		$this->resolver->invalidate();

		return $this->ok( $this->roles->find( $id ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function delete_item( $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->roles->find( $id ) ) {
			return $this->not_found();
		}

		// Assignments go with it. An assignment pointing at a deleted role would silently
		// grant nothing, which looks exactly like a permission bug to whoever it affects.
		$this->roles->unassign_role( $id );
		$this->roles->delete( $id );
		$this->resolver->invalidate();

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_assignments( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$assignments = $this->roles->assignments();

		foreach ( $assignments as $index => $assignment ) {
			if ( null === $assignment['user_id'] ) {
				continue;
			}

			$user = get_userdata( (int) $assignment['user_id'] );

			$assignments[ $index ]['user_name'] = false === $user ? '' : $user->display_name;
		}

		return $this->ok( $assignments );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function assign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body    = (array) $request->get_json_params();
		$role_id = absint( $body['role_id'] ?? 0 );

		if ( null === $this->roles->find( $role_id ) ) {
			return $this->fail( 'plugixa_exam_unknown_role', __( 'That role does not exist.', 'plugixa-exam' ) );
		}

		$user_id = isset( $body['user_id'] ) ? absint( $body['user_id'] ) : 0;
		$wp_role = isset( $body['wp_role'] ) ? sanitize_key( (string) $body['wp_role'] ) : '';

		/*
		 * Exactly one of the two. The source schema expressed this as a CHECK constraint,
		 * which MySQL ignored entirely before 8.0.16 — a guarantee that silently is not one
		 * on half the installations. Enforced here instead, where it always runs.
		 */
		if ( ( 0 === $user_id ) === ( '' === $wp_role ) ) {
			return $this->fail(
				'plugixa_exam_invalid_assignment',
				__( 'Assign the role to either a user or a WordPress role, not both.', 'plugixa-exam' )
			);
		}

		if ( 0 !== $user_id && false === get_userdata( $user_id ) ) {
			return $this->fail( 'plugixa_exam_unknown_user', __( 'That user does not exist.', 'plugixa-exam' ) );
		}

		if ( '' !== $wp_role && null === get_role( $wp_role ) ) {
			return $this->fail( 'plugixa_exam_unknown_wp_role', __( 'That WordPress role does not exist.', 'plugixa-exam' ) );
		}

		$user  = 0 === $user_id ? null : $user_id;
		$slug  = '' === $wp_role ? null : $wp_role;

		if ( $this->roles->assignment_exists( $role_id, $user, $slug ) ) {
			return $this->fail( 'plugixa_exam_already_assigned', __( 'That assignment already exists.', 'plugixa-exam' ), 409 );
		}

		$id = $this->roles->assign( $role_id, $user, $slug );
		$this->resolver->invalidate();

		return $this->ok( array( 'id' => $id ), array(), 201 );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function unassign( $request ): WP_REST_Response {
		$this->roles->unassign( (int) $request['id'] );
		$this->resolver->invalidate();

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Drop anything that is not a known feature/action pair.
	 *
	 * A typo in a role payload would otherwise be stored as a permission that can never
	 * match — the role looks configured and grants nothing.
	 *
	 * @param array<string, mixed> $input Raw permissions.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private function clean_permissions( array $input ): array {
		$clean = array();

		foreach ( Permissions::FEATURES as $feature => $actions ) {
			foreach ( $actions as $action ) {
				$clean[ $feature ][ $action ] = ! empty( $input[ $feature ][ $action ] );
			}
		}

		return $clean;
	}
}
