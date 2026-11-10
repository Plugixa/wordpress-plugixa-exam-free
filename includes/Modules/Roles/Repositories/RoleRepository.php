<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Roles\Repositories;

use PlugixaExam\Core\Database\InList;
use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;
use PlugixaExam\Modules\Roles\Services\DefaultRoles;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for roles and their assignments.
 */
final class RoleRepository extends Repository {

	public function table(): string {
		return 'roles';
	}

	public function entity_type(): string {
		return 'role';
	}

	/**
	 * @return string[]
	 */
	public function fillable(): array {
		return array( 'title', 'description', 'permissions', 'is_builtin', 'published' );
	}

	/**
	 * @return string[]
	 */
	public function sortable(): array {
		return array( 'id', 'title', 'created_at' );
	}

	/**
	 * Every live role.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		return array_map(
			array( $this, 'cast' ),
			$this->query()->order_by( 't.id', 'ASC' )->paginate( 500 )->get()
		);
	}

	/**
	 * Whether any role exists, including trashed ones.
	 *
	 * Used to decide whether to seed the built-in roles. Counting trashed rows too is
	 * deliberate: an administrator who deleted "Grader" on purpose should not find it
	 * recreated on the next plugin update.
	 */
	public function any_exist(): bool {
		return null !== $this->query()->select( 't.id' )->with_trashed()->first();
	}

	/* ------------------------------------------------------------- assignments */

	/**
	 * All assignments, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function assignments(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC', Db::table( 'role_assignments' ) ),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$row['id']      = (int) $row['id'];
				$row['role_id'] = (int) $row['role_id'];
				$row['user_id'] = null === $row['user_id'] ? null : (int) $row['user_id'];

				return $row;
			},
			$rows
		);
	}

	/**
	 * Role ids that apply to a user — assigned directly, or through a WordPress role.
	 *
	 * ONE QUERY, NOT TWO. The permission resolver calls this on every authorisation check
	 * that is not already memoised, which on a busy grading screen is a great many.
	 *
	 * @param int      $user_id  User id.
	 * @param string[] $wp_roles The user's WordPress role slugs.
	 *
	 * @return int[]
	 */
	public function role_ids_for( int $user_id, array $wp_roles ): array {
		global $wpdb;

		$table = Db::table( 'role_assignments' );

		if ( array() === $wp_roles ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$ids = $wpdb->get_col(
				$wpdb->prepare( 'SELECT role_id FROM %i WHERE user_id = %d', $table, $user_id )
			);

			return array_map( 'intval', (array) $ids );
		}

		/*
		 * The user's role slugs as one comma-joined set, so the statement is a literal — see
		 * `InList`. A slug never contains a comma; one that did is dropped rather than read as
		 * two roles.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT role_id FROM %i WHERE user_id = %d OR FIND_IN_SET( wp_role, %s )',
				$table,
				$user_id,
				InList::strings( $wp_roles )
			)
		);

		return array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Attach a role to a user or a WordPress role.
	 *
	 * @param int         $role_id Role id.
	 * @param int|null    $user_id User id, or null.
	 * @param string|null $wp_role WordPress role slug, or null.
	 */
	public function assign( int $role_id, ?int $user_id, ?string $wp_role ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->insert prepares.
		$wpdb->insert(
			Db::table( 'role_assignments' ),
			array(
				'role_id'    => $role_id,
				'user_id'    => $user_id,
				'wp_role'    => $wp_role,
				'created_at' => current_time( 'mysql', true ),
				'created_by' => get_current_user_id(),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Remove an assignment.
	 *
	 * @param int $id Assignment id.
	 */
	public function unassign( int $id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->delete prepares.
		$wpdb->delete( Db::table( 'role_assignments' ), array( 'id' => $id ) );
	}

	/**
	 * Remove every assignment of a role.
	 *
	 * @param int $role_id Role id.
	 */
	public function unassign_role( int $role_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->delete prepares.
		$wpdb->delete( Db::table( 'role_assignments' ), array( 'role_id' => $role_id ) );
	}

	/**
	 * Whether an assignment already exists.
	 *
	 * @param int         $role_id Role id.
	 * @param int|null    $user_id User id.
	 * @param string|null $wp_role WordPress role slug.
	 */
	public function assignment_exists( int $role_id, ?int $user_id, ?string $wp_role ): bool {
		global $wpdb;

		$table = Db::table( 'role_assignments' );

		if ( null !== $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			return (bool) $wpdb->get_var(
				$wpdb->prepare( 'SELECT id FROM %i WHERE role_id = %d AND user_id = %d', $table, $role_id, $user_id )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE role_id = %d AND wp_role = %s', $table, $role_id, (string) $wp_role )
		);
	}

	/**
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		$row = parent::cast( $row );

		/*
		 * Translate the text the SEEDER wrote, here rather than where it was written.
		 * `DefaultRoles::definitions()` runs inside `Activator::migrate()` on
		 * `plugins_loaded`, where WordPress will not load a textdomain at all — so a
		 * `__()` there returns English while raising the just-in-time notice, and on the
		 * day it worked it would freeze one language into the row for every reader
		 * afterwards. A role the site has renamed matches no key and passes through, which
		 * is what makes translating on read safe.
		 *
		 * ⚠️ SORTING STILL USES THE STORED COLUMN — `sortable()` orders by `title` in SQL,
		 * which is the English. Sorting in the reader's language would mean giving up SQL
		 * paging, which is the wrong trade for five built-in rows.
		 */
		$labels = DefaultRoles::labels();

		foreach ( array( 'title', 'description' ) as $column ) {
			if ( isset( $row[ $column ] ) && is_string( $row[ $column ] ) ) {
				$row[ $column ] = plugixa_exam_translate_seeded( $row[ $column ], $labels );
			}
		}

		foreach ( array( 'published', 'is_builtin' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (bool) (int) $row[ $column ];
			}
		}

		if ( array_key_exists( 'permissions', $row ) ) {
			$decoded            = json_decode( (string) $row['permissions'], true );
			$row['permissions'] = is_array( $decoded ) ? $decoded : array();
		}

		return $row;
	}
}
