<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Questions\Repositories;

use PlugixaExam\Core\Database\InList;
use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for questions, their options and their version snapshots.
 */
final class QuestionRepository extends Repository {

	public function table(): string {
		return 'questions';
	}

	public function entity_type(): string {
		return 'question';
	}

	/**
	 * @return string[]
	 */
	public function fillable(): array {
		return array(
			'subject_id',
			'question_type',
			'title',
			'slug',
			'stem',
			'difficulty',
			'default_points',
			'answer_key',
			'explanation',
			'shuffle_options',
			'published',
			'version',
			'approval_status',
			'rubric',
			'custom_fields',
			'render_hint',
		);
	}

	/**
	 * @return string[]
	 */
	public function sortable(): array {
		return array( 'id', 'title', 'question_type', 'difficulty', 'default_points', 'created_at', 'modified_at' );
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

	/* ---------------------------------------------------------------- options */

	/**
	 * A question's options, in display order.
	 *
	 * @param int $question_id Question id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function options( int $question_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE question_id = %d ORDER BY ordering ASC',
				Db::table( 'question_options' ),
				$question_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast_option' ), $rows );
	}

	/**
	 * Options for many questions at once.
	 *
	 * Exists so a bank list showing answer counts costs one query rather than one per row.
	 *
	 * @param int[] $question_ids Question ids.
	 *
	 * @return array<int, array<int, array<string, mixed>>> Options keyed by question id.
	 */
	public function options_for( array $question_ids ): array {
		[ $lowest, $highest, $set ] = InList::ids( $question_ids );

		if ( '' === $set ) {
			return array();
		}

		global $wpdb;

		// One literal statement whatever the number of questions — see `InList`.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE question_id BETWEEN %d AND %d AND FIND_IN_SET( question_id, %s ) ORDER BY question_id ASC, ordering ASC',
				Db::table( 'question_options' ),
				$lowest,
				$highest,
				$set
			),
			ARRAY_A
		);

		$map = array();

		foreach ( $rows as $row ) {
			$map[ (int) $row['question_id'] ][] = $this->cast_option( $row );
		}

		return $map;
	}

	/**
	 * Replace a question's options.
	 *
	 * DELETE-THEN-INSERT, AND THAT HAS A CONSEQUENCE WORTH KNOWING: option ids change on
	 * every save. An attempt records the ids it was shown, so editing a question invalidates
	 * the stored responses of anybody already sitting it. That is survivable only because an
	 * attempt freezes its question set at start and reviews fall back to the version
	 * snapshot — see `Schema::question_versions()`.
	 *
	 * The alternative, diffing by id, would keep ids stable but has to be right about
	 * reordering, insertion in the middle, and deletion — for a set that is almost always
	 * fewer than six rows.
	 *
	 * @param int                               $question_id Question id.
	 * @param array<int, array<string, mixed>>  $options     Options in display order.
	 */
	public function replace_options( int $question_id, array $options ): void {
		global $wpdb;

		$table = Db::table( 'question_options' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->delete prepares.
		$wpdb->delete( $table, array( 'question_id' => $question_id ) );

		foreach ( array_values( $options ) as $position => $option ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->insert prepares.
			$wpdb->insert(
				$table,
				array(
					'question_id' => $question_id,
					'ordering'    => $position + 1,
					'option_text' => (string) ( $option['option_text'] ?? '' ),
					'is_correct'  => empty( $option['is_correct'] ) ? 0 : 1,
					'match_key'   => isset( $option['match_key'] ) && '' !== $option['match_key']
						? (string) $option['match_key']
						: null,
					'feedback'    => isset( $option['feedback'] ) && '' !== $option['feedback']
						? (string) $option['feedback']
						: null,
				)
			);
		}
	}

	/**
	 * Delete every option belonging to a question.
	 *
	 * @param int $question_id Question id.
	 */
	public function delete_options( int $question_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->delete prepares.
		$wpdb->delete( Db::table( 'question_options' ), array( 'question_id' => $question_id ) );
	}

	/* --------------------------------------------------------------- versions */

	/**
	 * Freeze a question's current content.
	 *
	 * @param int                  $question_id Question id.
	 * @param int                  $version     Version number.
	 * @param array<string, mixed> $snapshot    The frozen content.
	 */
	public function add_version( int $question_id, int $version, array $snapshot ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->insert prepares.
		$wpdb->insert(
			Db::table( 'question_versions' ),
			array(
				'question_id' => $question_id,
				'version'     => $version,
				'snapshot'    => (string) wp_json_encode( $snapshot ),
				'created_at'  => current_time( 'mysql', true ),
				'created_by'  => get_current_user_id(),
			)
		);
	}

	/**
	 * A question's version history, newest first.
	 *
	 * @param int $question_id Question id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function versions( int $question_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, version, created_at, created_by, snapshot FROM %i WHERE question_id = %d ORDER BY version DESC',
				Db::table( 'question_versions' ),
				$question_id
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$row['id']       = (int) $row['id'];
				$row['version']  = (int) $row['version'];
				$row['snapshot'] = json_decode( (string) $row['snapshot'], true ) ?: array();

				return $row;
			},
			$rows
		);
	}

	/**
	 * Delete a question's version history.
	 *
	 * @param int $question_id Question id.
	 */
	public function delete_versions( int $question_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->delete prepares.
		$wpdb->delete( Db::table( 'question_versions' ), array( 'question_id' => $question_id ) );
	}

	/* ------------------------------------------------------------------ casts */

	/**
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		$row = parent::cast( $row );

		foreach ( array( 'subject_id', 'difficulty', 'version' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		if ( isset( $row['default_points'] ) ) {
			$row['default_points'] = (float) $row['default_points'];
		}

		foreach ( array( 'published', 'shuffle_options' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (bool) (int) $row[ $column ];
			}
		}

		// JSON columns come back as strings. Decoded here so no caller has to remember —
		// and a caller that forgets gets a string where it expects an array, which fails
		// somewhere far from the cause.
		foreach ( array( 'answer_key', 'rubric', 'custom_fields', 'render_hint' ) as $column ) {
			if ( ! array_key_exists( $column, $row ) ) {
				continue;
			}

			$decoded       = json_decode( (string) $row[ $column ], true );
			$row[ $column ] = is_array( $decoded ) ? $decoded : null;
		}

		return $row;
	}

	/**
	 * @param array<string, mixed> $row Raw option row.
	 *
	 * @return array<string, mixed>
	 */
	private function cast_option( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'question_id' => (int) $row['question_id'],
			'ordering'    => (int) $row['ordering'],
			'option_text' => (string) $row['option_text'],
			'is_correct'  => (bool) (int) $row['is_correct'],
			'match_key'   => $row['match_key'],
			'feedback'    => $row['feedback'],
		);
	}
}
