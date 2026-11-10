<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Roles\Services;

use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Core\Settings;
use PlugixaExam\Modules\Roles\Repositories\RoleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a user into a yes or no.
 *
 * Hooked to `plugixa_exam_user_can`, which `Core\Security\Permissions` fires. Core asks;
 * this answers.
 *
 * THREE RULES, IN ORDER, AND THE ORDER IS THE DESIGN.
 *
 *  1. A user who holds `manage_options` is allowed everything. Not a shortcut — an
 *     administrator locked out of their own exam plugin by a role matrix they mis-clicked
 *     has no way back in, because the Roles screen is itself behind the matrix.
 *  2. A user with NO exam role assigned is refused everything. Deliberately not "allowed by
 *     default": on a site where roles are configured, an unlisted user is an outsider.
 *  3. Otherwise the union of their roles decides. Union, not intersection — holding two
 *     roles grants what either grants, which is what "also a Grader" means to the person
 *     doing the assigning.
 */
final class PermissionResolver {

	/**
	 * Resolved permission maps, keyed by "userId:permissionVersion".
	 *
	 * The version is part of the key rather than something that clears the cache, so a role
	 * edited mid-request is picked up by the next lookup without any invalidation call
	 * having to be remembered.
	 *
	 * @var array<string, array<string, array<string, bool>>|null>
	 */
	private array $cache = array();

	/**
	 * @param RoleRepository $roles    The repository.
	 * @param Settings       $settings For the permission version.
	 */
	public function __construct(
		private readonly RoleRepository $roles,
		private readonly Settings $settings
	) {}

	/**
	 * Answer one authorisation question.
	 *
	 * @param bool   $allowed Whatever Core decided by default.
	 * @param string $feature Feature slug.
	 * @param string $action  Action slug.
	 * @param int    $user_id User being checked.
	 */
	public function user_can( bool $allowed, string $feature, string $action, int $user_id ): bool {
		unset( $allowed );

		if ( 0 === $user_id ) {
			return false;
		}

		// Rule 1. See the class docblock.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$matrix = $this->matrix_for( $user_id );

		// Rule 2.
		if ( null === $matrix ) {
			return false;
		}

		// Rule 3.
		return true === ( $matrix[ $feature ][ $action ] ?? false );
	}

	/**
	 * The merged permission map for a user, or null if they have no exam role.
	 *
	 * @param int $user_id User id.
	 *
	 * @return array<string, array<string, bool>>|null
	 */
	public function matrix_for( int $user_id ): ?array {
		$key = $user_id . ':' . $this->settings->int( 'permission_version', 1 );

		if ( array_key_exists( $key, $this->cache ) ) {
			return $this->cache[ $key ];
		}

		$user = get_userdata( $user_id );

		if ( false === $user ) {
			$this->cache[ $key ] = null;

			return null;
		}

		$role_ids = $this->roles->role_ids_for( $user_id, (array) $user->roles );

		if ( array() === $role_ids ) {
			$this->cache[ $key ] = null;

			return null;
		}

		$merged = array();

		foreach ( $this->roles->all() as $role ) {
			if ( ! in_array( (int) $role['id'], $role_ids, true ) || ! $role['published'] ) {
				continue;
			}

			foreach ( (array) $role['permissions'] as $feature => $actions ) {
				foreach ( (array) $actions as $action => $granted ) {
					// Union: once any role grants an action, another role's `false` must not
					// take it away. Assigning a second, narrower role should never remove
					// something the first one allowed.
					$merged[ $feature ][ $action ] = ! empty( $merged[ $feature ][ $action ] ) || ! empty( $granted );
				}
			}
		}

		$this->cache[ $key ] = $merged;

		return $merged;
	}

	/**
	 * Invalidate every resolved matrix, everywhere.
	 *
	 * Bumps a counter rather than clearing anything: the counter is part of the cache key,
	 * so nothing has to be found and cleared, and a matrix memoised in another process is
	 * stale the moment it looks the key up again.
	 */
	public function invalidate(): void {
		$this->settings->set( 'permission_version', $this->settings->int( 'permission_version', 1 ) + 1, 'integer' );

		$this->cache = array();
	}

	/**
	 * Whether the current user may administer the plugin at all.
	 *
	 * @param int $user_id User id.
	 */
	public function is_administrator( int $user_id ): bool {
		return user_can( $user_id, 'manage_options' ) || user_can( $user_id, Capabilities::SETTINGS );
	}

	/**
	 * Give `plugixa_exam_manage` to anybody holding an exam role.
	 *
	 * WITHOUT THIS, THE ROLE SYSTEM DOES NOTHING FOR MOST OF THE PEOPLE IT IS FOR.
	 *
	 * Core's outer gate asks for `plugixa_exam_manage` or `edit_posts` before the matrix is
	 * consulted at all — deliberately, so `permission_callback` is a real capability check
	 * that Plugin Check and role-management plugins can both see. But the people an exam
	 * site most wants to give a narrow role to are exactly the ones with no WordPress
	 * capabilities: a visiting examiner who marks essays is a Subscriber. Assign them
	 * Grader and, without this filter, they are refused at the gate and never reach the
	 * matrix that was supposed to let them in.
	 *
	 * So holding an exam role IS what grants the capability. The gate stays real — it is
	 * still a capability, still checked by core — and this module decides who has it, which
	 * is the same division of labour as everywhere else here.
	 *
	 * Only `MANAGE` is granted. `SETTINGS` is not, because settings, roles and the App
	 * Manager take `may_configure()` and must stay with `manage_options`: a role that could
	 * edit roles could grant itself anything.
	 *
	 * @param array<string, bool> $allcaps All capabilities the user has.
	 * @param string[]            $caps    Capabilities being checked.
	 * @param array<int, mixed>   $args    Context: [ requested cap, user id, … ].
	 *
	 * @return array<string, bool>
	 */
	public function grant_capability( array $allcaps, array $caps, array $args ): array {
		// Only do the work when this capability is the one being asked about. `user_has_cap`
		// fires on every capability check WordPress makes, including several per admin page
		// load, and resolving the matrix for all of them would be pure waste.
		if ( ! in_array( Capabilities::MANAGE, $caps, true ) ) {
			return $allcaps;
		}

		if ( ! empty( $allcaps[ Capabilities::MANAGE ] ) ) {
			return $allcaps;
		}

		$user_id = (int) ( $args[1] ?? 0 );

		if ( 0 === $user_id ) {
			return $allcaps;
		}

		/*
		 * Re-entrancy guard. `matrix_for()` reads the roles table through the query
		 * builder, which fires `plugixa_exam_query_clauses`; a listener on that which asks
		 * a capability question would land back here. Cheap to prevent, and the failure
		 * mode is a stack overflow with no useful trace.
		 */
		static $resolving = false;

		if ( $resolving ) {
			return $allcaps;
		}

		$resolving = true;

		try {
			if ( null !== $this->matrix_for( $user_id ) ) {
				$allcaps[ Capabilities::MANAGE ] = true;
			}
		} finally {
			$resolving = false;
		}

		return $allcaps;
	}
}
