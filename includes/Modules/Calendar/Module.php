<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Calendar;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\Calendar\Http\CalendarController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * When things happen.
 *
 * A month view of exam windows and the sittings inside them, assembled from whichever
 * modules have something dated to show. See {@see Services\CalendarSources} for why this
 * module contains no query against any table and names no other module.
 *
 * READ-ONLY, DELIBERATELY. The obvious next feature is dragging an exam block to move its
 * window, and it is the wrong one: `open_from` and `open_until` are two of about forty
 * settings on an exam, they interact with `release_at`, the cooldown and the auto-close
 * sweep, and a drag is the easiest gesture in the whole admin to make by accident. An exam
 * closed an hour early by a mis-drag ends every attempt in progress. Clicking an event opens
 * the exam builder, where the window is changed next to the rules that depend on it and
 * saved on purpose.
 *
 * `core => true` because a calendar with nothing on it is not a calendar: with the Exams
 * module absent this screen has no reason to exist, and Exams is itself core. The App
 * Manager row would be a switch whose only effect is hiding a screen the user can already
 * ignore.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'calendar';
	}

	public function app(): array {
		return array(
			'category' => 'core',
			'core'     => true,
		);
	}

	public function migrate(): void {
		// Nothing to migrate: the calendar owns no tables. Everything on it belongs to
		// whichever module put it there, and is stored there.
	}

	public function capabilities(): array {
		return array();
	}

	public function register_services( Container $container ): void {
		unset( $container );
	}

	public function register_routes( Container $container ): void {
		$container->get( CalendarController::class )->register_routes();
	}

	public function register_hooks( Container $container ): void {
		unset( $container );

		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );
	}

	/**
	 * @param array<string, string> $strings Accumulated strings.
	 *
	 * @return array<string, string>
	 */
	public function strings( array $strings ): array {
		return array_merge(
			$strings,
			array(
				'calendar'            => __( 'Calendar', 'plugixa-exam' ),
				'calendarDesc' => __( 'A month view of exam windows and the sittings inside them.', 'plugixa-exam' ),
				'calendarIntro'       => __( 'Exam windows and the sittings inside them.', 'plugixa-exam' ),
				'calendarSources'     => __( 'Show', 'plugixa-exam' ),
				'calendarEmpty'       => __( 'Nothing is scheduled in this period.', 'plugixa-exam' ),
				'calendarOpens'       => __( 'Opens', 'plugixa-exam' ),
				'calendarCloses'      => __( 'Closes', 'plugixa-exam' ),
				'calendarAlwaysOpen'  => __( 'Always open', 'plugixa-exam' ),
				'calendarReadOnly'    => __( 'Open the exam to change its window', 'plugixa-exam' ),
				'calendarScheduled'   => __( 'Exam windows', 'plugixa-exam' ),
				'calendarStateOpen'   => __( 'Open now', 'plugixa-exam' ),
				'calendarStateScheduled' => __( 'Scheduled', 'plugixa-exam' ),
				'calendarStateClosed' => __( 'Closed', 'plugixa-exam' ),
				'calendarEmptyHelp'   => __( 'An exam appears here once it has an opening or closing date. Exams that are always open have no date, so there is nothing to place.', 'plugixa-exam' ),
				'calendarFailed'      => __( 'The calendar could not be loaded.', 'plugixa-exam' ),
				'sourceExams'         => __( 'Exam windows', 'plugixa-exam' ),
				'sourceAttempts'      => __( 'Sittings', 'plugixa-exam' ),
			)
		);
	}
}
