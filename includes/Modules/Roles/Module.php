<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Roles;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Core\Plugin;
use PlugixaExam\Modules\Roles\Http\RolesController;
use PlugixaExam\Modules\Roles\Services\DefaultRoles;
use PlugixaExam\Modules\Roles\Services\PermissionResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's own role system.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'roles';
	}

	public function app(): array {
		return array(
			'category' => 'core',
			'core'     => true,
		);
	}

	public function migrate(): void {
		Schema::install();

		// Seeded after the tables exist, and only into an empty table. See DefaultRoles.
		Plugin::instance()->container()->get( DefaultRoles::class )->seed();
	}

	public function capabilities(): array {
		return array();
	}

	public function register_services( Container $container ): void {
		// PRIVACY LIVES HERE, not in `register_hooks()`, which does not run when this app is
		// switched off in the App Manager (NFR-ORG-6). See Services\Privacy.
		add_filter(
			'plugixa_exam_personal_data_providers',
			static fn ( array $providers ): array => $container->get( Services\Privacy::class )->provider( $providers )
		);
	}

	public function register_routes( Container $container ): void {
		$container->get( RolesController::class )->register_routes();
	}

	public function register_hooks( Container $container ): void {
		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );

		/*
		 * THE ANSWER TO CORE'S QUESTION.
		 *
		 * `Core\Security\Permissions` fires this filter and takes what comes back. Core
		 * owns the vocabulary — the 12 features and their actions — because everything
		 * needs to agree on the spelling; this module owns the resolution, because the
		 * roles table belongs to it and `includes/Core` may never name a module.
		 *
		 * With this module removed the filter has no listener, and Core's own default
		 * stands: everything for a user holding `plugixa_exam_manage`, nothing for anybody
		 * else. A site that loses its Roles module does not silently open up.
		 */
		$resolver = $container->get( PermissionResolver::class );

		add_filter(
			'plugixa_exam_user_can',
			array( $resolver, 'user_can' ),
			10,
			4
		);

		// Holding an exam role is what grants the capability Core's outer gate asks for.
		// See PermissionResolver::grant_capability() for why this is necessary rather than
		// merely convenient.
		add_filter( 'user_has_cap', array( $resolver, 'grant_capability' ), 10, 3 );
	}

	/**
	 * @param array<string, string> $strings Accumulated strings.
	 *
	 * @return array<string, string>
	 */
	public function strings( array $strings ): array {
		return array_merge(
			$strings,
			array(
				'roles'              => __( 'Roles', 'plugixa-exam' ),
				'rolesDesc' => __( 'Who may write, mark and manage exams, beyond their WordPress role.', 'plugixa-exam' ),
				'role'               => __( 'Role', 'plugixa-exam' ),
				'newRole'            => __( 'New role', 'plugixa-exam' ),
				'roleName'           => __( 'Name', 'plugixa-exam' ),
				'roleDescription'    => __( 'Description', 'plugixa-exam' ),
				'rolesIntro'         => __( 'Roles decide what each person can do inside the exam plugin. Administrators always have full access.', 'plugixa-exam' ),
				'rolesEmpty'         => __( 'No roles yet.', 'plugixa-exam' ),
				'builtinRole'        => __( 'Built in', 'plugixa-exam' ),
				'permissions'        => __( 'Permissions', 'plugixa-exam' ),
				'assignments'        => __( 'Who has this role', 'plugixa-exam' ),
				'assignToUser'       => __( 'Assign to a user', 'plugixa-exam' ),
				'assignToWpRole'     => __( 'Assign to a WordPress role', 'plugixa-exam' ),
				'assignmentsEmpty'   => __( 'Nobody has been given an exam role yet, so only administrators can use the plugin.', 'plugixa-exam' ),
				'unassign'           => __( 'Remove', 'plugixa-exam' ),
				'selectAll'          => __( 'Select all', 'plugixa-exam' ),
				'selectNone'         => __( 'Select none', 'plugixa-exam' ),

				// Feature labels for the matrix.
				'featureQuestions'     => __( 'Questions', 'plugixa-exam' ),
				'featureExams'         => __( 'Exams', 'plugixa-exam' ),
				'featureSubjects'      => __( 'Subjects', 'plugixa-exam' ),
				'featureAttempts'      => __( 'Attempts', 'plugixa-exam' ),
				'featureMonitoring'    => __( 'Live monitoring', 'plugixa-exam' ),
				'featureReports'       => __( 'Reports', 'plugixa-exam' ),
				'featureCohorts'       => __( 'Cohorts', 'plugixa-exam' ),
				'featureCertificates'  => __( 'Certificates', 'plugixa-exam' ),
				'featureLti'           => __( 'LTI', 'plugixa-exam' ),
				'featureWebhooks'      => __( 'Webhooks', 'plugixa-exam' ),
				'featureApi_tokens'    => __( 'API tokens', 'plugixa-exam' ),
				'featureEnrolments'    => __( 'Enrolments', 'plugixa-exam' ),
				'featureConfiguration' => __( 'Configuration', 'plugixa-exam' ),
			)
		);
	}
}
