<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Roles\Services;

use PlugixaExam\Core\Database\Schema as Db;
use PlugixaExam\Modules\Roles\Repositories\RoleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exam roles given to a person, in WordPress's privacy tools.
 *
 * A row here says "this user is a grader" — about a member of staff rather than a candidate,
 * but staff can make a request too. Erasure REVOKES: a role left assigned to an account
 * whose owner asked to be forgotten is access nobody is accountable for. Assignments to a
 * WordPress role (`wp_role`) name no person and are not touched.
 */
final class Privacy {

	public function __construct( private readonly RoleRepository $roles ) {}

	/**
	 * @param array<int, array<string, mixed>> $providers Accumulated providers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function provider( array $providers ): array {
		$providers[] = array(
			'id'     => 'role-assignments',
			'label'  => __( 'Exam staff roles', 'plugixa-exam' ),
			'export' => array( $this, 'export' ),
			'erase'  => array( $this, 'erase' ),
		);

		return $providers;
	}

	/**
	 * @param string $email Email address the request was made for.
	 * @param int    $page  Unused; a person holds a handful of roles.
	 *
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		unset( $page );

		$data = array();

		foreach ( $this->rows( $email ) as $row ) {
			$role = $this->roles->find( (int) $row['role_id'] );

			$data[] = array(
				'group_id'    => 'plugixa-exam-role-assignments',
				'group_label' => __( 'Exam staff roles', 'plugixa-exam' ),
				'item_id'     => 'plugixa-exam-role-assignment-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Role', 'plugixa-exam' ),
						'value' => esc_html( null === $role ? '#' . (int) $row['role_id'] : (string) $role['title'] ),
					),
					array(
						'name'  => __( 'Assigned', 'plugixa-exam' ),
						'value' => (string) ( $row['created_at'] ?? '' ),
					),
				),
			);
		}

		return array( 'data' => $data, 'done' => true );
	}

	/**
	 * @param string $email Email address the request was made for.
	 * @param int    $page  Unused.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		global $wpdb;

		unset( $page );

		$removed = 0;

		foreach ( $this->rows( $email ) as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->delete prepares.
			$removed += (int) $wpdb->delete( Db::table( 'role_assignments' ), array( 'id' => (int) $row['id'] ) );
		}

		// No cache to clear: PermissionResolver caches per request only, so the next one
		// already resolves without the revoked role.
		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Assignments to this person's account. Only accounts hold roles.
	 *
	 * @param string $email Address.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( string $email ): array {
		global $wpdb;

		$user = get_user_by( 'email', sanitize_email( $email ) );

		if ( false === $user ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; a privacy request must read the table as it is.
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id ASC', Db::table( 'role_assignments' ), (int) $user->ID ),
			ARRAY_A
		);
	}
}
