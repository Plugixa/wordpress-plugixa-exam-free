<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Tags;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\Tags\Http\TagsController;
use PlugixaExam\Modules\Tags\Repositories\TagRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tags — flat, cross-cutting labels on questions.
 *
 * Switchable off. With the app disabled the tag screens disappear and the question editor
 * stops offering the field, but no data is touched: existing tags stay in the database and
 * come back exactly as they were when it is switched on again. That is the App Manager's
 * contract everywhere — disable hides a feature, it does not delete anything.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'tags';
	}

	public function app(): array {
		return array(
			'category' => 'authoring',
			'setting'  => 'app_tags',
			'default'  => true,
			'deps'     => array(),
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
		$container->get( TagsController::class )->register_routes();
	}

	public function register_hooks( Container $container ): void {
		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );
		add_filter( 'plugixa_exam_settings_defaults', array( $this, 'settings_defaults' ) );

		/*
		 * Clean up the join when a question is deleted for good.
		 *
		 * Tags depends on Questions and never the other way round: nothing in the Questions
		 * module mentions tags. This listener is how the dependency is expressed — the
		 * dependent module does the cleaning up, so removing Tags removes its cleanup too.
		 */
		$repository = $container->get( TagRepository::class );

		add_action(
			'plugixa_exam_before_purge',
			static function ( string $entity_type, int $id ) use ( $repository ): void {
				if ( 'question' === $entity_type ) {
					$repository->detach_question( $id );
				}
			},
			10,
			2
		);

		// Attach `tag_ids` to a single question, for the editor.
		add_filter(
			'plugixa_exam_question_output',
			static function ( array $question ) use ( $repository ): array {
				$question['tag_ids'] = $repository->tags_for_question( (int) $question['id'] );

				return $question;
			}
		);

		// And to a whole list page — in ONE query, not one per row. The N+1 version of this
		// is the easiest performance mistake to make in a list screen, and it only becomes
		// visible once somebody has enough questions to care.
		add_filter(
			'plugixa_exam_question_list_output',
			static function ( array $rows ) use ( $repository ): array {
				$map = $repository->tags_for_questions(
					array_map( static fn( array $row ): int => (int) $row['id'], $rows )
				);

				foreach ( $rows as $index => $row ) {
					$rows[ $index ]['tag_ids'] = $map[ (int) $row['id'] ] ?? array();
				}

				return $rows;
			}
		);

		// Persist the editor's tag selection. Reads the RAW input, because `tag_ids` is not
		// a question column and the Questions module drops it before writing.
		add_action(
			'plugixa_exam_question_saved',
			static function ( int $question_id, array $input ) use ( $repository ): void {
				if ( ! array_key_exists( 'tag_ids', $input ) ) {
					return;
				}

				$repository->set_question_tags( $question_id, array_map( 'absint', (array) $input['tag_ids'] ) );
			},
			10,
			2
		);
	}

	/**
	 * @param array<string, mixed> $defaults Accumulated defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_defaults( array $defaults ): array {
		return array_merge( $defaults, array( 'app_tags' => true ) );
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
				'tags'            => __( 'Tags', 'plugixa-exam' ),
				'tagsDesc' => __( 'Flat labels on questions, for finding them across subjects.', 'plugixa-exam' ),
				'tag'             => __( 'Tag', 'plugixa-exam' ),
				'newTag'          => __( 'New tag', 'plugixa-exam' ),
				'tagName'         => __( 'Name', 'plugixa-exam' ),
				'tagsEmpty'       => __( 'No tags yet. Tags let a random-draw section narrow which questions it can pull.', 'plugixa-exam' ),
				'tagsIntro'       => __( 'Tags are flat labels that cut across subjects: “calculator allowed”, “past paper”, “diagram”.', 'plugixa-exam' ),
				'tagUsage'        => __( 'Used by', 'plugixa-exam' ),
				'tagDeleteWarn'   => __( 'Deleting a tag removes it from every question that carries it.', 'plugixa-exam' ),
			)
		);
	}
}
