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
 * What an exam's landing page may tell the browser.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ A CAST EXAM ROW IS NOT SAFE TO INLINE, AND IT LOOKS ENTIRELY SAFE.
 *
 * `ExamRepository::cast()` unsets exactly one field — `access_code` — and hands back
 * everything else the table holds: `pass_message` and `fail_message` (the copy a candidate
 * is supposed to read only AFTER they finish), `access_level`, `settings`, `release_at`,
 * `custom_fields`. The template never printed any of it, so nothing was ever wrong on
 * screen; inlining the row as JSON would publish the lot to anybody who opens `view-source:`
 * on a page that is open to guests.
 *
 * So this is a PROJECTION, listing what may be sent rather than removing what may not. The
 * difference matters the next time a column is added to that table: a deny-list silently
 * starts publishing it, and an allow-list silently does not.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * THE GATE IS NARROWED TOO. `AccessGate` returns whatever a listener put in it, and premium
 * requirement modules answer `plugixa_exam_access_requirements` with structures of their own.
 * Only the four keys the page renders come through, plus the three the page renders
 * conditionally.
 */
final class ExamState {

	/**
	 * @param array<string, mixed> $exam   The exam row.
	 * @param array<string, mixed> $gate   The access verdict.
	 * @param Router               $router Link builder.
	 *
	 * @return array<string, mixed>
	 */
	public static function of( array $exam, array $gate, Router $router ): array {
		$slug = (string) ( $exam['slug'] ?? '' );

		$state = array(
			'id'           => (int) ( $exam['id'] ?? 0 ),
			'title'        => (string) ( $exam['title'] ?? '' ),
			'slug'         => $slug,

			// The one HTML field, and it is the one the author wrote FOR this page.
			'intro'        => wp_kses( (string) ( $exam['intro'] ?? '' ), plugixa_exam_allowed_html( 'stem' ) ),

			'timeLimit'    => (int) ( $exam['time_limit'] ?? 0 ),
			'passMark'     => (float) ( $exam['pass_mark'] ?? 0 ),
			'maxAttempts'  => (int) ( $exam['max_attempts'] ?? 0 ),

			'gate'         => self::gate( $gate ),

			/*
			 * The gate POSTs to `admin-post.php` and always will — `FormHandler` owns the
			 * nonce, the code comparison, the guest token and the redirect for sixteen
			 * verdicts. React may draw the controls; it does not take over the submission.
			 */
			'formUrl'      => $router->form_url(),
			'formAction'   => 'plugixa_exam_gate',
			'nonce'        => wp_create_nonce( 'plugixa_exam_gate' ),

			'examUrl'      => '' === $slug ? '' : $router->exam_url( $slug ),
			'catalogueUrl' => $router->catalogue_url(),
		);

		$state['loginUrl'] = '' === $state['examUrl'] ? wp_login_url() : wp_login_url( $state['examUrl'] );

		return $state;
	}

	/**
	 * The verdict, reduced to what the page renders.
	 *
	 * @param array<string, mixed> $gate The access verdict.
	 *
	 * @return array<string, mixed>
	 */
	private static function gate( array $gate ): array {
		$out = array(
			'allowed' => ! empty( $gate['allowed'] ),
			'reason'  => (string) ( $gate['reason'] ?? '' ),

			// `wp_strip_all_tags`, not `esc_html`: this is data, and the client escapes on
			// render. Escaping here would send `&amp;` to a component that escapes again.
			'message' => wp_strip_all_tags( (string) ( $gate['message'] ?? '' ) ),
		);

		/*
		 * ⚠️ `resume` IS A BOOLEAN HERE AND AN ATTEMPT ID IN THE VERDICT. The id is the one
		 * the runner resumes, and the page has no use for it — it only needs to know whether
		 * the button says "Resume" or "Start". Sending the id would publish which attempt a
		 * signed-in reader has open, which is theirs but is not the page's business.
		 */
		$out['resuming'] = isset( $gate['resume'] );

		if ( isset( $gate['attempts_used'], $gate['attempts_allowed'] ) ) {
			$out['attemptsUsed']    = (int) $gate['attempts_used'];
			$out['attemptsAllowed'] = (int) $gate['attempts_allowed'];
		}

		return $out;
	}
}
