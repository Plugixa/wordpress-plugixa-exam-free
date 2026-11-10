<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Roles\Services;

use PlugixaExam\Core\Security\Permissions;
use PlugixaExam\Modules\Roles\Repositories\RoleRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The five roles a fresh install starts with.
 *
 * Seeded once, on the first migration that finds an empty roles table, and never again —
 * `RoleRepository::any_exist()` counts trashed rows too, so an administrator who deleted
 * "Proctor" on purpose does not find it recreated by the next plugin update.
 *
 * They are ordinary rows, editable and deletable like any other. `is_builtin` only marks
 * them in the UI so somebody can tell "the role that came with the plugin" from "the role
 * my predecessor made".
 */
final class DefaultRoles {

	/**
	 * @param RoleRepository $roles The repository.
	 */
	public function __construct( private readonly RoleRepository $roles ) {}

	/**
	 * Create the built-in roles, unless any role already exists.
	 */
	public function seed(): void {
		if ( $this->roles->any_exist() ) {
			return;
		}

		foreach ( self::definitions() as $definition ) {
			$this->roles->insert(
				array(
					'title'       => $definition['title'],
					'description' => $definition['description'],
					'permissions' => (string) wp_json_encode( $definition['permissions'] ),
					'is_builtin'  => 1,
					'published'   => 1,
				)
			);
		}
	}

	/**
	 * The seeded roles, in ENGLISH.
	 *
	 * The titles and descriptions here are deliberately NOT wrapped in `__()`. This runs
	 * inside `Activator::migrate()` on `plugins_loaded`, where WordPress refuses to load a
	 * textdomain at all — so a `__()` here returns English while raising
	 * `_load_textdomain_just_in_time was called incorrectly`, and on the day it did work it
	 * would freeze one language into the row for every reader afterwards.
	 *
	 * {@see self::labels()} carries the translations, applied when a role is read.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function definitions(): array {
		return array(
			array(
				'title'       => 'Exam Admin',
				'description' => 'Full access to every feature and setting.',
				'permissions' => self::all_true(),
			),
			array(
				'title'       => 'Author',
				'description' => 'Builds questions, exams and subjects. Cannot grade attempts or change settings.',
				'permissions' => self::grant(
					array(
						'questions'    => array( 'create', 'edit', 'edit.own', 'delete', 'view' ),
						'exams'        => array( 'create', 'edit', 'edit.own', 'delete', 'view', 'publish' ),
						'subjects'     => array( 'create', 'edit', 'delete', 'view' ),
						'attempts'     => array( 'view' ),
						'monitoring'   => array( 'view' ),
						'reports'      => array( 'view', 'export' ),
						'cohorts'      => array( 'view' ),
						'certificates' => array( 'view', 'manage_templates' ),
					)
				),
			),
			array(
				'title'       => 'Grader',
				'description' => 'Marks attempts and reads content. Cannot author or delete anything.',
				'permissions' => self::grant(
					array(
						'questions'    => array( 'view' ),
						'exams'        => array( 'view' ),
						'subjects'     => array( 'view' ),
						'attempts'     => array( 'view', 'grade' ),
						'monitoring'   => array( 'view' ),
						'reports'      => array( 'view' ),
						'certificates' => array( 'view' ),
					)
				),
			),
			array(
				/*
				 * The Proctor's limits are the point of having a Proctor at all: they can
				 * give somebody more time and let a disconnected examinee back in, but they
				 * cannot end anybody's paper. `force_submit` and `pause_exam` are withheld
				 * deliberately — the same two an invigilator link or PIN can never carry.
				 */
				'title'       => 'Proctor',
				'description' => 'Watches exams in progress. May extend time and allow a resume, but cannot force-submit a paper.',
				'permissions' => self::grant(
					array(
						'questions'  => array( 'view' ),
						'exams'      => array( 'view' ),
						'subjects'   => array( 'view' ),
						'attempts'   => array( 'view' ),
						'monitoring' => array( 'view', 'extend_time', 'allow_resume', 'set_note' ),
						'reports'    => array( 'view' ),
					)
				),
			),
			array(
				'title'       => 'Viewer',
				'description' => 'Read-only access to content and results.',
				'permissions' => self::grant(
					array(
						'questions'    => array( 'view' ),
						'exams'        => array( 'view' ),
						'subjects'     => array( 'view' ),
						'attempts'     => array( 'view' ),
						'monitoring'   => array( 'view' ),
						'reports'      => array( 'view' ),
						'cohorts'      => array( 'view' ),
						'certificates' => array( 'view' ),
					)
				),
			),
		);
	}

	/**
	 * English => translated, for the text {@see self::definitions()} seeded.
	 *
	 * Keyed by the English because the English is what is in the row — the same shape as
	 * WordPress core's `translate_user_role()`. A site that renamed "Grader" to "Marker"
	 * matches no key and keeps its own name, which is the property that makes translating
	 * on read safe at all.
	 *
	 * Every literal appears exactly once inside a `__()`, so `make-pot` extracts it and no
	 * variable is ever handed to a translation function.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		return array(
			'Exam Admin' => __( 'Exam Admin', 'plugixa-exam' ),
			'Full access to every feature and setting.' => __( 'Full access to every feature and setting.', 'plugixa-exam' ),
			'Author'     => __( 'Author', 'plugixa-exam' ),
			'Builds questions, exams and subjects. Cannot grade attempts or change settings.' => __( 'Builds questions, exams and subjects. Cannot grade attempts or change settings.', 'plugixa-exam' ),
			'Grader'     => __( 'Grader', 'plugixa-exam' ),
			'Marks attempts and reads content. Cannot author or delete anything.' => __( 'Marks attempts and reads content. Cannot author or delete anything.', 'plugixa-exam' ),
			'Proctor'    => __( 'Proctor', 'plugixa-exam' ),
			'Watches exams in progress. May extend time and allow a resume, but cannot force-submit a paper.' => __( 'Watches exams in progress. May extend time and allow a resume, but cannot force-submit a paper.', 'plugixa-exam' ),
			'Viewer'     => __( 'Viewer', 'plugixa-exam' ),
			'Read-only access to content and results.' => __( 'Read-only access to content and results.', 'plugixa-exam' ),
		);
	}

	/**
	 * A full matrix with everything granted.
	 *
	 * Built from {@see Permissions::FEATURES} rather than written out, so a feature added
	 * later is granted to Exam Admin automatically. Writing it by hand is how an
	 * administrator ends up unable to reach a feature nobody remembered to add them to.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function all_true(): array {
		$matrix = array();

		foreach ( Permissions::FEATURES as $feature => $actions ) {
			foreach ( $actions as $action ) {
				$matrix[ $feature ][ $action ] = true;
			}
		}

		return $matrix;
	}

	/**
	 * A full matrix with only the listed actions granted.
	 *
	 * Every known action is emitted, `false` included, so the Roles screen renders a
	 * complete grid and a role saved before a feature existed does not read as "denied by
	 * omission" when it was really "not asked".
	 *
	 * @param array<string, string[]> $granted Actions to grant, keyed by feature.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function grant( array $granted ): array {
		$matrix = array();

		foreach ( Permissions::FEATURES as $feature => $actions ) {
			foreach ( $actions as $action ) {
				$matrix[ $feature ][ $action ] = in_array( $action, $granted[ $feature ] ?? array(), true );
			}
		}

		return $matrix;
	}
}
