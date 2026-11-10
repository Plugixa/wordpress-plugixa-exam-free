<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Dashboard;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard.
 *
 * THE THINNEST MODULE IN THE PLUGIN, and useful as the reference for what a module is
 * allowed not to do. It owns no table, adds no capability and binds no service — it exists
 * so the admin has a landing screen, and so the shape of a module folder is demonstrated by
 * something small enough to read in one go.
 *
 * Its content comes from other modules through `dashboardWidgetRegistry` on the client, so
 * the statistics it shows grow as modules are installed without this file ever changing.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'dashboard';
	}

	public function app(): array {
		return array(
			'category' => 'core',
			'core'     => true,
		);
	}

	public function migrate(): void {
		// No tables. The dashboard reads other modules' data through their repositories.
	}

	public function capabilities(): array {
		return array();
	}

	public function register_services( Container $container ): void {
		unset( $container );
	}

	public function register_routes( Container $container ): void {
		unset( $container );
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
				'dashboardWelcome'     => __( 'Welcome back, {name}.', 'plugixa-exam' ),
				'there'                => __( 'there', 'plugixa-exam' ),
				'dashboardEmptyTitle'  => __( 'Nothing to show yet', 'plugixa-exam' ),
				/*
				 * The empty state is reached two ways and the sentence has to be true of
				 * both: a build whose modules contribute no widget, and a viewer permitted
				 * to see none of the ones that were contributed. It used to promise
				 * statistics "once you have created a subject, a question and an exam" —
				 * which stayed on screen on a site with fifty-four questions, because for
				 * four phases nothing registered a widget at all.
				 */
				'dashboardEmptyBody'   => __( 'There is nothing here you have permission to see. Ask an administrator if you were expecting statistics.', 'plugixa-exam' ),
				// The tiles and panels, contributed by the modules that own the data.
				'dashQuestions'        => __( 'Question bank', 'plugixa-exam' ),
				'dashQuestionsNote'    => __( 'questions to draw exams from', 'plugixa-exam' ),
				'dashExams'            => __( 'Exams', 'plugixa-exam' ),
				'dashExamsNote'        => __( 'published and open to learners', 'plugixa-exam' ),
				'dashExamsDrafts'      => __( '{n} more not published', 'plugixa-exam' ),
				'dashAttempts'         => __( 'Attempts', 'plugixa-exam' ),
				'dashAttemptsNote'     => __( 'papers started, all time', 'plugixa-exam' ),
				'dashPassRate'         => __( 'Pass rate', 'plugixa-exam' ),
				'dashNothingMarked'    => __( 'nothing marked yet', 'plugixa-exam' ),
				'dashNeedsGrading'     => __( 'Waiting to be marked', 'plugixa-exam' ),
				'dashNeedsGradingNote' => __( 'papers with something a person must score', 'plugixa-exam' ),
				'dashInProgress'       => __( 'Being sat now', 'plugixa-exam' ),
				'dashInProgressNote'   => __( 'papers open right now', 'plugixa-exam' ),
				'dashTrend'            => __( 'Submitted in the last fortnight', 'plugixa-exam' ),
				'dashTrendEmpty'       => __( 'Nothing has been handed in during the last two weeks.', 'plugixa-exam' ),
				'dashQueue'            => __( 'Waiting to be marked', 'plugixa-exam' ),
				'dashQueueAll'         => __( 'See all', 'plugixa-exam' ),
				'dashQueueEmpty'       => __( 'Nothing is waiting for a marker.', 'plugixa-exam' ),
				'dashboardModuleCount' => __( '{count} module(s) loaded · version {version}', 'plugixa-exam' ),
				'greetingMorning'      => __( 'Good morning', 'plugixa-exam' ),
				'greetingAfternoon'    => __( 'Good afternoon', 'plugixa-exam' ),
				'greetingEvening'      => __( 'Good evening', 'plugixa-exam' ),
				'dashboardIntro'       => __( 'Here is what is happening with your exams.', 'plugixa-exam' ),
				'navAuthoring'         => __( 'Authoring', 'plugixa-exam' ),
				'navDelivery'          => __( 'Delivery', 'plugixa-exam' ),
				'navInsight'           => __( 'Insight', 'plugixa-exam' ),
				'navAdmin'             => __( 'Administration', 'plugixa-exam' ),
				'fullscreen'           => __( 'Full screen', 'plugixa-exam' ),
				'exitFullscreen'       => __( 'Exit full screen', 'plugixa-exam' ),
				'reloadPage'           => __( 'Reload the page', 'plugixa-exam' ),
				'configMissingTitle'   => __( 'Configuration did not load', 'plugixa-exam' ),
				'configMissingBody'    => __( 'The application loaded but its settings did not. This usually means a caching or script-optimisation plugin removed the inline configuration script. Try clearing that plugin’s cache, or excluding this admin page from optimisation.', 'plugixa-exam' ),
			)
		);
	}
}
