<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Roles;

use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Roles and their assignments.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'roles', 'role_assignments' );
	}

	/**
	 * @return array<string, string> What dbDelta changed.
	 */
	public static function install(): array {
		$changes = Db::delta( self::roles(), self::role_assignments() );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * Exam roles.
	 *
	 * WHY THIS IS NOT JUST WORDPRESS ROLES.
	 *
	 * WordPress roles are site-wide and coarse; this matrix is 12 features × up to 7
	 * actions, and an exam site genuinely needs the distinctions — a Grader marks essays but
	 * must not edit the question they are marking, a Proctor may extend somebody's time but
	 * must never force-submit their paper. Expressing that as ~40 WordPress capabilities
	 * would put forty rows in every user's meta and make the Roles screen a capability
	 * editor rather than a matrix anyone can read.
	 *
	 * The four WordPress capabilities in `Core\Security\Capabilities` remain the outer gate,
	 * so `permission_callback` is real and role-management plugins still work. This is the
	 * inner one, and it can only narrow.
	 */
	private static function roles(): string {
		$table   = Db::table( 'roles' );
		$charset = Db::charset();
		$audit   = Repository::audit_columns();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			title varchar(255) NOT NULL default '',
			description text,
			permissions longtext,
			is_builtin tinyint(1) NOT NULL default 0,
			published tinyint(1) NOT NULL default 1,
			{$audit}
			PRIMARY KEY  (id),
			KEY published (published),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}

	/**
	 * Role assignments: to a user, or to a WordPress role.
	 *
	 * `wp_role` is a SLUG, not an id. Joomla numbers its user groups; WordPress names its
	 * roles, and storing an integer here would be a foreign key to nothing.
	 *
	 * The CHECK constraint the source schema used is not portable — MySQL ignored CHECK
	 * entirely before 8.0.16 and MariaDB behaves differently again — so exactly-one-of is
	 * enforced in the service. A constraint that silently does nothing on half the
	 * installations is worse than an explicit check, because it reads like a guarantee.
	 */
	private static function role_assignments(): string {
		$table   = Db::table( 'role_assignments' );
		$charset = Db::charset();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			role_id bigint(20) unsigned NOT NULL default 0,
			user_id bigint(20) unsigned default NULL,
			wp_role varchar(60) default NULL,
			created_at datetime default NULL,
			created_by bigint(20) unsigned NOT NULL default 0,
			PRIMARY KEY  (id),
			KEY role_id (role_id),
			KEY user_id (user_id),
			KEY wp_role (wp_role)
		) {$charset};";
	}
}
