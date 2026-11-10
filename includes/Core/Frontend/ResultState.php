<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything a result page draws, computed once.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * ONE COMPUTATION, THREE RENDERINGS, AND THE JSON IS NOT A SECOND SOURCE OF TRUTH.
 *
 * This used to live at the top of `templates/frontend/result.php`, which was right while
 * `/result/{id}/` was the only way to see a result. A site owner can now put the same view
 * on a page of their own with `[plugixa_exam_result]`, and a template is not somewhere a
 * shortcode can call into.
 *
 * So the array is built here, the routed template renders its markup FROM it, the shortcode
 * renders the SAME markup from the same array, and both inline that array for the React
 * view that replaces it. A template that printed one thing while a REST route computed
 * another is how a review screen ends up disagreeing with itself about whether an answer
 * was right — three renderings of one computation cannot.
 *
 * ⚠️ EVERY VISIBLE STRING IS TRANSLATED HERE, IN PHP, AND SENT ALREADY TRANSLATED. Not
 * because the client cannot translate — `hooks/useTranslation.ts` exists — but because
 * these particular sentences already existed in this code and are already in the catalogue.
 * Re-declaring "Correct" and "Awaiting marking" in `FrontendStrings` would ask every
 * translator to answer the same question twice and let the two answers differ.
 *
 * THE STATE CARRIES NO SCORE AT ALL when the exam has not released one: withheld from the
 * payload, not merely hidden by the markup. A React view is a `view-source:` away.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ THE VARIABLES KEPT THEIR `$pxe_` PREFIX, WHICH LOOKS WRONG IN A CLASS AND IS
 * DELIBERATE. This body moved out of a template verbatim; renaming forty variables in the
 * same commit that moves them is how a subtle transcription bug gets in and then gets
 * blamed on the move. They are function-scope locals either way.
 */
final class ResultState {

