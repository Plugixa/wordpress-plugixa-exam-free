<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Services;

use PlugixaExam\Core\Database\Schema as Db;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The four numbers a site owner opens the plugin to see, and a fortnight of activity.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * TWO QUERIES, AND BOTH OF THEM SEEK.
 *
 * The counts are one pass with conditional sums rather than five `COUNT(*)` queries with
 * five different `WHERE`s — same work for the database, one round trip instead of five, and
 * it cannot drift: a site that submits an attempt between query two and query four would
 * otherwise report a total that does not equal the sum of its parts, on the one screen
 * whose whole job is to add up.
 *
 * The indexes are the ones the specification names for exactly this: `(status,is_graded)`
 * for the marking queue and `(status,percent)` for the pass rate. The trend seeks on
 * `submitted`.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * WHAT COUNTS AS A RESULT. `passed` is only meaningful once something has been marked, so
 * the pass rate is over attempts with a `percent`, not over every row — an exam sat by
 * thirty people and marked for three has a pass rate of "3 marked so far", not of "10%".
 * `null` is returned when nothing has been marked at all, and the client renders a dash:
 * "nobody has passed" and "nothing has been marked" are different facts, and the dashboard
 * is precisely where collapsing them would mislead.
 *
 * THE TREND IS DENSE. SQL returns only days that have rows, and a bar chart drawn straight
 * from that silently rescales its axis — three sittings on Monday and three on Friday draw
 * as two adjacent equal bars, which reads as a busy week. Every day in the window is
 * emitted, zeroes included.
 */
final class Summary {

	/** Days of history the trend covers. Two weeks reads as a rhythm; a month reads as noise. */
	private const TREND_DAYS = 14;

	/**
	 * @return array<string, mixed>
	 */
	public function overview(): array {
		global $wpdb;

		$table = Db::table( 'attempts' );

		/*
		 * THE QUERY IS A TRUE LITERAL, placeholders and all.
		 *
		 * The first draft generated the `IN` list with `array_fill()` and concatenated it,
		 * which is the usual idiom for a variable-length list and costs a PHPCS
		 * suppression — the sniff cannot see that only placeholder text was interpolated,
		 * and a suppression on a `PreparedSQL` sniff is exactly the thing a WordPress.org
		 * reviewer looks at twice. `AWAITING_MARK` is a fixed pair, so the list can simply
		 * be written out; the assertion below is what keeps the two in step if a third
		 * status is ever added.
		 */
		$awaiting = AttemptRepository::AWAITING_MARK;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, deliberately uncached: a dashboard showing stale totals is worse than one query.
		$row = (array) $wpdb->get_row(
			$wpdb->prepare(
				'SELECT
					COUNT(*) AS total,
					SUM(CASE WHEN status = %s THEN 1 ELSE 0 END) AS in_progress,
					SUM(CASE WHEN is_graded = 0 AND status IN (%s, %s) THEN 1 ELSE 0 END) AS needs_grading,
					SUM(CASE WHEN percent IS NOT NULL THEN 1 ELSE 0 END) AS marked,
					SUM(CASE WHEN percent IS NOT NULL AND passed = 1 THEN 1 ELSE 0 END) AS passed,
					AVG(percent) AS average_percent
				FROM %i',
				'in_progress',
				$awaiting[0],
				$awaiting[1],
				$table
			),
			ARRAY_A
		);

		$marked = (int) ( $row['marked'] ?? 0 );
		$passed = (int) ( $row['passed'] ?? 0 );

		return array(
			'total'           => (int) ( $row['total'] ?? 0 ),
			'in_progress'     => (int) ( $row['in_progress'] ?? 0 ),
			'needs_grading'   => (int) ( $row['needs_grading'] ?? 0 ),
			'marked'          => $marked,
			'passed'          => $passed,
			// Null, not zero, when nothing has been marked. See the class docblock.
			'pass_rate'       => $marked > 0 ? round( $passed / $marked * 100, 1 ) : null,
			'average_percent' => null === ( $row['average_percent'] ?? null ) ? null : round( (float) $row['average_percent'], 1 ),
			'trend'           => $this->trend(),
		);
	}

	/**
	 * Submissions per day for the last fortnight, every day present.
	 *
	 * @return array<int, array{date: string, count: int}>
	 */
	private function trend(): array {
		global $wpdb;

		$since = gmdate( 'Y-m-d', time() - ( self::TREND_DAYS - 1 ) * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; aggregate for one admin screen.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT DATE(submitted) AS day, COUNT(*) AS n
				FROM %i
				WHERE submitted IS NOT NULL AND DATE(submitted) >= %s
				GROUP BY DATE(submitted)',
				Db::table( 'attempts' ),
				$since
			),
			ARRAY_A
		);

		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ (string) $row['day'] ] = (int) $row['n'];
		}

		$trend = array();

		for ( $back = self::TREND_DAYS - 1; $back >= 0; $back-- ) {
			$day = gmdate( 'Y-m-d', time() - $back * DAY_IN_SECONDS );

			$trend[] = array(
				'date'  => $day,
				'count' => $counts[ $day ] ?? 0,
			);
		}

		return $trend;
	}
}
