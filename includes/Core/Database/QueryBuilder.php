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
 * A small SELECT builder over one table.
 *
 * THE SINGLE CHOKE POINT for two things that must not be optional:
 *
 *  - the soft-delete scope. Every list in the plugin excludes trashed rows unless it asked
 *    not to, because a query that forgets is a query that shows deleted records as live
 *    ones — and nobody reviewing a list notices the rows that should not be there.
 *  - `plugixa_exam_query_clauses`, the seam the Roles module uses to narrow what a user may
 *    see. A rule applied here cannot be forgotten by a controller, and a row-level rule one
 *    controller forgets is a row quietly readable through one route, which looks exactly
 *    like a row that was allowed.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE ONE PLACE IN THE PLUGIN A STATEMENT IS ASSEMBLED AT RUNTIME, AND WHY IT HAS TO BE.
 *
 * Every other query hands `prepare()` one literal — WordPress.org's review judges the
 * argument's form, not what it evaluates to, and plugixa-recipe was rejected for concatenating
 * a hard-coded column list. This class cannot do that: it composes one statement out of the
 * fragments forty call sites and two hooks contribute, so there is no point at which a whole
 * literal exists. Writing literal SQL per call site instead would give up the choke point
 * above, which is where the trash scope and the row-level narrowing live — a worse trade than
 * it looks. plugixa-crm reached the same conclusion about its own builder.
 *
 * What that costs is bounded, and `ArchTest` pins it: exactly two `prepare()` calls here,
 * `get()` and `count()`, and no other non-literal query anywhere in the plugin.
 *
 * WHAT IS TEXT AND WHAT IS BOUND. Bound: every value, the table (`%i`), the page (`%d`), and
 * every list — {@see where_in()} binds three values whatever the list's length. Text: only
 * fragments a code author wrote — select expressions, joins, the `%s`/`%d` half of `where()`,
 * group and order columns, and the ASC/DESC keyword, which `order_by()` normalises. Column and
 * table names never come from request data; identifiers arrive as literals or through
 * `Repository::safe_sort_column()`.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class QueryBuilder {

	/**
	 * @var string[]
	 */
	private array $select = array( 't.*' );

	/**
	 * @var string[]
	 */
	private array $joins = array();

	/**
	 * WHERE fragments, each already carrying its own placeholders.
	 *
	 * @var string[]
	 */
	private array $where = array();

	/**
	 * Values bound to the placeholders in {@see $where}, in order.
	 *
	 * @var array<int, scalar>
	 */
	private array $bindings = array();

	/**
	 * @var string[]
	 */
	private array $group_by = array();

	/**
	 * @var string[]
	 */
	private array $order_by = array();

	private ?int $limit = null;

	private int $offset = 0;

	/**
	 * How trashed rows are treated: exclude them, include them, or show only them.
	 */
	private string $trash_scope = 'live';

	/**
	 * @param string $table       Fully-qualified table name.
	 * @param string $entity_type Entity slug, passed to the clause filter.
	 * @param bool   $soft_deletes Whether the table has a `deleted_at` column.
	 */
	public function __construct(
		private readonly string $table,
		private readonly string $entity_type,
		private readonly bool $soft_deletes = true
	) {}

	/**
	 * @param string ...$columns Column expressions, e.g. `t.id`, `COUNT(*) AS n`.
	 */
	public function select( string ...$columns ): self {
		$this->select = $columns;

		return $this;
	}

	/**
	 * @param string $sql A complete JOIN clause.
	 */
	public function join( string $sql ): self {
		$this->joins[] = $sql;

		return $this;
	}

	/**
	 * Add a WHERE fragment.
	 *
	 * @param string             $sql    Fragment with `%s`/`%d`/`%f` placeholders.
	 * @param array<int, scalar> $values Values for those placeholders.
	 */
	public function where( string $sql, array $values = array() ): self {
		$this->where[]  = $sql;
		$this->bindings = array_merge( $this->bindings, array_values( $values ) );

		return $this;
	}

	/**
	 * Membership in a list, as three bound values whatever the list's length, or a never-true
	 * clause when the list is empty.
	 *
	 * An empty `IN ()` is a MySQL syntax error, and the tempting fix — skipping the clause —
	 * inverts its meaning: "show me the rows in this empty set" becomes "show me everything".
	 *
	 * NOT `IN ( %d, %d, … )` any more. That generated one placeholder per value, so the shape of
	 * the statement depended on the data; {@see InList} explains the replacement and why the
	 * `BETWEEN` half is there for ids. Ids that are not positive and strings containing a comma
	 * are dropped — neither could match a row here — which every caller already guaranteed.
	 *
	 * @param string             $column      Column expression (a literal, never request data).
	 * @param array<int, scalar> $values      Allowed values.
	 * @param string             $placeholder `%d` for ids, `%s` for strings.
	 */
	public function where_in( string $column, array $values, string $placeholder = '%d' ): self {
		if ( array() === $values ) {
			return $this->where( '1 = 0' );
		}

		if ( '%s' === $placeholder ) {
			return $this->where( 'FIND_IN_SET( ' . $column . ', %s )', array( InList::strings( $values ) ) );
		}

		return $this->where(
			$column . ' BETWEEN %d AND %d AND FIND_IN_SET( ' . $column . ', %s )',
			InList::ids( $values )
		);
	}

	/**
	 * Rows where any of the given columns contains a term.
	 *
	 * One parenthesised OR group, AND-ed onto the rest: a record matches a search if its title
	 * OR its code matches, while still having to pass every other filter. A no-op for an empty
	 * term or no columns, so a list with nothing searchable stays a list rather than becoming
	 * empty.
	 *
	 * CALL IT BEFORE `count()`, so the pager counts what the page reads.
	 *
	 * Moved here from `RestController::paged_list()`, which assembled the same group itself —
	 * so that assembling a statement happens in exactly one class.
	 *
	 * @param string   $term    The raw term. Escaped for LIKE here; callers must not pre-wrap it.
	 * @param string[] $columns Author-written column expressions, e.g. `t.title`.
	 */
	public function search( string $term, array $columns ): self {
		global $wpdb;

		if ( '' === $term || array() === $columns ) {
			return $this;
		}

		$like = '%' . $wpdb->esc_like( $term ) . '%';

		return $this->where(
			'(' . implode( ' OR ', array_map( static fn( string $column ): string => $column . ' LIKE %s', $columns ) ) . ')',
			array_fill( 0, count( $columns ), $like )
		);
	}

	/**
	 * @param string $column Column expression.
	 * @param string $dir    `ASC` or `DESC`.
	 */
	public function order_by( string $column, string $dir = 'DESC' ): self {
		$this->order_by[] = $column . ( 'ASC' === strtoupper( $dir ) ? ' ASC' : ' DESC' );

		return $this;
	}

	/**
	 * @param string ...$columns Column expressions.
	 */
	public function group_by( string ...$columns ): self {
		$this->group_by = array_merge( $this->group_by, $columns );

		return $this;
	}

	/**
	 * @param int $limit  Row limit.
	 * @param int $offset Row offset.
	 */
	public function paginate( int $limit, int $offset = 0 ): self {
		$this->limit  = max( 1, $limit );
		$this->offset = max( 0, $offset );

		return $this;
	}

	/**
	 * Include trashed rows alongside live ones.
	 */
	public function with_trashed(): self {
		$this->trash_scope = 'all';

		return $this;
	}

	/**
	 * Show only trashed rows.
	 */
	public function only_trashed(): self {
		$this->trash_scope = 'trashed';

		return $this;
	}

	/**
	 * Run the query.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get(): array {
		global $wpdb;

		[ $sql, $bindings ] = $this->compose( implode( ', ', $this->select ), $this->order_by, $this->limit );

		/*
		 * `PluginCheck.Security.DirectDB.UnescapedDBParameter` is named as well as the WPCS
		 * sniffs, and it has to be: Plugin Check ships it and the WPCS ruleset does not, so a
		 * PHPCS run that reads zero says nothing about what the WordPress.org reviewer will see.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the builder's statement, assembled in compose() from author-written fragments; every value, list and identifier is bound here. See the class docblock for why a builder cannot hand prepare() a literal.
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $bindings ), ARRAY_A );
	}

	/**
	 * The first row, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public function first(): ?array {
		$rows = $this->paginate( 1, $this->offset )->get();

		return $rows[0] ?? null;
	}

	/**
	 * How many rows match, ignoring limit, offset and order.
	 *
	 * ORDER BY on a COUNT is wasted work, and on a grouped query it can reference a column the
	 * aggregate does not expose — so neither is passed on.
	 */
	public function count(): int {
		global $wpdb;

		[ $sql, $bindings ] = $this->compose( 'COUNT(*)', array(), null );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see get().
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $bindings ) );
	}

	/**
	 * The statement and its bindings, in placeholder order. Not prepared: the caller prepares
	 * it at the point of use, so no prepared string is ever held in a variable.
	 *
	 * @param string    $projection What to SELECT.
	 * @param string[]  $order_by   ORDER BY terms.
	 * @param int|null  $limit      Row limit, or null for none.
	 *
	 * @return array{0: string, 1: array<int, scalar>}
	 */
	private function compose( string $projection, array $order_by, ?int $limit ): array {
		$where    = $this->where;
		$bindings = $this->bindings;

		if ( $this->soft_deletes ) {
			if ( 'live' === $this->trash_scope ) {
				$where[] = 't.deleted_at IS NULL';
			} elseif ( 'trashed' === $this->trash_scope ) {
				$where[] = 't.deleted_at IS NOT NULL';
			}
		}

		/**
		 * Narrow any query in the plugin.
		 *
		 * Fired on every list, count and search. A listener returns extra WHERE fragments
		 * and the values bound to them; it must not return unbound request data.
		 *
		 * @param array{where: string[], bindings: array<int, scalar>} $clauses     Current clauses.
		 * @param string                                              $entity_type Entity slug.
		 */
		$clauses = (array) apply_filters(
			'plugixa_exam_query_clauses',
			array(
				'where'    => $where,
				'bindings' => $bindings,
			),
			$this->entity_type
		);

		$where    = (array) ( $clauses['where'] ?? $where );
		$bindings = (array) ( $clauses['bindings'] ?? $bindings );

		/*
		 * `%i` FOR THE TABLE, and its binding goes first because its placeholder does.
		 * Placeholders bind in the order they appear, and this one appears before every WHERE
		 * value. It also means there is always something to bind, so no path returns a
		 * statement that skips `prepare()`.
		 */
		$sql = 'SELECT ' . $projection . ' FROM %i AS t';

		array_unshift( $bindings, $this->table );

		if ( array() !== $this->joins ) {
			$sql .= ' ' . implode( ' ', $this->joins );
		}

		if ( array() !== $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}

		if ( array() !== $this->group_by ) {
			$sql .= ' GROUP BY ' . implode( ', ', $this->group_by );
		}

		if ( array() !== $order_by ) {
			$sql .= ' ORDER BY ' . implode( ', ', $order_by );
		}

		if ( null !== $limit ) {
			$sql       .= ' LIMIT %d OFFSET %d';
			$bindings[] = $limit;
			$bindings[] = $this->offset;
		}

		return array( $sql, $bindings );
	}
}
