<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The WordPress half of authorisation.
 *
 * TWO LAYERS, AND BOTH ARE LOAD-BEARING.
 *
 * This class is the outer one: four plain WordPress capabilities that a REST route's
 * `permission_callback` can check without knowing anything about exams. That is not
 * ceremony — Plugin Check reads `permission_callback`, site owners expect capabilities to
 * work with their role-management plugin, and a route whose only guard is a bespoke
 * permission system is a route that looks unguarded to every tool that inspects it.
 *
 * {@see Permissions} is the inner one: the 12-feature × 4-action matrix ported from
 * NextExams, which decides what a *Grader* may do that an *Author* may not. It runs after
 * this and can only narrow, never widen.
 *
 * The order matters. A user without `plugixa_exam_manage` is refused before the role matrix
 * is consulted at all, so an installation that has never configured a single exam role
 * still behaves sensibly: administrators can do everything, nobody else can do anything.
 */
final class Capabilities {

	public const MANAGE   = 'plugixa_exam_manage';
	public const EDIT     = 'plugixa_exam_edit';
	public const DELETE   = 'plugixa_exam_delete';
	public const SETTINGS = 'plugixa_exam_settings';

	/**
	 * The meta capability a REST route asks: "may this user do ACTION on FEATURE?"
	 *
	 * Asked as `current_user_can( Capabilities::ACCESS, 'exams', 'publish' )`, the way core
	 * asks `current_user_can( 'edit_post', $id )`. Nobody is ever GRANTED it: WordPress maps
	 * it, through {@see Permissions::map_access()}, onto the answer both layers give
	 * together. It exists so that every route's permission check is WordPress's own function,
	 * written on the line that registers the route, where a reader of that line — a person
	 * or a tool — can see it.
	 */
	public const ACCESS = 'plugixa_exam_access';

	/**
	 * WordPress capability each plugin capability falls back to.
	 *
	 * A site that has never granted the plugin capabilities — because it was installed
	 * before they existed, or because a role plugin rebuilt its roles — still works. The
	 * fallbacks are deliberately conservative: `manage_options` for anything that changes
	 * how the plugin behaves, `edit_posts` for content work.
	 *
	 * @var array<string, string>
	 */
	private const FALLBACKS = array(
		self::MANAGE   => 'edit_posts',
		self::EDIT     => 'edit_posts',
		self::DELETE   => 'edit_posts',
		self::SETTINGS => 'manage_options',
	);

	/**
	 * The capabilities Core grants, and to whom.
	 *
	 * Modules add their own through {@see \PlugixaExam\Core\Modules\ModuleInterface::capabilities()}.
	 *
	 * @return array<string, string[]>
	 */
	public static function core_map(): array {
		return array(
			self::MANAGE   => array( 'administrator' ),
			self::EDIT     => array( 'administrator' ),
			self::DELETE   => array( 'administrator' ),
			self::SETTINGS => array( 'administrator' ),
		);
	}

	/**
	 * Whether the current user holds a capability, honouring the fallback.
	 *
	 * @param string $capability One of the constants above.
	 */
	public static function current_user_can( string $capability ): bool {
		if ( current_user_can( $capability ) ) {
			return true;
		}

		$fallback = self::FALLBACKS[ $capability ] ?? null;

		return null !== $fallback && current_user_can( $fallback );
	}

	/**
	 * Grant capabilities to roles.
	 *
	 * Runs on activation and whenever the version changes, so it must be idempotent —
	 * `WP_Role::add_cap()` already is.
	 *
	 * @param array<string, string[]> $map Capability => role names.
	 */
	public static function grant( array $map ): void {
		foreach ( $map as $capability => $roles ) {
			foreach ( $roles as $role_name ) {
				$role = get_role( $role_name );

				if ( null !== $role ) {
					$role->add_cap( $capability );
				}
			}
		}
	}

	/**
	 * Remove every capability this plugin ever granted, from every role.
	 *
	 * Sweeps all roles rather than the ones in the grant map, because a site owner may have
	 * copied a capability onto a custom role — and an uninstall that leaves a dangling
	 * `plugixa_exam_*` capability on a role is litter that outlives the plugin.
	 */
	public static function revoke_all(): void {
		$capabilities = array( self::MANAGE, self::EDIT, self::DELETE, self::SETTINGS );

		/**
		 * Capabilities to remove at uninstall.
		 *
		 * @param string[] $capabilities Capability names.
		 */
		$capabilities = (array) apply_filters( 'plugixa_exam_uninstall_capabilities', $capabilities );

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( $capabilities as $capability ) {
				$role->remove_cap( (string) $capability );
			}
		}
	}
}
