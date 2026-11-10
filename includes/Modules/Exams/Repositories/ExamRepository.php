<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Exams\Repositories;

use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for exams and their composition.
 */
final class ExamRepository extends Repository {

	/**
	 * Columns holding a boolean, cast on the way out.
	 *
	 * @var string[]
	 */
	private const BOOL_COLUMNS = array(
		'shuffle_questions',
		'shuffle_options',
		'show_answers',
		'review_before_submit',
		'allow_early_finish',
		'allow_pause',
		'auto_close_held',
		'track_integrity',
		'published',
	);

	/**
	 * Columns holding an integer.
	 *
	 * @var string[]
	 */
	private const INT_COLUMNS = array(
		'subject_id',
		'time_limit',
		'max_attempts',
		'cooldown_minutes',
		'max_pauses',
		'max_pause_minutes',
		'idle_timeout_minutes',
		'certificate_template_id',
	);

	public function table(): string {
		return 'exams';
	}

	public function entity_type(): string {
		return 'exam';
	}

	/**
	 * @return string[]
	 */
	public function fillable(): array {
		return array(
			'title', 'slug', 'description', 'intro', 'subject_id',
			'time_limit', 'max_attempts', 'cooldown_minutes', 'open_from', 'open_until',
			'pass_mark', 'shuffle_questions', 'shuffle_options',
			'access_type', 'access_level', 'access_code',
			'result_release', 'release_at', 'show_answers', 'pass_message', 'fail_message',
			'lock_policy', 'attempt_mode', 'practice_reveal',
			'layout', 'review_before_submit', 'allow_early_finish', 'fullscreen_mode', 'require_answer',
			'allow_pause', 'max_pauses', 'max_pause_minutes',
			'auto_close_action', 'idle_timeout_minutes', 'window_close_action',
			'auto_close_held', 'auto_close_time_spent', 'auto_close_notify',
			'track_integrity', 'certificate_template_id',
			'settings', 'custom_fields', 'published',
		);
	}

	/**
	 * @return string[]
	 */
	public function sortable(): array {
		return array( 'id', 'title', 'open_from', 'open_until', 'created_at', 'modified_at' );
	}

	/**
	 * An exam by slug, for the front-end router.
	 *
	 * @param string $slug Exam slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_slug( string $slug ): ?array {
		$row = $this->query()->where( 't.slug = %s', array( $slug ) )->first();

		return null === $row ? null : $this->cast( $row );
	}

	/**
	 * Whether a slug is taken.
	 *
	 * @param string $slug    Candidate slug.
	 * @param int    $exclude Row to ignore.
	 */
	public function slug_taken( string $slug, int $exclude = 0 ): bool {
		return null !== $this->query()
			->select( 't.id' )
			->where( 't.slug = %s', array( $slug ) )
			->where( 't.id <> %d', array( $exclude ) )
			->first();
	}

	/* --------------------------------------------------------------- sections */

