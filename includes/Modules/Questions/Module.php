<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Questions;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\Questions\Http\QuestionsController;
use PlugixaExam\Modules\Questions\Repositories\QuestionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The question bank.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'questions';
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
		$container->get( QuestionsController::class )->register_routes();
	}

	public function register_hooks( Container $container ): void {
		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );

		/*
		 * Options and version history follow a question into the bin.
		 *
		 * Hooked on purge rather than delete: a trashed question keeps everything, so
		 * restoring it restores a working question rather than a stem with no answers.
		 */
		$repository = $container->get( QuestionRepository::class );

		add_action(
			'plugixa_exam_before_purge',
			static function ( string $entity_type, int $id ) use ( $repository ): void {
				if ( 'question' !== $entity_type ) {
					return;
				}

				$repository->delete_options( $id );
				$repository->delete_versions( $id );
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
