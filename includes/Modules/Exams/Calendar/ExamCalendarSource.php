<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Exams\Calendar;

use PlugixaExam\Core\Security\Permissions;
use PlugixaExam\Modules\Exams\Repositories\ExamRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exam windows, on the calendar.
 *
 * The Calendar module asks; this answers. Neither names the other's classes — see
 * `Modules\Calendar\Services\CalendarSources` for why that is worth the indirection.
 *
 * FOUR SHAPES OF WINDOW, AND ONLY THREE OF THEM BELONG ON A CALENDAR:
 *
 * - opens and closes → a block spanning the window. The common case.
 * - opens, never closes → a single all-day marker on the opening date.
 * - closes, always was open → a single all-day marker on the closing date, which is the
 *   date that actually matters to anyone planning around it.
 * - neither → nothing. An always-open exam has no date, and putting it on every day of
 *   every month would bury the exams that do.
 *
 * THE OVERLAP TEST IS THE POINT OF THIS QUERY. A month view must show an exam that opened
 * in August and closes in October, even though neither of its dates is in September. The
 * naive `open_from BETWEEN from AND to` misses it, and the bug only appears for long
 * windows — which are exactly the ones somebody scheduled carefully.
 */
final class ExamCalendarSource {

	private const SOURCE_ID = 'exams';

	/**
	 * @param ExamRepository $exams The repository.
	 */
	public function __construct( private readonly ExamRepository $exams ) {}

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_calendar_sources', array( $this, 'source' ) );
		add_filter( 'plugixa_exam_calendar_events', array( $this, 'events' ), 10, 2 );
	}

	/**
	 * @param array<int, array<string, mixed>> $sources Accumulated sources.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function source( array $sources ): array {
		if ( ! Permissions::user_can( 'exams', 'view' ) ) {
			return $sources;
		}

		$sources[] = array(
			'id'    => self::SOURCE_ID,
			'label' => __( 'Exam windows', 'plugixa-exam' ),
			'color' => 'indigo',
		);

		return $sources;
	}

	/**
	 * Exam windows overlapping the requested period.
	 *
	 * @param array<int, array<string, mixed>> $events Accumulated events.
	 * @param array<string, mixed>             $window from, to, sources.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function events( array $events, array $window ): array {
		if ( ! in_array( self::SOURCE_ID, (array) ( $window['sources'] ?? array() ), true ) ) {
			return $events;
		}

		// The registry checks the source list too, but a filter is reachable by anyone who
		// can call `apply_filters` and this is the query that would run.
		if ( ! Permissions::user_can( 'exams', 'view' ) ) {
			return $events;
		}

		$from = (string) $window['from'];
		$to   = (string) $window['to'];

		$rows = $this->exams->query()
			->select( 't.id', 't.title', 't.slug', 't.open_from', 't.open_until', 't.published' )
			->where( '(t.open_from IS NOT NULL OR t.open_until IS NOT NULL)' )
			->where( '(t.open_from IS NULL OR t.open_from <= %s)', array( $to ) )
			->where( '(t.open_until IS NULL OR t.open_until >= %s)', array( $from ) )
			->order_by( 't.open_from', 'ASC' )
			->paginate( 500 )
			->get();

		foreach ( $rows as $row ) {
			$event = $this->to_event( $row );

			if ( null !== $event ) {
				$events[] = $event;
			}
		}

		return $events;
	}

	/**
	 * One exam row as a calendar event, or null when it has no dates.
	 *
	 * @param array<string, mixed> $row Exam row.
	 *
	 * @return array<string, mixed>|null
	 */
	private function to_event( array $row ): ?array {
		$id    = (int) $row['id'];
		$opens = (string) ( $row['open_from'] ?? '' );
		$shuts = (string) ( $row['open_until'] ?? '' );
		$title = (string) $row['title'];

		$base = array(
			'id'     => self::SOURCE_ID . '-' . $id,
			'source' => self::SOURCE_ID,
			'meta'   => array(
				'entity'    => 'exam',
				'id'        => $id,
				'published' => 1 === (int) ( $row['published'] ?? 0 ),
			),
		);

		if ( '' !== $opens && '' !== $shuts ) {
			return $base + array(
				'title'  => $title,
				'start'  => $opens,
				'end'    => $shuts,
				'allDay' => false,
			);
		}

		if ( '' !== $opens ) {
			return $base + array(
				/* translators: %s: exam title. */
				'title'  => sprintf( __( '%s opens', 'plugixa-exam' ), $title ),
				'start'  => $opens,
				'end'    => $opens,
				'allDay' => true,
			);
		}

		if ( '' !== $shuts ) {
			return $base + array(
				/* translators: %s: exam title. */
				'title'  => sprintf( __( '%s closes', 'plugixa-exam' ), $title ),
				'start'  => $shuts,
				'end'    => $shuts,
				'allDay' => true,
			);
		}

		return null;
	}
}
