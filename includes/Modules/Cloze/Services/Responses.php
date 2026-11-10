<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Cloze\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a cloze answer is allowed to look like on its way into the database.
 *
 * ────────────────────────────────────────────────────────────────────────────────────────
 * WITHOUT THIS CLASS EVERY CLOZE ANSWER SCORES ZERO, SILENTLY.
 *
 * `AttemptService::sanitize_response()` DISCARDS an answer whose type it does not recognise
 * — deliberately, because an unvalidated shape written straight to the database is stored
 * user input that nothing has checked. The module owning the type is the only thing that
 * knows what it should look like, so the module has to say.
 *
 * Miss this hook and everything else still works: the type registers, questions author and
 * save, the runner renders. Then every examinee scores zero, because their answers were
 * dropped between the browser and the row. Nothing errors.
 * ────────────────────────────────────────────────────────────────────────────────────────
 *
 * THE SHAPE IS A MAP, AND THE KEYS COME FROM THE CLIENT. A response is `{ blankIndex: text }`,
 * and a browser can send any keys it likes — including enormous ones, or thousands of them.
 * So it is rebuilt from scratch with coerced keys and a hard cap, rather than filtered.
 */
final class Responses {

	/**
	 * Most blanks one answer may fill.
	 *
	 * Matches the authoring cap in {@see AnswerKeys}. A response with more parts than the
	 * question has is not an answer, it is either a bug or an attempt to make grading
	 * expensive.
	 */
	private const MAX_PARTS = 100;

	/**
	 * Longest a single blank's answer may be.
	 *
	 * A blank holds a word or a short phrase. This is generous for that and still bounds
	 * what one examinee can push into a `longtext` on an autosave timer.
	 */
	private const MAX_TEXT = 500;

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_sanitize_response', array( $this, 'sanitize' ), 10, 3 );
	}

	/**
	 * @param mixed  $sanitized The cleaned answer so far, null by default.
	 * @param mixed  $answer    The raw answer.
	 * @param string $type      Question type.
	 */
	public function sanitize( mixed $sanitized, mixed $answer, string $type ): mixed {
		if ( 'cloze' !== $type ) {
			// Another module's type. Leave its value exactly as found.
			return $sanitized;
		}

		if ( ! is_array( $answer ) ) {
			return array();
		}

		$out = array();

		foreach ( $answer as $key => $value ) {
			if ( count( $out ) >= self::MAX_PARTS ) {
				break;
			}

			if ( ! is_scalar( $value ) && null !== $value ) {
				// A nested array or object is not an answer to a blank.
				continue;
			}

			/*
			 * `absint` on the key rather than a cast, so `"3"` and `3` land in the same slot —
			 * they arrive as strings through JSON and as integers from the no-JavaScript form
			 * POST, and a response that grades differently depending on which one submitted it
			 * would be a genuinely miserable bug to find.
			 *
			 * `mb_substr` rather than `substr`: cutting a multi-byte character in half produces
			 * invalid UTF-8, which MySQL rejects — turning an over-long answer into a failed
			 * save rather than a truncated one.
			 */
			$out[ absint( $key ) ] = mb_substr( sanitize_text_field( (string) $value ), 0, self::MAX_TEXT );
		}

		return $out;
	}
}
