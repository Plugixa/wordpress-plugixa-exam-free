<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Exams;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\Exams\Calendar\ExamCalendarSource;
use PlugixaExam\Modules\Exams\Frontend\ExamBridge;
use PlugixaExam\Modules\Exams\Http\ExamsController;
use PlugixaExam\Modules\Exams\Repositories\ExamRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exams: the composition of a paper and the rules for sitting it.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'exams';
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
		unset( $container );
	}

	public function register_routes( Container $container ): void {
		$container->get( ExamsController::class )->register_routes();
	}

	public function register_hooks( Container $container ): void {
		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );

		// Answers the front-end templates' questions about exams. Core may not name this
		// module, so the templates reach exam data through filters.
		$container->get( ExamBridge::class )->register_hooks();

		// Exam windows on the admin calendar. The Calendar module asks through a filter and
		// never names this one, so a build without it simply gets no answer.
		$container->get( ExamCalendarSource::class )->register_hooks();

		$repository = $container->get( ExamRepository::class );

		add_action(
			'plugixa_exam_before_purge',
			static function ( string $entity_type, int $id ) use ( $repository ): void {
				if ( 'exam' === $entity_type ) {
					$repository->delete_composition( $id );
				}
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
