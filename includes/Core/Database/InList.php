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
 * The bound VALUES that stand in for an `IN ( … )` list, so the statement around them can be a
 * literal.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS. WordPress.org's review reads a query as unsafe unless the string handed to
 * `prepare()` is ONE literal, and a list is the one clause whose width is not known when the
 * code is written. The previous answer here — `plugixa_exam_placeholders()`, a run of `%d`
 * generated from a count and interpolated in — bound every value and was still the pattern the
 * review rejects, because it judges the argument's FORM, not what it evaluates to. plugixa-recipe
 * 1.0.2 was rejected for concatenating a hard-coded column list. So the literal says instead:
 *
 *     WHERE question_id BETWEEN %d AND %d AND FIND_IN_SET( question_id, %s )
 *
 * with three values whose number does not depend on how many ids there are.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * WHY BOTH HALVES. `FIND_IN_SET()` alone is a correct membership test and an index-free one:
 * MySQL cannot use an index to evaluate a function over the column, so it would read the whole
 * table. The `BETWEEN` over the set's own lowest and highest id is what brings the index back —
 * a range scan, narrowed by `FIND_IN_SET()` inside it.
 *
 * AN EMPTY LIST MATCHES NOTHING. `IN ()` is a syntax error; `BETWEEN 0 AND 0 AND
 * FIND_IN_SET( id, '' )` is valid SQL that returns no rows, which is what an empty list means.
 *
 * VALUES ONLY, NEVER SQL. Returning a fragment would reassemble the statement at runtime and
 * undo the point; every call site writes its WHERE out in full.
 *
 * Ported from plugixa-crm's `Core\Database\InList`, which made the same move the same day.
 */
final class InList {

	/**
	 * `[ lowest, highest, 'id,id,id' ]` for `col BETWEEN %d AND %d AND FIND_IN_SET( col, %s )`.
	 *
	 * Ids are cast to int, and anything that is not a positive id is dropped along with
	 * duplicates — a primary or foreign key here is never zero or negative, so no row could
	 * have matched it.
	 *
	 * @param array<int, mixed> $ids Ids, in any order.
	 *
	 * @return array{0: int, 1: int, 2: string}
	 */
	public static function ids( array $ids ): array {
		$ids = array_values(
			array_unique(
				array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 )
			)
		);

		if ( array() === $ids ) {
			return array( 0, 0, '' );
		}

		return array( min( $ids ), max( $ids ), implode( ',', $ids ) );
	}

	/**
	 * A comma-joined set of strings for `FIND_IN_SET( col, %s )`.
	 *
	 * For a column no range could serve — a question type, a role slug — so there is no
	 * `BETWEEN` to add.
	 *
	 * A VALUE CONTAINING A COMMA IS DROPPED. MySQL would read it as two members, and matching
	 * either half would be a wrong answer rather than a missing one. Under `IN` such a value was
	 * one string that no slug in this plugin can equal, so dropping it changes no result.
	 *
	 * @param array<int, mixed> $values Values, exactly as they should be compared.
	 */
	public static function strings( array $values ): string {
		$values = array_filter(
			array_unique( array_map( 'strval', $values ) ),
			static fn( string $value ): bool => '' !== $value && ! str_contains( $value, ',' )
		);

		return implode( ',', $values );
	}
}
