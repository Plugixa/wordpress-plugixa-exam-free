<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\App;

use PlugixaExam\Core\Frontend\ShellData;
use PlugixaExam\Core\Plugin;
use PlugixaExam\Core\Settings;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translated strings for the front-end bundles — the exam runner and the portal.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE DEFECT THIS EXISTS FOR: THE ONE SCREEN A CANDIDATE ACTUALLY SITS WAS ENGLISH-ONLY.
 *
 * `templates/frontend/take.php` routes every visible string through `esc_html_e()`, so the
 * no-JS fallback has always been translatable. The React runner that REPLACES it carried
 * around sixty hardcoded English literals and no string channel at all — so switching a
 * site to French translated the fallback and silently reverted the real exam page to
 * English. The better the browser, the worse the translation.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * A SEPARATE CHANNEL FROM `Core\App\Config`, DELIBERATELY, AND NOT FOR TIDINESS.
 * `Config::payload()` carries `permissions` (the whole matrix), `settings`, `apps`,
 * `adminUrl` and the current user. That object is fine on `wp-admin`, where the reader is
 * already staff. An exam page is PUBLIC — a guest sitting a paper by link, a search engine
 * on the catalogue — and `Config::inject( 'frontend' )` would have published the site's
 * permission matrix to all of them. This class carries strings and nothing else.
 *
 * NOT `wp_set_script_translations()`, for the reason {@see Strings} gives: it keys
 * translations to a bundle filename, and Vite's content hash changes on every build, so a
 * rebuild would silently un-translate the runner. A flat key-to-string map has no such
 * coupling. Blocks are the exception and do use it — they are unbuilt files with stable
 * names, so the objection does not apply to them.
 *
 * THE FREE BUILD SHIPS NO PREMIUM STRINGS. Everything here belongs to the free runner and
 * portal; a premium module appends its own through `plugixa_exam_frontend_i18n_strings`,
 * so an uninstalled module contributes nothing and the free bundle names no premium
 * feature. Same discipline, and same reason, as the admin bag.
 */
final class FrontendStrings {

	private const HANDLE = 'plugixa-exam-frontend-i18n';

