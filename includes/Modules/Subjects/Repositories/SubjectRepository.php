<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Subjects\Repositories;

use PlugixaExam\Core\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for subjects.
 */
final class SubjectRepository extends Repository {

	public function table(): string {
		return 'subjects';
	}

	public function entity_type(): string {
		return 'subject';
	}

	/**
	 * @return string[]
	 */
	public function fillable(): array {
		return array(
			'parent_id',
			'path',
			'level',
			'code',
			'title',
			'slug',
			'description',
			'ordering',
			'published',
		);
	}

	/**
	 * @return string[]
	 */
	public function sortable(): array {
		return array( 'id', 'title', 'code', 'ordering', 'created_at', 'modified_at' );
	}

	/**
	 * Every live subject, ordered for tree assembly.
	 *
	 * Ordered by `path` rather than by `parent_id, ordering`, because a path sort already
	 * produces depth-first order — a parent always sorts immediately before its children.
	 * The tree builder can then walk the list once instead of recursing.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all_ordered(): array {
		return array_map(
			array( $this, 'cast' ),
			$this->query()->order_by( 't.path', 'ASC' )->paginate( 5000 )->get()
		);
	}

	/**
	 * Ids of a subject and everything beneath it.
	 *
	 * The reason the path exists. A pool rule saying "any question in Mathematics" means
	 * Algebra and Geometry too, and this is what turns that into a single indexed query.
	 *
	 * @param int $id Subject id.
	 *
	 * @return int[]
	 */
	public function subtree_ids( int $id ): array {
		$subject = $this->find( $id );

		if ( null === $subject || '' === (string) $subject['path'] ) {
			return array();
		}

		$path = (string) $subject['path'];

		$rows = $this->query()
			->select( 't.id' )
			->where( '(t.path = %s OR t.path LIKE %s)', array( $path, $path . '/%' ) )
			->paginate( 5000 )
			->get();

		return array_map( static fn( array $row ): int => (int) $row['id'], $rows );
	}

	/**
	 * Direct children of a subject.
	 *
	 * @param int $parent_id Parent id, 0 for roots.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function children( int $parent_id ): array {
		return array_map(
			array( $this, 'cast' ),
			$this->query()
				->where( 't.parent_id = %d', array( $parent_id ) )
				->order_by( 't.ordering', 'ASC' )
				->paginate( 1000 )
				->get()
		);
	}

	/**
	 * Whether a slug is already taken by a live subject.
	 *
	 * Uniqueness is enforced here rather than by a UNIQUE key, because the table soft
	 * deletes: a UNIQUE index would count trashed rows, so deleting "Algebra" would
	 * permanently reserve its slug and creating it again would fail with a database error
	 * nobody can act on.
	 *
	 * @param string $slug    Candidate slug.
	 * @param int    $exclude Row to ignore (the one being edited).
	 */
	public function slug_taken( string $slug, int $exclude = 0 ): bool {
		return null !== $this->query()
			->select( 't.id' )
			->where( 't.slug = %s', array( $slug ) )
			->where( 't.id <> %d', array( $exclude ) )
			->first();
	}

	/**
	 * Whether a code is already taken by a live subject.
	 *
	 * @param string $code    Candidate code.
	 * @param int    $exclude Row to ignore.
	 */
	public function code_taken( string $code, int $exclude = 0 ): bool {
		return null !== $this->query()
			->select( 't.id' )
			->where( 't.code = %s', array( $code ) )
			->where( 't.id <> %d', array( $exclude ) )
			->first();
	}

	/**
	 * The next ordering value among a parent's children.
	 *
	 * @param int $parent_id Parent id.
	 */
	public function next_ordering( int $parent_id ): int {
		$row = $this->query()
			->select( 'COALESCE(MAX(t.ordering), 0) AS max_order' )
			->where( 't.parent_id = %d', array( $parent_id ) )
			->first();

		return (int) ( $row['max_order'] ?? 0 ) + 1;
	}

	/**
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		$row = parent::cast( $row );

		foreach ( array( 'parent_id', 'level', 'ordering' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		if ( isset( $row['published'] ) ) {
			$row['published'] = (bool) (int) $row['published'];
		}

		return $row;
	}
}
