<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base data access for one table.
 *
 * Lifecycle hooks fire from here rather than from each module's service, so that a listener
 * cannot be defeated by a code path that writes through a different door — the CSV importer
 * and the REST controller reach the same `insert()`.
 */
abstract class Repository {

	/**
	 * Bare table name, e.g. "questions".
	 */
	abstract public function table(): string;

	/**
	 * Entity slug used by hooks and authorisation, e.g. "question".
	 */
	abstract public function entity_type(): string;

	/**
	 * Columns a caller may write.
	 *
	 * An allow-list, not a block-list: a column absent from here cannot be set through any
	 * request, however the payload is shaped. That is what keeps `id`, the audit columns
	 * and anything a service computes out of a client's reach.
	 *
	 * @return string[]
	 */
	abstract public function fillable(): array;

	/**
	 * Whether this table has a `deleted_at` column.
	 *
	 * Join tables and append-only logs do not, and asking a table without one to filter on
	 * it is a SQL error rather than an empty result.
	 */
	public function soft_deletes(): bool {
		return true;
	}

	/**
	 * Columns that may be sorted on.
	 *
	 * @return string[]
	 */
	public function sortable(): array {
		return array( 'id', 'created_at', 'modified_at' );
	}

	/**
	 * Fully-qualified table name.
	 */
	public function full_table(): string {
		return Schema::table( $this->table() );
	}

	/**
	 * A query scoped to this table.
	 */
	public function query(): QueryBuilder {
		return new QueryBuilder( $this->full_table(), $this->entity_type(), $this->soft_deletes() );
	}

	/**
	 * A sort column that is definitely a column.
	 *
	 * Request data never reaches an ORDER BY unescaped; anything unrecognised falls back to
	 * `id` rather than erroring, because a bookmarked list with a stale sort parameter
	 * should still render.
	 *
	 * @param string $column Requested column.
	 */
	public function safe_sort_column( string $column ): string {
		return in_array( $column, $this->sortable(), true ) ? $column : 'id';
	}

	/**
	 * One row by id.
	 *
	 * @param int  $id            Row id.
	 * @param bool $with_trashed  Whether a trashed row counts as found.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id, bool $with_trashed = false ): ?array {
		$query = $this->query()->where( 't.id = %d', array( $id ) );

		if ( $with_trashed ) {
			$query->with_trashed();
		}

		$row = $query->first();

		return null === $row ? null : $this->cast( $row );
	}

	/**
	 * Insert a row.
	 *
	 * @param array<string, mixed> $data Column values; anything not fillable is dropped.
	 *
	 * @return int The new id, or 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$row = $this->filter_fillable( $data );

		$now                 = current_time( 'mysql', true );
		$row['created_at']   = $now;
		$row['modified_at']  = $now;
		$row['created_by']   = get_current_user_id();
		$row['modified_by']  = get_current_user_id();

		/**
		 * Filter a row on its way in.
		 *
		 * @param array<string, mixed> $row         The row about to be written.
		 * @param string               $entity_type Entity slug.
		 */
		$row = (array) apply_filters( 'plugixa_exam_before_insert', $row, $this->entity_type() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->insert prepares.
		$ok = $wpdb->insert( $this->full_table(), $row );

		if ( false === $ok ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after a row is created.
		 *
		 * @param int                  $id          The new id.
		 * @param array<string, mixed> $row         What was written.
		 * @param string               $entity_type Entity slug.
		 */
		do_action( 'plugixa_exam_entity_created', $id, $row, $this->entity_type() );

		return $id;
	}

	/**
	 * Update a row.
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Column values; anything not fillable is dropped.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$row = $this->filter_fillable( $data );

		if ( array() === $row ) {
			return false;
		}

		$row['modified_at'] = current_time( 'mysql', true );
		$row['modified_by'] = get_current_user_id();

		/** This filter is documented in includes/Core/Database/Repository.php */
		$row = (array) apply_filters( 'plugixa_exam_before_update', $row, $this->entity_type() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->update prepares.
		$ok = $wpdb->update( $this->full_table(), $row, array( 'id' => $id ) );

		if ( false === $ok ) {
			return false;
		}

		/**
		 * Fires after a row is updated.
		 *
		 * @param int                  $id          Row id.
		 * @param array<string, mixed> $row         What changed.
		 * @param string               $entity_type Entity slug.
		 */
		do_action( 'plugixa_exam_entity_updated', $id, $row, $this->entity_type() );

		return true;
	}

	/**
	 * Move a row to the trash, or delete it outright when the table has no `deleted_at`.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): bool {
		if ( ! $this->soft_deletes() ) {
			return $this->purge( $id );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->update prepares.
		$ok = $wpdb->update(
			$this->full_table(),
			array(
				'deleted_at'  => current_time( 'mysql', true ),
				'modified_at' => current_time( 'mysql', true ),
				'modified_by' => get_current_user_id(),
			),
			array( 'id' => $id )
		);

		if ( false === $ok ) {
			return false;
		}

		/**
		 * Fires after a row is trashed.
		 *
		 * @param int    $id          Row id.
		 * @param string $entity_type Entity slug.
		 */
		do_action( 'plugixa_exam_entity_deleted', $id, $this->entity_type() );

		return true;
	}