	/**
	 * Put `window.PlugixaExamFrontend` on the page.
	 *
	 * A src-less handle carrying an inline script, for the reason {@see Config::inject()}
	 * documents: the bundles are enqueued with `wp_enqueue_script_module()`, and
	 * `wp_localize_script()` cannot attach data to a script module.
	 *
	 * @param string $modifier Scope modifier of the page being rendered, e.g. `pxe--result`.
	 *                         An empty string means an embed, which has no application shell.
	 */
	public static function inject( string $modifier = '' ): void {
		$payload = array(
			'i18n'     => self::all(),

			/*
			 * THE SITE'S LOCALE AND TIMEZONE, BECAUSE FORMATTING A DATE IS THE SAME
			 * PROBLEM AS TRANSLATING A WORD.
			 *
			 * `Portal.tsx` called `toLocaleDateString()` with neither, so a result was
			 * dated in the BROWSER's locale and the BROWSER's timezone — the exact defect
			 * `lib/datetime.ts` was written to stop in the admin app, reappearing on the
			 * learner's screen. A candidate in Lisbon and the site in Paris disagreeing
			 * about which day they sat a paper is not a cosmetic difference.
			 */
			'locale'   => get_user_locale(),

			// Which way the page runs — see `Core\App\Config` for why this is `is_rtl()`
			// rather than a list of locales kept in the plugin.
			'isRtl'    => is_rtl(),
			'timezone' => Config::timezone(),

			/*
			 * HOW THIS SITE WRITES NUMBERS, WHICH IS NOT A QUESTION ABOUT THE READER.
			 *
			 * `decimal_places`, `decimal_separator` and `thousands_separator` reached
			 * wp-admin and reached the server-rendered templates through
			 * `plugixa_exam_format_number()`, and did not reach here — which did not show
			 * while the result page and the portal were PHP, because PHP was doing the
			 * formatting. It would show on the first React result page on any site that
			 * does not write numbers the English way. See `lib/number.ts`, and note that
			 * `toLocaleString()` is the wrong answer: a mark is a property of the site.
			 */
			'format'   => self::number_format(),

			/*
			 * WHERE EACH VIEW LIVES, so a client that navigates does not have to rebuild
			 * URLs it does not own — the exam base is filterable, the learner paths are
			 * fixed, and a language plugin adds a prefix only the server knows about.
			 *
			 * ⚠️ THIS USED TO BE THE APPLICATION'S NAVIGATION, composed by `ShellData` and
			 * rendered by `parts/nav.php` so the pre-boot markup and the payload could not
			 * disagree. The plugin does not ship a menu any more; the key kept its name
			 * because the route table was always the half of it that mattered.
			 *
			 * ⚠️ NOTHING PRIVILEGED. Every route below is a public URL. This is not, and
			 * must not become, a second `Core\App\Config` — see this class's header.
			 */
			'shell'    => ShellData::payload( $modifier ),
		);

		wp_register_script( self::HANDLE, '', array(), PLUGIXA_EXAM_VERSION, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script(
			self::HANDLE,
			'window.PlugixaExamFrontend = ' . wp_json_encode( $payload ) . ';',
			'before'
		);
	}

	/**
	 * The three number-formatting settings, as the client's `NumberFormat`.
	 *
	 * FAILS OPEN TO THE SHIPPED DEFAULTS, exactly as `plugixa_exam_format_number()` does and
	 * for the same reason: this runs while rendering a page somebody is reading their result
	 * on, and a site whose settings are briefly unreadable should print the mark they earned
	 * rather than a fatal.
	 *
	 * The one-character truncation is deliberately NOT done here. `lib/number.ts` does it,
	 * as `plugixa_exam_format_number()` does, so the rule lives beside the formatting in
	 * both halves rather than in the transport between them.
	 *
	 * @return array{places: int, decimal: string, thousands: string}
	 */
	private static function number_format(): array {
		$settings = null;

		try {
			$settings = Plugin::instance()->container()->get( Settings::class );
		} catch ( Throwable $e ) {
			$settings = null;
		}

		if ( null === $settings ) {
			return array(
				'places'    => 2,
				'decimal'   => '.',
				'thousands' => ',',
			);
		}

		return array(
			'places'    => $settings->int( 'decimal_places', 2 ),
			'decimal'   => $settings->str( 'decimal_separator', '.' ),
			'thousands' => $settings->str( 'thousands_separator', ',' ),
		);
	}

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		$strings = array(
			// Autosave, read aloud by a screen reader as it changes.
			'saving'            => __( 'Saving…', 'plugixa-exam' ),
			'saved'             => __( 'Saved', 'plugixa-exam' ),
			'saveFailed'        => __( "Couldn't save — will retry", 'plugixa-exam' ),
			'offline'           => __( 'The site could not be reached.', 'plugixa-exam' ),

			/*
			 * The clock. Two separate strings because the visible one is an abbreviation
			 * and the other is only ever heard: " left" beside 12:04 is what a sighted
			 * reader needs, " remaining" is what makes the timer a sentence out loud.
			 * A language that cannot use a trailing word for this is free to translate
			 * either to an empty string.
			 */
			'timeRemaining'     => __( ' remaining', 'plugixa-exam' ),
			'timeLeft'          => __( ' left', 'plugixa-exam' ),

			// Navigator.
			'questionNavigator' => __( 'Question navigator', 'plugixa-exam' ),

			// The header band.
			'examInProgress'     => __( 'Exam in progress', 'plugixa-exam' ),
			'practiceInProgress' => __( 'Practice in progress', 'plugixa-exam' ),
			'timeLeftWord'       => __( 'left', 'plugixa-exam' ),
			'leaveExam'          => __( 'Save and leave', 'plugixa-exam' ),
			'answeredOfLong'     => __( '{done} of {total} answered', 'plugixa-exam' ),

			/*
			 * The one-line instruction under a composite question's stem.
			 *
			 * A control that is four identical dropdowns does not explain what it is asking
			 * for; a radio group does, which is why the simple types have no entry here.
			 */
			'hintOrdering'       => __( 'Choose a position number for each item.', 'plugixa-exam' ),
			'hintMatching'       => __( 'Choose the matching value for each item.', 'plugixa-exam' ),
			'hintEmq'            => __( 'Answer each statement from the list.', 'plugixa-exam' ),
			'hintCloze'          => __( 'Fill in each blank.', 'plugixa-exam' ),
			'hintMcqMulti'       => __( 'Choose every answer that applies.', 'plugixa-exam' ),

			// The status bar's progress. `answeredOf` is a sentence a translator has to be
			// able to reorder — German puts the total first — so it is a placeholder
			// string, not two numbers concatenated in the component.
			'examProgress'      => __( 'Questions answered', 'plugixa-exam' ),
			'answeredOf'        => __( '{done} of {total}', 'plugixa-exam' ),
			'questionNumber'    => __( 'Question {number}', 'plugixa-exam' ),
			'answered'          => __( 'Answered', 'plugixa-exam' ),
			'notAnswered'       => __( 'Not answered', 'plugixa-exam' ),
			'flagged'           => __( 'Flagged', 'plugixa-exam' ),
			'goToIt'            => __( 'Go to it', 'plugixa-exam' ),

			/*
			 * The navigator's accessible label, assembled as ONE string rather than
			 * concatenated from three. A comma and a word order that works in English is
			 * not one that works everywhere, and a translator handed the pieces cannot fix
			 * it. `{state}` is one of the three below.
			 */
			'questionNavLabel'  => __( 'Question {number}, {state}', 'plugixa-exam' ),
			'navStateAnswered'  => __( 'answered', 'plugixa-exam' ),
			'navStateUnanswered' => __( 'not answered', 'plugixa-exam' ),
			'navStateFlagged'   => __( 'answered, flagged', 'plugixa-exam' ),
			'navStateUnansweredFlagged' => __( 'not answered, flagged', 'plugixa-exam' ),

			// A question.
			'points'            => __( '{points} points', 'plugixa-exam' ),
			'flagForReview'     => __( 'Flag for review', 'plugixa-exam' ),
			'removeFlag'        => __( 'Remove flag', 'plugixa-exam' ),
			'chooseOne'         => __( 'Choose one answer', 'plugixa-exam' ),
			'chooseAll'         => __( 'Choose all that apply', 'plugixa-exam' ),
			'trueOrFalse'       => __( 'True or false', 'plugixa-exam' ),
			'answerTrue'        => __( 'True', 'plugixa-exam' ),
			'answerFalse'       => __( 'False', 'plugixa-exam' ),
			'yourAnswer'        => __( 'Your answer', 'plugixa-exam' ),

			// The review step.
			'beforeYouSubmit'   => __( 'Before you submit', 'plugixa-exam' ),
			'someUnanswered'    => __( 'You have not answered {count} of {total} questions. Once you submit you cannot go back.', 'plugixa-exam' ),
			'allAnswered'       => __( 'You have answered every question. Once you submit you cannot go back.', 'plugixa-exam' ),

			// Actions.
			'previous'          => __( 'Previous', 'plugixa-exam' ),
			'next'              => __( 'Next', 'plugixa-exam' ),
			'backToQuestions'   => __( 'Back to the questions', 'plugixa-exam' ),
			'submitting'        => __( 'Submitting…', 'plugixa-exam' ),
			'finish'            => __( 'Finish', 'plugixa-exam' ),
			'submitExam'        => __( 'Submit exam', 'plugixa-exam' ),

			// The portal.
			'assignedToMe'      => __( 'Assigned to me', 'plugixa-exam' ),
			'myResults'         => __( 'My results', 'plugixa-exam' ),
			'myCertificates'    => __( 'My certificates', 'plugixa-exam' ),
			'noCertificates'    => __( 'You have not earned a certificate yet.', 'plugixa-exam' ),
			'nothingAssigned'   => __( 'Nothing has been assigned to you yet.', 'plugixa-exam' ),
			'noResults'         => __( 'You have not finished any exams yet.', 'plugixa-exam' ),
			'resultsNotOut'     => __( 'Results are not out yet.', 'plugixa-exam' ),
			'view'              => __( 'View', 'plugixa-exam' ),
			'passed'            => __( 'Passed', 'plugixa-exam' ),
			'notPassed'         => __( 'Not passed', 'plugixa-exam' ),
			'opensOn'           => __( 'Opens {date}', 'plugixa-exam' ),
			'dueOn'             => __( 'Due {date}', 'plugixa-exam' ),

			/*
			 * ONE SENTENCE, THREE PLACEHOLDERS — the twin of the `printf()` in
			 * `templates/frontend/result.php`, and deliberately not three strings glued
			 * together with a translated "of". A language that puts the total first, drops
			 * the connective, or needs a different word for "points" can only say so if it
			 * is handed the whole sentence. Each number arrives already formatted by
			 * `lib/number.ts`, which is the site's decimal separator, not the reader's.
			 */
			'resultScore'       => __( '{percent}% — {scored} of {total} points', 'plugixa-exam' ),

			/*
			 * The portal's three groups. These were the LABELS and the identity at once —
			 * `'Closed' === heading` decided whether a row was a link — so wrapping them in
			 * a translation call would have quietly broken the portal on every non-English
			 * site: the comparison would fail, a closed exam would become a link, and the
			 * landing page would then refuse it. The group is now keyed by a slug and this
			 * is only its name.
			 */
			'groupAvailable'    => __( 'Ready to sit', 'plugixa-exam' ),
			'groupUpcoming'     => __( 'Opening later', 'plugixa-exam' ),
			'groupClosed'       => __( 'Closed', 'plugixa-exam' ),
		);

		/**
		 * Add a module's front-end strings.
		 *
		 * The premium twin of `plugixa_exam_admin_i18n_strings`. A module that is not
		 * installed contributes nothing, so the free build ships no premium label.
		 *
		 * @param array<string, string> $strings Accumulated strings.
		 */
		return (array) apply_filters( 'plugixa_exam_frontend_i18n_strings', $strings );
	}
}
