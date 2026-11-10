<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Subjects;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\Subjects\Http\SubjectsController;
use PlugixaExam\Modules\Subjects\Repositories\SubjectRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subjects — the hierarchical taxonomy questions and exams are filed under.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'subjects';
	}

	public function app(): array {
		return array(
			'category' => 'core',
			'core'     => true,
		);
	}

	public function migrate(): void {
		Schema::install();
	}

	public function capabilities(): array {
		return array();
	}

	public function register_services( Container $container ): void {
		// Autowired: the repository takes no constructor arguments and the service takes
		// only the repository.
		unset( $container );
	}

	public function register_routes( Container $container ): void {
		$container->get( SubjectsController::class )->register_routes();
	}

	public function register_hooks( Container $container ): void {
		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );

		/*
		 * Answer "what is beneath this subject?" for anyone who asks.
		 *
		 * The Questions module filters its list by subject and the exam builder draws pool
		 * questions from one, and both mean "including everything nested under it" — but
		 * neither may name this module. They apply this filter and get the subtree; with
		 * Subjects somehow absent they get the single id they passed in, which is a
		 * narrower answer rather than a broken one.
		 */
		$repository = $container->get( SubjectRepository::class );

		add_filter(
			'plugixa_exam_subject_subtree_ids',
			static function ( array $ids, int $subject_id ) use ( $repository ): array {
				$subtree = $repository->subtree_ids( $subject_id );

				return array() === $subtree ? $ids : $subtree;
			},
			10,
			2
		);
	}

	/**
	 * @param array<string, string> $strings Accumulated strings.
	 *
	 * @return array<string, string>
	 */
	public function strings( array $strings ): array {
		return array_merge( $strings, Strings::all() );
	}
}