	/**
	 * Bring a trashed row back.
	 *
	 * @param int $id Row id.
	 */
	public function restore( int $id ): bool {
		if ( ! $this->soft_deletes() ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->update prepares.
		$ok = $wpdb->update( $this->full_table(), array( 'deleted_at' => null ), array( 'id' => $id ) );

		if ( false === $ok ) {
			return false;
		}

		/**
		 * Fires after a trashed row is restored.
		 *
		 * @param int    $id          Row id.
		 * @param string $entity_type Entity slug.
		 */
		do_action( 'plugixa_exam_entity_restored', $id, $this->entity_type() );

		return true;
	}

	/**
	 * Delete a row for good.
	 *
	 * @param int $id Row id.
	 */
	public function purge( int $id ): bool {
		global $wpdb;

		/**
		 * Fires before a row is deleted for good, while it can still be read.
		 *
		 * This is where a module cleans up rows that point at another module's record
		 * without either module importing the other.
		 *
		 * @param string $entity_type Entity slug.
		 * @param int    $id          Row id.
		 */
		do_action( 'plugixa_exam_before_purge', $this->entity_type(), $id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->delete prepares.
		$ok = $wpdb->delete( $this->full_table(), array( 'id' => $id ) );

		if ( false === $ok ) {
			return false;
		}

		/**
		 * Fires after a row is deleted for good.
		 *
		 * @param int    $id          Row id.
		 * @param string $entity_type Entity slug.
		 */
		do_action( 'plugixa_exam_entity_purged', $id, $this->entity_type() );

		return true;
	}

	/**
	 * Coerce a row's types on the way out.
	 *
	 * MySQL hands every column back as a string, and `"0"` is truthy in JavaScript — a
	 * client testing `row.published` to decide whether to draw something is right about the
	 * published rows and wrong about the rest. Subclasses override to name their columns.
	 *
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		foreach ( array( 'id', 'created_by', 'modified_by' ) as $column ) {
			if ( isset( $row[ $column ] ) ) {
				$row[ $column ] = (int) $row[ $column ];
			}
		}

		return $row;
	}

	/**
	 * Drop anything the caller may not write.
	 *
	 * @param array<string, mixed> $data Raw input.
	 *
	 * @return array<string, mixed>
	 */
	protected function filter_fillable( array $data ): array {
		return array_intersect_key( $data, array_flip( $this->fillable() ) );
	}

	/**
	 * The audit columns every table in this plugin carries.
	 *
	 * Returned as a SQL fragment so a module's `Schema` cannot spell them differently —
	 * `SchemaTest` asserts every table has them, and a column named `created` in one table
	 * and `created_at` in another is the kind of difference that survives review and then
	 * breaks a generic list component.
	 *
	 * @param bool $soft_deletes Whether to include `deleted_at`.
	 */
	public static function audit_columns( bool $soft_deletes = true ): string {
		$sql = "created_at datetime default NULL,\n\t\t\tmodified_at datetime default NULL,\n\t\t\t"
			. "created_by bigint(20) unsigned NOT NULL default 0,\n\t\t\t"
			. 'modified_by bigint(20) unsigned NOT NULL default 0,';

		if ( $soft_deletes ) {
			$sql .= "\n\t\t\tdeleted_at datetime default NULL,";
		}

		return $sql;
	}
}