	/**
	 * The result the visitor asked for, as a view state — or null if they may not see it.
	 *
	 * ⚠️ OWNERSHIP IS NOT DECIDED HERE. `plugixa_exam_frontend_result` is answered by the
	 * Attempts module, which refuses an attempt the visitor does not own and withholds a
	 * score the exam has not released. This asks and renders; it does not judge.
	 *
	 * @param ViewData $data       Resolved view data.
	 * @param int      $attempt_id The attempt, or 0 to read it from the URL.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function of( ViewData $data, int $attempt_id = 0 ): ?array {
		$result = $attempt_id > 0 ? $data->result_by_id( $attempt_id ) : $data->result();

		if ( null === $result ) {
			return null;
		}

		return self::build( $result, $data->router() );
	}

	/**
	 * @param array<string, mixed> $pxe_result The result payload.
	 * @param Router               $pxe_router Link builder.
	 *
	 * @return array<string, mixed>
	 */
	public static function build( array $pxe_result, Router $pxe_router ): array {
		$pxe_attempt  = $pxe_result['attempt'];
		$pxe_released = ! empty( $pxe_result['released'] );
		$pxe_passed   = ! empty( $pxe_attempt['passed'] );
		$pxe_pending  = empty( $pxe_attempt['is_graded'] );

		/* ------------------------------------------------------------------ the grade, if any */

		/**
		 * The grade band this mark falls in, if the site defines any.
		 *
		 * The twin of `plugixa_exam_result_certificate` below, and the same shape: one filter per
		 * addition rather than a payload every module writes into, so a module that is switched off
		 * leaves no trace in the markup at all.
		 *
		 * A PENDING ATTEMPT IS NOT GRADED, so it gets no band — `percent` on a paper still awaiting
		 * a marker is the auto-graded part alone, and printing a letter beside it would report a
		 * grade that is about to change.
		 *
		 * @param array<string, mixed>|null $grade   The band, or null.
		 * @param float                     $percent The mark.
		 */
		$pxe_grade = ( $pxe_released && ! $pxe_pending )
			? apply_filters( 'plugixa_exam_result_grade', null, (float) $pxe_attempt['percent'] )
			: null;

		if ( ! is_array( $pxe_grade ) || '' === (string) ( $pxe_grade['label'] ?? '' ) ) {
			$pxe_grade = null;
		}

		/*
		 * THE BAND'S COLOUR GOES INTO A `style` ATTRIBUTE, SO IT IS A HEX COLOUR OR NOTHING.
		 *
		 * It arrives from a filter, which means from whatever is attached to it, and the
		 * template wrote it into `style="--pxe-grade: …"` behind `esc_attr()` alone — which
		 * keeps a value inside the attribute and does nothing about what it says once it is
		 * there. `red;position:fixed;inset:0` is a valid attribute and four declarations.
		 * The same mistake as the palette in {@see AppAssets::palette()}, found by looking
		 * for it after that one was reported. Settled here, where the answer is taken, so
		 * the state the React view reads and a theme's own copy of the template both get a
		 * value that is already safe; the template asks again where it prints.
		 */
		if ( null !== $pxe_grade ) {
			$pxe_colour = $pxe_grade['colour'] ?? '';

			$pxe_grade['colour'] = (string) sanitize_hex_color( is_string( $pxe_colour ) ? $pxe_colour : '' );
		}

		/* ----------------------------------------------------------- the certificate, if any */

		/**
		 * The certificate this attempt earned, if any.
		 *
		 * Answered by the Certificates module, which is FREE: a passing attempt earns, renders
		 * and verifies a certificate on every build. This comment used to say premium, and was
		 * left behind when the module moved out of pro/. What stayed premium is the DESIGNER.
		 *
		 * Still a filter rather than a direct call, because the answer is null on an attempt
		 * that earned nothing, and the block below then renders nothing. No "upgrade to unlock"
		 * panel either way, because advertising a feature on a page an examinee reached by
		 * passing an exam is somebody else's business intruding on their moment.
		 *
		 * @param array<string, mixed>|null $certificate The certificate row, or null.
		 * @param int                       $attempt_id  The attempt.
		 */
		$pxe_certificate = $pxe_released
			? apply_filters( 'plugixa_exam_result_certificate', null, (int) $pxe_attempt['id'] )
			: null;

		if ( ! is_array( $pxe_certificate ) || empty( $pxe_certificate['verify_token'] ) ) {
			$pxe_certificate = null;
		} elseif ( ! empty( $pxe_certificate['revoked'] ) ) {
			/*
			 * A WITHDRAWN CERTIFICATE SAYS SO, rather than vanishing. It used to be dropped here,
			 * so an examinee whose purchase was refunded — or whose certificate an administrator
			 * withdrew — came back to a result page that simply no longer mentioned one, with
			 * nothing to tell them it had ever existed or why the download had gone.
			 *
			 * No PDF: the file is exactly what was withdrawn. The verification link stays, because
			 * the public page is where "withdrawn" is the answer anybody checking will get. The
			 * reason is left out on purpose — an administrator's withdrawal note was written for
			 * the Certificates screen, not for the person it is about.
			 */
			$pxe_certificate = array(
				'number'    => (string) $pxe_certificate['number'],
				'withdrawn' => true,
				'line'      => sprintf(
					/* translators: %s: the certificate number. */
					__( 'Certificate %s has been withdrawn.', 'plugixa-exam' ),
					(string) $pxe_certificate['number']
				),
				'pdfUrl'    => '',
				'verifyUrl' => $pxe_router->verify_url( (string) $pxe_certificate['verify_token'] ),
			);
		} else {
			$pxe_certificate = array(
				'number'    => (string) $pxe_certificate['number'],
				'withdrawn' => false,
				'line'      => sprintf(
					/* translators: %s: the certificate number. */
					__( 'Certificate %s has been issued to you.', 'plugixa-exam' ),
					(string) $pxe_certificate['number']
				),
				'pdfUrl'    => rest_url(
					'plugixa-exam/v1/certificates/verify/'
					. rawurlencode( (string) $pxe_certificate['verify_token'] ) . '/pdf'
				),
				'verifyUrl' => $pxe_router->verify_url( (string) $pxe_certificate['verify_token'] ),
			);
		}

		/* ----------------------------------------------------------------------- the review */

		$pxe_review = array();

		foreach ( (array) ( $pxe_result['review'] ?? array() ) as $pxe_index => $pxe_item ) {
			$pxe_awarded  = $pxe_item['points_awarded'];
			$pxe_possible = (float) $pxe_item['points_possible'];

			if ( null === $pxe_awarded ) {
				$pxe_state_key = 'pending';
				$pxe_label     = __( 'Awaiting marking', 'plugixa-exam' );
			} elseif ( (float) $pxe_awarded >= $pxe_possible && $pxe_possible > 0 ) {
				$pxe_state_key = 'correct';
				$pxe_label     = __( 'Correct', 'plugixa-exam' );
			} elseif ( (float) $pxe_awarded > 0 ) {
				// A partially credited answer is neither right nor wrong, and saying "incorrect"
				// over a half-mark is simply untrue.
				$pxe_state_key = 'partial';
				$pxe_label     = __( 'Partly correct', 'plugixa-exam' );
			} else {
				$pxe_state_key = 'incorrect';
				$pxe_label     = __( 'Incorrect', 'plugixa-exam' );
			}

			$pxe_given  = $pxe_item['response'];
			$pxe_chosen = array_map( 'intval', is_array( $pxe_given ) ? $pxe_given : array( $pxe_given ) );

			$pxe_options = array();

			foreach ( (array) $pxe_item['options'] as $pxe_option ) {
				$pxe_options[] = array(
					'text'      => wp_kses( (string) $pxe_option['text'], plugixa_exam_allowed_html() ),
					'isCorrect' => ! empty( $pxe_option['is_correct'] ),
					'wasChosen' => in_array( (int) $pxe_option['id'], $pxe_chosen, true ),
				);
			}

			/*
			 * A true/false answer is stored as a boolean, so printing it raw shows the examinee "1"
			 * — which tells them nothing about what they answered, on the one screen whose entire
			 * job is to tell them exactly that.
			 *
			 * ⚠️ AND `(string) false` IS '' — so testing for an empty string first, as the line
			 * below does for every other type, hid every "False" a candidate gave: the review
			 * showed "Your answer: True" and, for the next question, nothing at all. Caught by the
			 * WooCommerce E2E on screen, 24 Sep 2026. Read the way `Grader` reads it.
			 */
			$pxe_shown = '';

			if ( array() === $pxe_options && 'true_false' === (string) $pxe_item['type'] ) {
				if ( is_scalar( $pxe_given ) && '' !== $pxe_given ) {
					$pxe_shown = filter_var( $pxe_given, FILTER_VALIDATE_BOOL )
						? __( 'True', 'plugixa-exam' )
						: __( 'False', 'plugixa-exam' );
				}
			} elseif ( array() === $pxe_options && is_scalar( $pxe_given ) && '' !== (string) $pxe_given ) {
				$pxe_shown = (string) $pxe_given;
			}

			$pxe_review[] = array(
				'number'      => sprintf(
					/* translators: %d: question number. */
					__( 'Question %d', 'plugixa-exam' ),
					(int) $pxe_index + 1
				),
				'state'       => $pxe_state_key,
				'label'       => $pxe_label,
				/*
				 * NOT A TRANSLATABLE STRING. `%1$s / %2$s` contains no word to translate — a
				 * volunteer opening the catalogue finds an entry they can only retype, and a
				 * language that writes the ratio differently cannot express that here anyway.
				 * Catalogue noise costs a real person real attention, so it is left out.
				 */
				'points'      => sprintf(
					'%1$s / %2$s',
					null === $pxe_awarded ? '—' : plugixa_exam_format_number( (float) $pxe_awarded ),
					plugixa_exam_format_number( $pxe_possible )
				),
				/*
				 * Material the question depends on, added by `plugixa_exam_review_item`. Named
				 * for what it is to this screen and nothing more: free code neither knows nor
				 * cares which module supplied it.
				 */
				'context'     => wp_kses( (string) ( $pxe_item['context_html'] ?? '' ), plugixa_exam_allowed_html( 'stem' ) ),
				'stem'        => wp_kses( (string) $pxe_item['stem'], plugixa_exam_allowed_html( 'stem' ) ),
				'explanation' => wp_kses( (string) $pxe_item['explanation'], plugixa_exam_allowed_html( 'stem' ) ),
				'options'     => $pxe_options,
				'given'       => $pxe_shown,
			);
		}

		/* ------------------------------------------------------------------------ the state */

		$pxe_state = array(
			'released'     => $pxe_released,
			'title'        => (string) $pxe_result['exam']['title'],
			'message'      => wp_kses( (string) ( $pxe_result['message'] ?? '' ), plugixa_exam_allowed_html( 'stem' ) ),
			'catalogueUrl' => $pxe_router->catalogue_url(),

			// Read by both halves so the ring and the figure cannot disagree with the sentence.
			'percent'      => $pxe_released ? (float) $pxe_attempt['percent'] : null,
			'passed'       => $pxe_released ? $pxe_passed : null,
			'pending'      => $pxe_released ? $pxe_pending : null,

			/*
			 * ONE SENTENCE, THREE PLACEHOLDERS, ALREADY FILLED. The client could compose it from
			 * `resultScore` and `lib/number.ts` — and then the same figures would be formatted by
			 * two implementations of the site's decimal settings.
			 */
			'scoreLine'    => $pxe_released
				? sprintf(
					/* translators: 1: percentage, 2: points scored, 3: points available. */
					__( '%1$s%% (%2$s of %3$s points)', 'plugixa-exam' ),
					plugixa_exam_format_number( (float) $pxe_attempt['percent'] ),
					plugixa_exam_format_number( (float) $pxe_attempt['score'] ),
					plugixa_exam_format_number( (float) $pxe_attempt['max_score'] )
				)
				: '',

			'verdict'      => $pxe_released
				? ( $pxe_passed ? __( 'Passed', 'plugixa-exam' ) : __( 'Not passed', 'plugixa-exam' ) )
				: '',

			/*
			 * Said plainly, and before the review. An examinee who reads a failing score and closes
			 * the tab without noticing that the essay is still unmarked has been given the wrong
			 * information — the mark can only go up from here.
			 */
			'pendingNote'  => __( 'Some answers are still being marked by hand, so your score may go up.', 'plugixa-exam' ),

			'grade'        => null === $pxe_grade
				? null
				: array(
					'label'  => (string) $pxe_grade['label'],
					'colour' => (string) ( $pxe_grade['colour'] ?? '' ),
				),

			'certificate'  => $pxe_certificate,
			'feedback'     => (string) ( $pxe_attempt['grader_comment'] ?? '' ),
			'review'       => $pxe_review,

			'labels'       => array(
				'yourAnswers'  => __( 'Your answers', 'plugixa-exam' ),
				'yourAnswer'   => __( 'your answer', 'plugixa-exam' ),
				'rightAnswer'  => __( 'correct answer', 'plugixa-exam' ),
				'certificate'  => __( 'Your certificate', 'plugixa-exam' ),
				'downloadPdf'  => __( 'Download PDF', 'plugixa-exam' ),
				'verifyLink'   => __( 'Verification link', 'plugixa-exam' ),
				'feedback'     => __( 'Marker’s feedback', 'plugixa-exam' ),
				'backToExams'  => __( 'Back to exams', 'plugixa-exam' ),
			),
		);

		return $pxe_state;
	}
}
