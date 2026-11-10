<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Calendar;

use PlugixaExam\Core\Security\Permissions;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sittings, on the calendar.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────
 * ONE EVENT PER DAY, NOT ONE PER ATTEMPT
 * ─────────────────────────────────────────────────────────────────────────────────────
 *
 * The literal reading of "put attempts on the calendar" is one block per sitting, labelled
 * with the examinee's name. It is also unusable and slow, and both problems arrive at the
 * same moment: the day a real exam runs. A hundred and forty people sitting a paper between
 * nine and eleven produces a hundred and forty overlapping blocks stacked in a two-hour
 * column, and the month view underneath it becomes a solid rectangle. The calendar is at its
 * least readable exactly when there is most to read.
 *
 * So this source aggregates: one all-day event per day, saying how many attempts were
 * submitted. The month view then shows the shape of activity, which is the question a
 * calendar is good at answering — when is it busy, did anyone sit this on the closing day.
 * The question it is bad at answering — who scored what — is one click away on the attempts
 * screen, which was built for it, has search and paging, and does not need to be rebuilt
 * here in a worse form.
 *
 * The query is a `GROUP BY` on an indexed column and returns at most one row per day. A busy
 * month costs thirty rows whether the site ran thirty attempts or thirty thousand.
 *
 * NO NAMES ON THE CALENDAR IS ALSO THE RIGHT PRIVACY DEFAULT. A schedule is the screen most
 * likely to be projected in a staff meeting or screenshotted into a chat, and a count says
 * everything that view needs without putting examinee names in the picture.
 */
final class AttemptCalendarSource {

	private const SOURCE_ID = 'attempts';

	/**
	 * @param AttemptRepository $attempts The repository.
	 */
	public function __construct( private readonly AttemptRepository $attempts ) {}

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
		if ( ! Permissions::user_can( 'attempts', 'view' ) ) {
			return $sources;
		}

		$sources[] = array(
			'id'    => self::SOURCE_ID,
			'label' => __( 'Sittings', 'plugixa-exam' ),
			'color' => 'teal',
		);

		return $sources;
	}

	/**
	 * A daily count of submitted attempts across the requested period.
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

		if ( ! Permissions::user_can( 'attempts', 'view' ) ) {
			return $events;
		}

		$rows = $this->attempts->query()
			->select( 'DATE(t.submitted) AS pxe_day', 'COUNT(*) AS pxe_total' )
			->where( 't.submitted IS NOT NULL' )
			->where( 't.submitted >= %s', array( (string) $window['from'] ) )
			->where( 't.submitted <= %s', array( (string) $window['to'] ) )
			->group_by( 'DATE(t.submitted)' )
			->order_by( 'pxe_day', 'ASC' )
			->paginate( 400 )
			->get();

		foreach ( $rows as $row ) {
			$day   = (string) $row['pxe_day'];
			$total = (int) $row['pxe_total'];

			if ( '' === $day || $total < 1 ) {
				continue;
			}

			$events[] = array(
				'id'     => self::SOURCE_ID . '-' . $day,
				'source' => self::SOURCE_ID,
				'title'  => sprintf(
					/* translators: %d: number of exam attempts submitted that day. */
					_n( '%d sitting submitted', '%d sittings submitted', $total, 'plugixa-exam' ),
					$total
				),
				/*
				 * Both ends on the same day: an all-day event, which the scheduler renders
				 * as a chip in the day cell rather than a block in the time grid. Spanning
				 * it to the end of the day instead would draw a bar across every hour and
				 * hide anything genuinely scheduled that day underneath it.
				 */
				'start'  => $day . ' 00:00:00',
				'end'    => $day . ' 00:00:00',
				'allDay' => true,
				'meta'   => array(
					'entity' => 'attempts',
					'day'    => $day,
					'total'  => $total,
				),
			);
		}

		return $events;
	}
}
