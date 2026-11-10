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
 * The feature × action matrix, ported from NextExams' role system.
 *
 * CORE ASKS THE QUESTION; THE ROLES MODULE ANSWERS IT.
 *
 * The vocabulary lives here because everything uses it — a REST route names a feature and
 * an action, the SPA is handed the resolved matrix, and both have to agree on the spelling.
 * The *resolution* does not live here, because the roles table belongs to the Roles module
 * and `includes/Core` may never name a module (`CoreIndependenceTest` enforces it). So this
 * class fires `plugixa_exam_user_can` and takes the answer.
 *
 * WHAT HAPPENS WITH NO LISTENER — on a fresh install, or if the Roles module is removed —
 * is the interesting case, and it is deliberate: `default_for()` grants everything to a
 * user who holds `plugixa_exam_manage`, and nothing to anyone else. A site that has never
 * opened the Roles screen behaves the way a site owner would expect, and a site whose Roles
 * module vanished does not silently open up.
 */
final class Permissions {

	/**
	 * Every feature and the actions it defines.
	 *
	 * The full 12 from NextExams, plus `enrolments` (paid access, which NextExams never had),
	 * including features whose module is premium. Keeping the
	 * vocabulary complete in Core is what lets the Roles screen render a coherent matrix
	 * and a saved role survive a module being switched off and back on — a role that
	 * silently dropped its `certificates` block when Certificates was disabled would come
	 * back with those permissions revoked.
	 *
	 * @var array<string, string[]>
	 */
	public const FEATURES = array(
		'questions'     => array( 'create', 'edit', 'edit.own', 'delete', 'view' ),
		'exams'         => array( 'create', 'edit', 'edit.own', 'delete', 'view', 'publish' ),
		'subjects'      => array( 'create', 'edit', 'delete', 'view' ),
		'attempts'      => array( 'view', 'grade', 'unlock', 'delete' ),
		'monitoring'    => array( 'view', 'extend_time', 'force_submit', 'allow_resume', 'pause_exam', 'set_note', 'manage_grants' ),
		'reports'       => array( 'view', 'export' ),
		'cohorts'       => array( 'view', 'create', 'edit', 'delete', 'manage_members' ),
		'certificates'  => array( 'view', 'issue', 'revoke', 'manage_templates' ),
		'lti'           => array( 'view', 'manage_platforms' ),
		'webhooks'      => array( 'view', 'manage' ),
		'api_tokens'    => array( 'view', 'manage' ),
		'enrolments'    => array( 'view', 'grant', 'revoke' ),
		'configuration' => array( 'manage_roles', 'manage_settings', 'manage_customfields', 'manage_notifications' ),
	);

	/**
	 * Teach WordPress the {@see Capabilities::ACCESS} meta capability.
	 */
	public static function register_hooks(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_access' ), 10, 4 );
	}

	/**
	 * Answer `current_user_can( Capabilities::ACCESS, $feature, $action )` from the matrix.
	 *
	 * A meta capability is not held; it is MAPPED onto capabilities that are. A yes maps to
	 * `exist`, which every user WordPress knows holds and which grants nothing by itself —
	 * the decision was already made by {@see self::user_can()}, both layers of it. A no maps
	 * to `do_not_allow`, the one answer core refuses for everybody, a multisite super admin
	 * included.
	 *
	 * A CALL THAT NAMES NO FEATURE OR ACTION IS REFUSED, not passed along: `current_user_can(
	 * 'plugixa_exam_access' )` on its own is a mistake in the caller, and a mistake in a
	 * permission check has to fail shut.
	 *
	 * @param string[] $caps    Primitive capabilities WordPress would otherwise require.
	 * @param string   $cap     The capability being asked about.
	 * @param int      $user_id The user it is asked of.
	 * @param mixed[]  $args    What followed the capability in the call.
	 *
	 * @return string[]
	 */
	public static function map_access( $caps, $cap, $user_id, $args ) {
		if ( Capabilities::ACCESS !== $cap ) {
			return $caps;
		}

		$feature = isset( $args[0] ) && is_string( $args[0] ) ? $args[0] : '';
		$action  = isset( $args[1] ) && is_string( $args[1] ) ? $args[1] : '';

		if ( '' === $feature || '' === $action ) {
			return array( 'do_not_allow' );
		}

		return self::user_can( $feature, $action, (int) $user_id )
			? array( 'exist' )
			: array( 'do_not_allow' );
	}

	/**
	 * Whether a user may perform an action on a feature.
	 *
	 * @param string   $feature One of {@see FEATURES}.
	 * @param string   $action  One of that feature's actions.
	 * @param int|null $user_id Defaults to the current user.
	 */
	public static function user_can( string $feature, string $action, ?int $user_id = null ): bool {
		$user_id ??= get_current_user_id();

		if ( 0 === $user_id ) {
			return false;
		}

		// The outer layer first. A user who does not hold the WordPress capability is
		// refused before the matrix is consulted, so the two layers can only narrow.
		if ( ! user_can( $user_id, Capabilities::MANAGE ) && ! user_can( $user_id, 'edit_posts' ) ) {
			return false;
		}

		$default = self::default_for( $user_id );

		/**
		 * Resolve a permission against the plugin's role matrix.
		 *
		 * The Roles module hooks this. With no listener the default stands — see the class
		 * docblock for why that default is what it is.
		 *
		 * @param bool   $allowed Whether the action is permitted.
		 * @param string $feature Feature slug.
		 * @param string $action  Action slug.
		 * @param int    $user_id User being checked.
		 */
		return (bool) apply_filters( 'plugixa_exam_user_can', $default, $feature, $action, $user_id );
	}

	/**
	 * The whole matrix for a user, as the SPA consumes it.
	 *
	 * Shipped into `window.PlugixaExamConfig.permissions` so the UI never offers an action
	 * the API would refuse — and, just as importantly, never hides one it would accept. A
	 * UI that is stricter than the API is a support ticket nobody can reproduce.
	 *
	 * @param int|null $user_id Defaults to the current user.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function matrix_for( ?int $user_id = null ): array {
		$user_id ??= get_current_user_id();

		$matrix = array();

		foreach ( self::FEATURES as $feature => $actions ) {
			foreach ( $actions as $action ) {
				$matrix[ $feature ][ $action ] = self::user_can( $feature, $action, $user_id );
			}
		}

		return $matrix;
	}

	/**
	 * Whether a feature/action pair is one this plugin defines.
	 *
	 * Called before a role is saved, so a typo in a role payload is rejected rather than
	 * stored as a permission that will never match anything.
	 *
	 * @param string $feature Feature slug.
	 * @param string $action  Action slug.
	 */
	public static function is_known( string $feature, string $action ): bool {
		return isset( self::FEATURES[ $feature ] )
			&& in_array( $action, self::FEATURES[ $feature ], true );
	}

	/**
	 * The answer when nothing has hooked the filter.
	 *
	 * @param int $user_id User being checked.
	 */
	private static function default_for( int $user_id ): bool {
		return user_can( $user_id, Capabilities::MANAGE );
	}
}
