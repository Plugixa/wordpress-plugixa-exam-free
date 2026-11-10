<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Tags\Repositories;

use PlugixaExam\Core\Database\InList;
use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for tags, and for the question↔tag join.
 */
final class TagRepository extends Repository {

	public function table(): string {
		return 'tags';
	}

	public function entity_type(): string {
		return 'tag';
	}

	/**
	 * @return string[]
	 */
	public function fillable(): array {
		return array( 'title', 'slug', 'published', 'ordering' );
	}

	/**
	 * @return string[]
	 */
	public function sortable(): array {
		return array( 'id', 'title', 'ordering', 'created_at' );
	}

	/**
	 * Every live tag.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		return array_map(
			array( $this, 'cast' ),
			$this->query()->order_by( 't.ordering', 'ASC' )->order_by( 't.title', 'ASC' )->paginate( 1000 )->get()
		);
	}

	/**
	 * Whether a slug is taken.
	 *
	 * Checked in the service rather than enforced by a UNIQUE key, because the table soft
	 * deletes and a UNIQUE index would count trashed rows — permanently reserving the slug
	 * of every tag anybody ever deleted.
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

	/**
	 * Tag ids attached to a question.
	 *
	 * @param int $question_id Question id.
	 *
	 * @return int[]
	 */
	public function tags_for_question( int $question_id ): array {
		global $wpdb;

		$join = Db::table( 'question_tags' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$ids = $wpdb->get_col(
			$wpdb->prepare( 'SELECT tag_id FROM %i WHERE question_id = %d', $join, $question_id )
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Tag ids for many questions at once.
	 *
	 * Exists so a bank list of fifty questions costs one query rather than fifty. The N+1
	 * version is the single easiest performance mistake to make in a list screen, and it
	 * only shows up once somebody has enough questions to notice.
	 *
	 * @param int[] $question_ids Question ids.
	 *
	 * @return array<int, int[]> Tag ids keyed by question id.
	 */
	public function tags_for_questions( array $question_ids ): array {
		[ $lowest, $highest, $set ] = InList::ids( $question_ids );

		if ( '' === $set ) {
			return array();
		}

		global $wpdb;

		// One literal statement whatever the number of questions — see `InList`. The
		// `question_tag (question_id, tag_id)` key serves the range.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT question_id, tag_id FROM %i WHERE question_id BETWEEN %d AND %d AND FIND_IN_SET( question_id, %s )',
				Db::table( 'question_tags' ),
				$lowest,
				$highest,
				$set
			),
			ARRAY_A
		);

		$map = array();

		foreach ( $rows as $row ) {
			$map[ (int) $row['question_id'] ][] = (int) $row['tag_id'];
		}

		return $map;
	}

	/**
	 * Replace a question's tags.
	 *
	 * Delete-then-insert rather than a diff. The set is tiny — a question has a handful of
	 * tags — and a diff would have to be right about ordering, duplicates and the empty
	 * case to save one query.
	 *
	 * @param int   $question_id Question id.
	 * @param int[] $tag_ids     Tag ids.
	 */
	public function set_question_tags( int $question_id, array $tag_ids ): void {
		global $wpdb;

		$join = Db::table( 'question_tags' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom join table; $wpdb->delete prepares.
		$wpdb->delete( $join, array( 'question_id' => $question_id ) );

		$now = current_time( 'mysql', true );

		foreach ( array_unique( array_map( 'intval', $tag_ids ) ) as $tag_id ) {
			if ( $tag_id <= 0 ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom join table; $wpdb->insert prepares.
			$wpdb->insert(
				$join,
				array(
					'question_id' => $question_id,
					'tag_id'      => $tag_id,
					'created_at'  => $now,
				)
			);
		}
	}

	/**
	 * Detach a tag from every question.
	 *
	 * Called when a tag is deleted. Without it the join keeps rows pointing at a tag that
	 * is gone, and a pool rule filtering on that tag silently matches nothing.
	 *
	 * @param int $tag_id Tag id.
	 */
	public function detach_tag( int $tag_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom join table; $wpdb->delete prepares.
		$wpdb->delete( Db::table( 'question_tags' ), array( 'tag_id' => $tag_id ) );
	}

	/**
	 * Detach every tag from a question.
	 *
	 * @param int $question_id Question id.
	 */
	public function detach_question( int $question_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom join table; $wpdb->delete prepares.
		$wpdb->delete( Db::table( 'question_tags' ), array( 'question_id' => $question_id ) );
	}

	/**
	 * How many questions carry each tag.
	 *
	 * @return array<int, int> Count keyed by tag id.
	 */
	public function usage_counts(): array {
		global $wpdb;

		$join = Db::table( 'question_tags' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT tag_id, COUNT(*) AS n FROM %i GROUP BY tag_id', $join ),
			ARRAY_A
		);

		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ (int) $row['tag_id'] ] = (int) $row['n'];
		}

		return $counts;
	}

	/**
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		$row = parent::cast( $row );

		if ( isset( $row['ordering'] ) ) {
			$row['ordering'] = (int) $row['ordering'];
		}

		if ( isset( $row['published'] ) ) {
			$row['published'] = (bool) (int) $row['published'];
		}

		return $row;
	}
}
