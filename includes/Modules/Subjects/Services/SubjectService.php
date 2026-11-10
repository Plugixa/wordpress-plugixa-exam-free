<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Subjects\Services;

use PlugixaExam\Modules\Subjects\Repositories\SubjectRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Subject business logic.
 *
 * All of it lives here rather than in the controller, so that the CSV importer, the demo
 * seeder and REST all reach the same code — and so that the path invariant has exactly one
 * place it can be broken.
 */
final class SubjectService {

	/**
	 * How deep the tree may go.
	 *
	 * `path` is varchar(400). At a realistic 8 characters per level that is ~50 levels of
	 * headroom, so 10 is not a storage limit — it is a usability one. A subject tree deeper
	 * than ten levels is unnavigable in a picker, and the depth is nearly always a sign
	 * that tags were wanted instead.
	 */
	private const MAX_DEPTH = 10;

	/**
	 * @param SubjectRepository $subjects The repository.
	 */
	public function __construct( private readonly SubjectRepository $subjects ) {}

	/**
	 * The whole tree, nested.
	 *
	 * Assembled from one flat query rather than by recursing: the repository returns rows
	 * in path order, which is already depth-first, so a single pass with an id-keyed index
	 * builds the tree. Recursion here would be one query per node.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function tree(): array {
		$rows = $this->subjects->all_ordered();

		$index = array();
		foreach ( $rows as $row ) {
			$row['children']          = array();
			$index[ (int) $row['id'] ] = $row;
		}

		$tree = array();

		// Reference-walk: children are appended to the parent that is already in $index, so
		// a node added later still shows up under a parent processed earlier.
		foreach ( array_keys( $index ) as $id ) {
			$parent_id = (int) $index[ $id ]['parent_id'];

			if ( 0 !== $parent_id && isset( $index[ $parent_id ] ) ) {
				$index[ $parent_id ]['children'][] = &$index[ $id ];
			} else {
				$tree[] = &$index[ $id ];
			}
		}

		unset( $index );

		return $tree;
	}

	/**
	 * Create a subject.
	 *
	 * @param array<string, mixed> $input Raw input.
	 *
	 * @return int|WP_Error The new id.
	 */
	public function create( array $input ): int|WP_Error {
		$data = $this->sanitize( $input );

		if ( $data instanceof WP_Error ) {
			return $data;
		}

		$parent_id = (int) $data['parent_id'];
		$parent    = 0 === $parent_id ? null : $this->subjects->find( $parent_id );

		if ( 0 !== $parent_id && null === $parent ) {
			return new WP_Error(
				'plugixa_exam_invalid_parent',
				__( 'That parent subject does not exist.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		$level = null === $parent ? 1 : (int) $parent['level'] + 1;

		if ( $level > self::MAX_DEPTH ) {
			return new WP_Error(
				'plugixa_exam_too_deep',
				sprintf(
					/* translators: %d: maximum nesting depth. */
					__( 'Subjects can only be nested %d levels deep.', 'plugixa-exam' ),
					self::MAX_DEPTH
				),
				array( 'status' => 400 )
			);
		}

		$data['level']    = $level;
		$data['ordering'] = $this->subjects->next_ordering( $parent_id );

		// The path needs the row's own id, which does not exist until the insert. Written
		// blank, then filled in — the alternative is reserving an id, which no portable
		// WordPress code can do.
		$data['path'] = '';

		$id = $this->subjects->insert( $data );

		if ( 0 === $id ) {
			return new WP_Error(
				'plugixa_exam_insert_failed',
				__( 'The subject could not be saved.', 'plugixa-exam' ),
				array( 'status' => 500 )
			);
		}

		$this->subjects->update( $id, array( 'path' => $this->path_for( $parent, $id ) ) );

		return $id;
	}

	/**
	 * Update a subject.
	 *
	 * @param int                  $id    Subject id.
	 * @param array<string, mixed> $input Raw input.
	 *
	 * @return true|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		$existing = $this->subjects->find( $id );

		if ( null === $existing ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Subject not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		$data = $this->sanitize( $input, $id );

		if ( $data instanceof WP_Error ) {
			return $data;
		}

		$new_parent = (int) $data['parent_id'];

		if ( $new_parent !== (int) $existing['parent_id'] ) {
			$moved = $this->move( $id, $new_parent );

			if ( $moved instanceof WP_Error ) {
				return $moved;
			}
		}

		// The move has already written parent_id, level, path and ordering. Letting the
		// generic update write them again from $data would undo it with stale values.
		unset( $data['parent_id'], $data['level'], $data['path'], $data['ordering'] );

		$this->subjects->update( $id, $data );

		return true;
	}

	/**
	 * Re-parent a subject and rewrite its subtree's paths.
	 *
	 * @param int $id        Subject to move.
	 * @param int $parent_id New parent, 0 for root.
	 *
	 * @return true|WP_Error
	 */
	public function move( int $id, int $parent_id ): bool|WP_Error {
		$subject = $this->subjects->find( $id );

		if ( null === $subject ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Subject not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		$parent = 0 === $parent_id ? null : $this->subjects->find( $parent_id );

		if ( 0 !== $parent_id && null === $parent ) {
			return new WP_Error( 'plugixa_exam_invalid_parent', __( 'That parent subject does not exist.', 'plugixa-exam' ), array( 'status' => 400 ) );
		}

		/*
		 * A subject cannot be moved inside its own subtree. Without this check the pair
		 * becomes unreachable from any root — invisible in the tree, still attached to its
		 * questions, and recoverable only from the database.
		 */
		if ( null !== $parent && in_array( (int) $parent['id'], $this->subjects->subtree_ids( $id ), true ) ) {
			return new WP_Error(
				'plugixa_exam_circular_parent',
				__( 'A subject cannot be moved inside itself.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		$old_path  = (string) $subject['path'];
		$new_path  = $this->path_for( $parent, $id );
		$new_level = null === $parent ? 1 : (int) $parent['level'] + 1;
		$shift     = $new_level - (int) $subject['level'];

		if ( $new_level > self::MAX_DEPTH ) {
			return new WP_Error(
				'plugixa_exam_too_deep',
				sprintf(
					/* translators: %d: maximum nesting depth. */
					__( 'Subjects can only be nested %d levels deep.', 'plugixa-exam' ),
					self::MAX_DEPTH
				),
				array( 'status' => 400 )
			);
		}

		$this->subjects->update(
			$id,
			array(
				'parent_id' => $parent_id,
				'level'     => $new_level,
				'path'      => $new_path,
				'ordering'  => $this->subjects->next_ordering( $parent_id ),
			)
		);

		// Descendants keep their relative position; only the prefix and depth change.
		foreach ( $this->subjects->subtree_ids( $id ) as $descendant_id ) {
			if ( $descendant_id === $id ) {
				continue;
			}

			$descendant = $this->subjects->find( $descendant_id );

			if ( null === $descendant ) {
				continue;
			}

			$this->subjects->update(
				$descendant_id,
				array(
					'path'  => $new_path . substr( (string) $descendant['path'], strlen( $old_path ) ),
					'level' => (int) $descendant['level'] + $shift,
				)
			);
		}

		return true;
	}

	/**
	 * Trash a subject.
	 *
	 * @param int $id Subject id.
	 *
	 * @return true|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		$children = $this->subjects->children( $id );

		/*
		 * Refused rather than cascaded. Deleting a subject that still has children would
		 * either orphan them — invisible in the tree, still attached to their questions — or
		 * silently delete a whole branch from a single click on a leaf-looking row. Asking
		 * the user to empty it first is the only option that cannot lose work.
		 */
		if ( array() !== $children ) {
			return new WP_Error(
				'plugixa_exam_has_children',
				__( 'Move or delete this subject’s child subjects first.', 'plugixa-exam' ),
				array( 'status' => 409 )
			);
		}

		$this->subjects->delete( $id );

		return true;
	}

	/**
	 * Reorder siblings.
	 *
	 * @param int[] $ids Subject ids in their new order.
	 */
	public function reorder( array $ids ): void {
		foreach ( array_values( $ids ) as $position => $id ) {
			$this->subjects->update( (int) $id, array( 'ordering' => $position + 1 ) );
		}
	}

	/**
	 * Validate and coerce input.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param int                  $exclude Row being edited, for uniqueness checks.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function sanitize( array $input, int $exclude = 0 ): array|WP_Error {
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );

		if ( '' === trim( $title ) ) {
			return new WP_Error(
				'plugixa_exam_title_required',
				__( 'A subject needs a title.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );

		if ( '' === $slug ) {
			$slug = sanitize_title( $title );
		}

		$slug = $this->unique_slug( $slug, $exclude );

		$code = sanitize_text_field( (string) ( $input['code'] ?? '' ) );

		if ( '' !== $code && $this->subjects->code_taken( $code, $exclude ) ) {
			return new WP_Error(
				'plugixa_exam_code_taken',
				__( 'Another subject already uses that code.', 'plugixa-exam' ),
				array( 'status' => 409 )
			);
		}

		return array(
			'title'       => $title,
			'slug'        => $slug,
			'code'        => '' === $code ? null : $code,
			// The description is authored HTML shown above a question list. `wp_kses` with
			// the plugin's own allow-list, not `wp_kses_post`, which permits iframes.
			'description' => wp_kses( (string) ( $input['description'] ?? '' ), plugixa_exam_allowed_html( 'stem' ) ),
			'parent_id'   => absint( $input['parent_id'] ?? 0 ),
			'published'   => empty( $input['published'] ) ? 0 : 1,
		);
	}

	/**
	 * A slug nothing else is using, by appending `-2`, `-3`, …
	 *
	 * @param string $slug    Base slug.
	 * @param int    $exclude Row being edited.
	 */
	private function unique_slug( string $slug, int $exclude ): string {
		$candidate = $slug;
		$suffix    = 2;

		while ( $this->subjects->slug_taken( $candidate, $exclude ) ) {
			$candidate = $slug . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * The materialised path for a row under a parent.
	 *
	 * @param array<string, mixed>|null $parent The parent row, or null for a root.
	 * @param int                       $id     The row's own id.
	 */
	private function path_for( ?array $parent, int $id ): string {
		return null === $parent ? (string) $id : $parent['path'] . '/' . $id;
	}
}
