<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Calendar\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The registry behind the calendar: who can put something on it, and what they put there.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────
 * WHY THE CALENDAR KNOWS NOTHING ABOUT EXAMS
 * ─────────────────────────────────────────────────────────────────────────────────────
 *
 * The obvious build is a calendar module that queries the exams table for open windows and
 * the attempts table for sittings. It is fifty lines, it works, and it is the wrong shape —
 * because the next thing anybody wants on a schedule is certificate expiry dates, then
 * invigilation sessions, then cohort enrolment windows, and each of those is a premium
 * module that does not exist in the free build. A calendar that queried tables directly
 * would need a `class_exists()` for each, which is the licence check the wp.org guidelines
 * forbid wearing a different hat.
 *
 * So the calendar asks, twice:
 *
 *   `plugixa_exam_calendar_sources`  — "who has something to show?"
 *   `plugixa_exam_calendar_events`   — "what falls in this window?"
 *
 * Exams and Attempts answer today. A premium module answers by existing. Nothing here
 * changes, and nothing here has to know what a certificate is.
 *
 * THE SOURCE IS ALSO THE UNIT OF COLOUR AND OF FILTERING, which is why it is a first-class
 * thing rather than a string on each event: MUI's scheduler models exactly this as a
 * `resource`, so a source becomes a resource, its colour becomes the resource colour, and
 * the sidebar's show/hide checkboxes come out of the component for free.
 *
 * DATES ARE UTC, ALWAYS, IN `Y-m-d H:i:s`. The database stores UTC; the client converts to
 * the site's timezone for display. Passing local time across this boundary is the classic
 * way an exam that opens at 09:00 appears at 09:00 for the administrator and 04:00 for the
 * server, and only in summer.
 */
final class CalendarSources {

	/**
	 * Colours a source may claim.
	 *
	 * These are MUI Scheduler palette names, not CSS colours, so that the calendar's own
	 * light and dark themes stay coherent — a source handing over `#ff0000` would be
	 * unreadable in one of them. A source asking for anything not on this list gets grey,
	 * which is legible everywhere.
	 */
	private const COLORS = array( 'red', 'pink', 'purple', 'indigo', 'blue', 'teal', 'green', 'lime', 'amber', 'orange', 'grey' );

	/**
	 * How far apart `from` and `to` may be, in days.
	 *
	 * A month view asks for about six weeks. This allows a year, which covers an agenda
	 * view and any reasonable export, and refuses the request that asks for everything ever
	 * — one of which is enough to make a large site's attempts table scan.
	 */
	private const MAX_RANGE_DAYS = 400;