	/**
	 * An exam's sections, in order.
	 *
	 * @param int $exam_id Exam id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function sections( int $exam_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE exam_id = %d ORDER BY ordering ASC, id ASC',
				Db::table( 'exam_sections' ),
				$exam_id
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$row['id']                  = (int) $row['id'];
				$row['exam_id']             = (int) $row['exam_id'];
				$row['ordering']            = (int) $row['ordering'];
				$row['points_per_question'] = null === $row['points_per_question']
					? null
					: (float) $row['points_per_question'];

				return $row;
			},
			$rows
		);
	}

	/**
	 * Replace an exam's whole composition.
	 *
	 * DELETE-THEN-INSERT, for the same reason `question_options` does it: the builder sends
	 * the complete intended shape, and diffing three related tables to save a handful of
	 * writes is a great deal of code that has to be right about reordering, insertion and
	 * removal in all three at once.
	 *
	 * Section ids therefore change on every save. That is safe because an attempt freezes
	 * its own question set at start and never reads the exam's composition again — see
	 * `AttemptService::start()`.
	 *
	 * @param int                              $exam_id  Exam id.
	 * @param array<int, array<string, mixed>> $sections Sections, each with `questions` and `rules`.
	 */
	public function replace_composition( int $exam_id, array $sections ): void {
		global $wpdb;

		$sections_table = Db::table( 'exam_sections' );
		$questions_table = Db::table( 'exam_questions' );
		$rules_table     = Db::table( 'exam_pool_rules' );

		foreach ( array( $questions_table, $rules_table, $sections_table ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables; $wpdb->delete prepares.
			$wpdb->delete( $table, array( 'exam_id' => $exam_id ) );
		}

		foreach ( array_values( $sections ) as $position => $section ) {
			$type = 'pool' === ( $section['section_type'] ?? 'fixed' ) ? 'pool' : 'fixed';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->insert prepares.
			$wpdb->insert(
				$sections_table,
				array(
					'exam_id'             => $exam_id,
					'title'               => (string) ( $section['title'] ?? '' ),
					'description'         => (string) ( $section['description'] ?? '' ),
					'ordering'            => $position + 1,
					'section_type'        => $type,
					'points_per_question' => isset( $section['points_per_question'] ) && '' !== $section['points_per_question']
						? (float) $section['points_per_question']
						: null,
				)
			);

			$section_id = (int) $wpdb->insert_id;

			if ( 'fixed' === $type ) {
				$seen = array();

				foreach ( array_values( (array) ( $section['questions'] ?? array() ) ) as $index => $entry ) {
					$question_id = absint( is_array( $entry ) ? ( $entry['question_id'] ?? 0 ) : $entry );

					// The UNIQUE key would reject a repeat with a database error the builder
					// cannot explain. Dropping it silently is right here: the same question
					// twice in one section is never intended.
					if ( 0 === $question_id || isset( $seen[ $question_id ] ) ) {
						continue;
					}

					$seen[ $question_id ] = true;

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->insert prepares.
					$wpdb->insert(
						$questions_table,
						array(
							'exam_id'     => $exam_id,
							'section_id'  => $section_id,
							'question_id' => $question_id,
							'ordering'    => $index + 1,
							'points'      => is_array( $entry ) && isset( $entry['points'] ) && '' !== $entry['points']
								? (float) $entry['points']
								: null,
						)
					);
				}

				continue;
			}

			foreach ( (array) ( $section['rules'] ?? array() ) as $rule ) {
				if ( ! is_array( $rule ) ) {
					continue;
				}

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->insert prepares.
				$wpdb->insert(
					$rules_table,
					array(
						'exam_id'             => $exam_id,
						'section_id'          => $section_id,
						'subject_id'          => empty( $rule['subject_id'] ) ? null : absint( $rule['subject_id'] ),
						'difficulty'          => empty( $rule['difficulty'] ) ? null : absint( $rule['difficulty'] ),
						'question_type'       => empty( $rule['question_type'] ) ? null : sanitize_key( (string) $rule['question_type'] ),
						'draw_count'          => max( 1, absint( $rule['draw_count'] ?? 1 ) ),
						'points_per_question' => isset( $rule['points_per_question'] ) && '' !== $rule['points_per_question']
							? (float) $rule['points_per_question']
							: null,
						'filter_json'         => isset( $rule['tag_ids'] ) && array() !== (array) $rule['tag_ids']
							? (string) wp_json_encode( array( 'tags' => array_map( 'absint', (array) $rule['tag_ids'] ) ) )
							: null,
					)
				);
			}
		}
	}

	/**
	 * Questions explicitly placed in a section.
	 *
	 * @param int $section_id Section id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function section_questions( int $section_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE section_id = %d ORDER BY ordering ASC, id ASC',
				Db::table( 'exam_questions' ),
				$section_id
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$row['id']          = (int) $row['id'];
				$row['question_id'] = (int) $row['question_id'];
				$row['section_id']  = (int) $row['section_id'];
				$row['ordering']    = (int) $row['ordering'];
				$row['points']      = null === $row['points'] ? null : (float) $row['points'];

				return $row;
			},
			$rows
		);
	}

	/**
	 * Draw rules for a section.
	 *
	 * @param int $section_id Section id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function section_rules( int $section_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE section_id = %d ORDER BY id ASC',
				Db::table( 'exam_pool_rules' ),
				$section_id
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$decoded = json_decode( (string) $row['filter_json'], true );

				return array(
					'id'                  => (int) $row['id'],
					'section_id'          => (int) $row['section_id'],
					'subject_id'          => null === $row['subject_id'] ? null : (int) $row['subject_id'],
					'difficulty'          => null === $row['difficulty'] ? null : (int) $row['difficulty'],
					'question_type'       => $row['question_type'],
					'draw_count'          => (int) $row['draw_count'],
					'points_per_question' => null === $row['points_per_question'] ? null : (float) $row['points_per_question'],
					'tag_ids'             => is_array( $decoded ) ? array_map( 'intval', (array) ( $decoded['tags'] ?? array() ) ) : array(),
				);
			},
			$rows
		);
	}

	/**
	 * Remove an exam's whole composition. Called when the exam is purged.
	 *
	 * @param int $exam_id Exam id.
	 */
	public function delete_composition( int $exam_id ): void {
		global $wpdb;

		foreach ( array( 'exam_questions', 'exam_pool_rules', 'exam_sections' ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables; $wpdb->delete prepares.
			$wpdb->delete( Db::table( $table ), array( 'exam_id' => $exam_id ) );
		}
	}

	/**
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		$row = parent::cast( $row );

		foreach ( self::INT_COLUMNS as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		foreach ( self::BOOL_COLUMNS as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (bool) (int) $row[ $column ];
			}
		}

		if ( isset( $row['pass_mark'] ) ) {
			$row['pass_mark'] = (float) $row['pass_mark'];
		}

		foreach ( array( 'settings', 'custom_fields' ) as $column ) {
			if ( ! array_key_exists( $column, $row ) ) {
				continue;
			}

			$decoded        = json_decode( (string) $row[ $column ], true );
			$row[ $column ] = is_array( $decoded ) ? $decoded : null;
		}

		// The access code is a secret. It is checked server-side by AccessGate and must
		// never travel to a client — including the admin one, where it would sit in a React
		// prop that anybody can read out of the page.
		unset( $row['access_code'] );

		return $row;
	}

	/**
	 * The access code, read explicitly.
	 *
	 * Separate from `cast()` because `cast()` strips it. A caller that needs the code has to
	 * ask for it by name, which makes every such call visible in a grep.
	 *
	 * @param int $exam_id Exam id.
	 */
	public function access_code( int $exam_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		return (string) $wpdb->get_var(
			$wpdb->prepare( 'SELECT access_code FROM %i WHERE id = %d', $this->full_table(), $exam_id )
		);
	}
}