	/**
	 * Every module with something to put on the calendar.
	 *
	 * @return array<int, array{id: string, label: string, color: string}>
	 */
	public function sources(): array {
		/**
		 * Declare a calendar source.
		 *
		 * Each entry is:
		 *
		 *     array(
		 *         'id'    => 'exams',                              // unique, stable
		 *         'label' => __( 'Exam windows', 'plugixa-exam' ),
		 *         'color' => 'indigo',                             // see self::COLORS
		 *     )
		 *
		 * @param array<int, array<string, mixed>> $sources Accumulated sources.
		 */
		$sources = (array) apply_filters( 'plugixa_exam_calendar_sources', array() );

		$valid = array();
		$seen  = array();

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) || empty( $source['id'] ) ) {
				continue;
			}

			$id = sanitize_key( (string) $source['id'] );

			// A duplicate id would give the scheduler two resources with the same key and
			// silently hide one source's events behind the other's.
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;

			$color = (string) ( $source['color'] ?? 'grey' );

			$valid[] = array(
				'id'    => $id,
				'label' => (string) ( $source['label'] ?? $id ),
				'color' => in_array( $color, self::COLORS, true ) ? $color : 'grey',
			);
		}

		return $valid;
	}

	/**
	 * Everything falling inside a window.
	 *
	 * @param string   $from    Window start, UTC `Y-m-d H:i:s`.
	 * @param string   $to      Window end, UTC `Y-m-d H:i:s`.
	 * @param string[] $only    Source ids to include; empty means all of them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function events( string $from, string $to, array $only = array() ): array {
		$known = wp_list_pluck( $this->sources(), 'id' );

		$only = array() === $only
			? $known
			: array_values( array_intersect( $known, array_map( 'sanitize_key', $only ) ) );

		if ( array() === $only ) {
			return array();
		}

		/**
		 * Contribute events falling inside a window.
		 *
		 * A source is handed the window and returns events overlapping it — not events
		 * starting inside it. An exam that opened last week and closes next week belongs on
		 * this week's calendar, and a source that filters on `start BETWEEN` will drop it.
		 *
		 * Each event is:
		 *
		 *     array(
		 *         'id'      => 'exams-17',        // unique across every source
		 *         'source'  => 'exams',           // must match a registered source id
		 *         'title'   => 'Algebra II',
		 *         'start'   => '2026-09-14 09:00:00',   // UTC
		 *         'end'     => '2026-09-14 11:00:00',   // UTC
		 *         'allDay'  => false,
		 *         'url'     => 'https://…/exams/17',    // an outside link, optional
		 *         'meta'    => array( 'entity' => 'exam', 'id' => 17 ),  // optional
		 *     )
		 *
		 * `meta` is what a click acts on. It is deliberately not a URL: the admin is a
		 * single-page app, and handing it an absolute URL to its own screen would make it
		 * reload itself. The client reads `meta.entity` and routes internally.
		 *
		 * @param array<int, array<string, mixed>> $events Accumulated events.
		 * @param array<string, mixed>             $window from, to, sources.
		 */
		$events = (array) apply_filters(
			'plugixa_exam_calendar_events',
			array(),
			array(
				'from'    => $from,
				'to'      => $to,
				'sources' => $only,
			)
		);

		$valid = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$source = sanitize_key( (string) ( $event['source'] ?? '' ) );

			/*
			 * A source that answers with events it was not asked for is dropped rather than
			 * trusted. Otherwise a module could ignore the `sources` argument and put its
			 * events back on a calendar the user has just unticked.
			 */
			if ( ! in_array( $source, $only, true ) ) {
				continue;
			}

			$start = (string) ( $event['start'] ?? '' );
			$end   = (string) ( $event['end'] ?? '' );

			if ( '' === $start || '' === $end ) {
				continue;
			}

			$valid[] = array(
				'id'     => (string) ( $event['id'] ?? $source . '-' . count( $valid ) ),
				'source' => $source,
				'title'  => (string) ( $event['title'] ?? '' ),
				// `T` and `Z` make these unambiguous instants. The scheduler reads a string
				// ending in `Z` as UTC and converts for display; without it the string is
				// wall time and the whole calendar shifts by the site's offset.
				'start'  => $this->instant( $start ),
				'end'    => $this->instant( $end ),
				'allDay' => (bool) ( $event['allDay'] ?? false ),
				'url'    => isset( $event['url'] ) ? esc_url_raw( (string) $event['url'] ) : '',
				'meta'   => is_array( $event['meta'] ?? null ) ? $event['meta'] : array(),
			);
		}

		return $valid;
	}

	/**
	 * Clamp a requested window to something a database can answer quickly.
	 *
	 * @param string $from Requested start.
	 * @param string $to   Requested end.
	 *
	 * @return array{0: string, 1: string} Normalised UTC bounds.
	 */
	public function window( string $from, string $to ): array {
		$start = strtotime( $from . ' UTC' );
		$end   = strtotime( $to . ' UTC' );

		if ( false === $start ) {
			$start = strtotime( gmdate( 'Y-m-01 00:00:00' ) . ' UTC' );
		}

		if ( false === $end || $end <= $start ) {
			$end = $start + ( 31 * DAY_IN_SECONDS );
		}

		$end = min( $end, $start + ( self::MAX_RANGE_DAYS * DAY_IN_SECONDS ) );

		return array( gmdate( 'Y-m-d H:i:s', $start ), gmdate( 'Y-m-d H:i:s', $end ) );
	}

	/**
	 * A stored UTC datetime as an ISO-8601 instant.
	 *
	 * @param string $datetime `Y-m-d H:i:s`, UTC.
	 */
	private function instant( string $datetime ): string {
		$timestamp = strtotime( $datetime . ' UTC' );

		return false === $timestamp ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}
