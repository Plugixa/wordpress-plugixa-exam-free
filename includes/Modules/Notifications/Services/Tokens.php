<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Notifications\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `{token}` substitution for email subjects and bodies.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * A SCORE TOKEN RESOLVES TO NOTHING UNLESS THE RESULT HAS BEEN RELEASED.
 *
 * This is the whole reason the class exists rather than a `strtr()` at the call site. An exam
 * set to `manual` or `after_close` release deliberately withholds the mark — and the email
 * that says "your submission was received" goes out the instant the paper is submitted, long
 * before anybody has released anything. If `{percent}` interpolated there, the notification
 * would hand over the exact number the release policy exists to withhold, in plain text, to
 * an inbox, where deleting it later changes nothing.
 *
 * So the caller passes `released`, and every score token collapses to an empty string when it
 * is false. An administrator who puts `{score}` into the "submission received" template gets
 * a blank rather than a leak, and the template editor says so.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class Tokens {

	/**
	 * Tokens that must never appear before results are released.
	 *
	 * @var string[]
	 */
	private const SCORE_TOKENS = array( 'score', 'max_score', 'percent', 'outcome', 'pass_mark' );

	/**
	 * Tokens whose value IS markup, and must not be escaped into an HTML body.
	 *
	 * Exactly one so far, and it should stay that way: `{report_html}` is a table the Analytics
	 * scheduler rendered itself. Everything else is a title, a name or a number that somebody
	 * typed, and a title reading `Fractions <½>` must arrive as words rather than as a broken
	 * tag — which is the whole reason `apply()` escapes at all.
	 *
	 * @var string[]
	 */
	private const RAW_TOKENS = array( 'report_html' );

	/**
	 * The tokens whose value is markup.
	 *
	 * Public because two other places need the same list and guessing it produces a plausible
	 * wrong answer: anything converting a plain-text body to HTML must leave a markup token
	 * unwrapped (a `<table>` inside a `<p>` is invalid and mail clients close the paragraph
	 * early) while still wrapping a token that resolves to ordinary words. "Is this block just a
	 * token" is NOT the test — `{report_name}` is a token too, and it is a sentence.
	 *
	 * @return string[]
	 */
	public static function raw_tokens(): array {
		return self::RAW_TOKENS;
	}

	/**
	 * Block elements a withheld token can be sitting inside, innermost first.
	 *
	 * ORDER IS THE ALGORITHM. `<p>` and `<li>` come before `<tr>`, so a paragraph inside a table
	 * cell takes only itself; `<tr>` comes before `<div>`, so a wrapper does not swallow the page
	 * because one row in it mentioned a score.
	 *
	 * `<td>` IS DELIBERATELY ABSENT, and a test caught its absence being needed. A table row is
	 * a label and a value: dropping only the cell holding `{percent}` leaves `Your mark` sitting
	 * in a row with nothing beside it — the withheld sentence shown without its number, which is
	 * the exact failure this whole mechanism exists to prevent — and leaves the row a column
	 * short of every other row. The unit that means something in a table is the ROW.
	 *
	 * @var string[]
	 */
	private const BLOCK_TAGS = array( 'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'tr', 'div' );

	/**
	 * Replace every `{token}` in a string.
	 *
	 * ────────────────────────────────────────────────────────────────────────────────────
	 * A BLANKED TOKEN TAKES ITS WHOLE LINE WITH IT.
	 *
	 * Blanking `{score}` in place turns "Score: {score} of {max_score} ({percent}%)" into
	 * "Score:  of  (%)" — which is not withholding the mark, it is showing the reader the
	 * shape of the sentence that was withheld. A line exists to carry the values in it, so a
	 * line whose values are all gone has nothing left to say and is dropped.
	 *
	 * Only lines referencing a token in `$blanked` are affected. A token that is simply
	 * empty — an examinee with no name — leaves its line alone, because the rest of that
	 * sentence still reads.
	 * ────────────────────────────────────────────────────────────────────────────────────
	 *
	 * IN HTML MODE THE LINE RULE BECOMES A BLOCK RULE, because an HTML body has no lines — see
	 * `drop_blocks_using()`. Both run: a template may be HTML and still contain newlines, and a
	 * token that ended up in neither a block nor a line is still blanked by the substitution
	 * below, so the fallback is "shows less than intended", never "leaks the mark".
	 *
	 * AND IN HTML MODE EVERY VALUE IS ESCAPED. `strtr` is a blind splice: an exam called
	 * `Fractions <½>` is harmless in a plain-text mail and is broken markup in an HTML one, and
	 * an examinee display name is attacker-controlled on a site that lets people register. The
	 * one exception is `RAW_TOKENS`, whose value is markup by contract.
	 *
	 * @param string               $text    Subject or body.
	 * @param array<string, mixed> $values  Token values.
	 * @param string[]             $blanked Tokens deliberately emptied.
	 * @param bool                 $html    Whether the body is HTML rather than plain text.
	 */
	public static function apply( string $text, array $values, array $blanked = array(), bool $html = false ): string {
		if ( array() !== $blanked ) {
			if ( $html ) {
				$text = self::drop_blocks_using( $text, $blanked );
			}

			$text = self::drop_lines_using( $text, $blanked );
		}

		$replacements = array();

		foreach ( $values as $key => $value ) {
			$replacement = is_scalar( $value ) ? (string) $value : '';

			if ( $html && ! in_array( $key, self::RAW_TOKENS, true ) ) {
				$replacement = esc_html( $replacement );
			}

			$replacements[ '{' . $key . '}' ] = $replacement;
		}

		/*
		 * Any token the caller did not supply is stripped rather than left standing. A
		 * template carrying `{certificate_url}` on a site without the Certificates module
		 * should read as a missing sentence, not as literal braces in somebody's inbox.
		 */
		$text = strtr( $text, $replacements );

		return (string) preg_replace( '/\{[a-z_]{1,40}\}/', '', $text );
	}

	/**
	 * Remove every line mentioning one of the given tokens.
	 *
	 * A subject line is never dropped — a message with no subject is worse than one whose
	 * subject mentions a score it does not go on to give.
	 *
	 * @param string   $text   Subject or body.
	 * @param string[] $tokens Token names.
	 */
	private static function drop_lines_using( string $text, array $tokens ): string {
		if ( ! str_contains( $text, "\n" ) ) {
			return $text;
		}

		$needles = array_map( static fn( string $name ): string => '{' . $name . '}', $tokens );

		$kept = array_filter(
			explode( "\n", $text ),
			static function ( string $line ) use ( $needles ): bool {
				foreach ( $needles as $needle ) {
					if ( str_contains( $line, $needle ) ) {
						return false;
					}
				}

				return true;
			}
		);

		return implode( "\n", $kept );
	}

	/**
	 * Remove the innermost block element wrapping each of the given tokens.
	 *
	 * ────────────────────────────────────────────────────────────────────────────────────
	 * THE HTML TWIN OF `drop_lines_using()`, AND THE REASON IT HAD TO EXIST.
	 *
	 * The plain-text rule is "a line whose values are all withheld has nothing left to say".
	 * An HTML body is ONE LINE, so that rule does not merely work less well on it — it does
	 * nothing at all, and `drop_lines_using()` returns early on a string with no newline.
	 * Ported naively, an unreleased result would have reached the recipient as
	 * `<p>Score:  of  (%)</p>`: the mark withheld, and the shape of the withheld sentence
	 * shown anyway, which is the exact failure the plain-text rule was written to prevent.
	 *
	 * THE POLICY IS "DROP THE NEAREST BLOCK ANCESTOR", and it is a choice among three:
	 *
	 *   - nearest block ancestor (this) — predictable, and it can take a neighbouring
	 *     sentence with it when an author writes two thoughts in one paragraph;
	 *   - sentence-level surgery — kinder to that author, and not reliably possible without
	 *     parsing prose;
	 *   - blank the token and leave the words — never drops anything wanted, and produces
	 *     the `Score:  of  (%)` this exists to avoid.
	 *
	 * The first is chosen because the failure modes are not symmetrical: losing a sentence
	 * the reader was meant to see is a template somebody notices and rewrites, while showing
	 * the skeleton of a withheld mark is a policy breach nobody sees.
	 *
	 * THE UNWRAPPED CASE IS THE ONE AN AUTHOR WILL ACTUALLY HIT — a token typed straight into
	 * the body with no `<p>` around it. Nothing matches, so this returns the text unchanged
	 * and the caller's line rule and blanking take over. That degrades to "less removed than
	 * ideal", never to "the number leaks", because the value itself is empty either way.
	 * ────────────────────────────────────────────────────────────────────────────────────
	 *
	 * @param string   $text   Body markup.
	 * @param string[] $tokens Token names.
	 */
	private static function drop_blocks_using( string $text, array $tokens ): string {
		foreach ( $tokens as $name ) {
			$needle = preg_quote( '{' . $name . '}', '#' );

			foreach ( self::BLOCK_TAGS as $tag ) {
				/*
				 * `(?:(?!</?TAG\b).)*?` is a tempered match: it crosses anything EXCEPT another
				 * opening or closing tag of the same name. That is what makes this pick the
				 * paragraph the token is in rather than starting at the first paragraph on the
				 * page and swallowing everything up to the token — the bug a plain `.*?` has,
				 * and one that reads as "the email lost its opening line" rather than as a
				 * regex fault.
				 */
				$pattern = '#<' . $tag . '\b[^>]*>'
					. '(?:(?!</?' . $tag . '\b).)*?'
					. $needle
					. '(?:(?!</?' . $tag . '\b).)*?'
					. '</' . $tag . '\s*>#is';

				$stripped = preg_replace( $pattern, '', $text );

				// A PCRE failure (backtrack limit on a pathological body) returns null. Keep
				// what we had and let the blanking below do its job — never return null into
				// somebody's outbox.
				if ( null === $stripped ) {
					continue;
				}

				if ( $stripped !== $text ) {
					$text = $stripped;

					// Innermost wins: this token is dealt with, so do not also drop the row or
					// the div that contained the paragraph we just removed.
					break;
				}
			}
		}

		return $text;
	}

	/**
	 * The values available for an attempt.
	 *
	 * @param array<string, mixed> $attempt  Attempt row.
	 * @param array<string, mixed> $exam     Exam row.
	 * @param string               $examinee Display name.
	 * @param bool                 $released Whether the mark may be disclosed.
	 *
	 * @return array<string, string>
	 */
	public static function for_attempt( array $attempt, array $exam, string $examinee, bool $released ): array {
		$values = array(
			'site_name'   => (string) get_bloginfo( 'name' ),
			'site_url'    => (string) home_url( '/' ),
			'name'        => $examinee,
			'exam_title'  => (string) ( $exam['title'] ?? '' ),
			'attempt_id'  => (string) ( $attempt['id'] ?? '' ),
			'submitted'   => self::local_date( $attempt['submitted'] ?? null ),
			'started'     => self::local_date( $attempt['started'] ?? null ),
			'result_url'  => self::result_url( (int) ( $attempt['id'] ?? 0 ) ),

			/*
			 * Formatted the way the site writes numbers, not to a precision chosen here. A
			 * candidate reading `{percent}` in an email and the same figure on the result
			 * page should not see two different numbers, which is what three call sites
			 * each picking their own `round()` produced.
			 */
			'score'       => plugixa_exam_format_number( (float) ( $attempt['score'] ?? 0 ) ),
			'max_score'   => plugixa_exam_format_number( (float) ( $attempt['max_score'] ?? 0 ) ),
			'percent'     => plugixa_exam_format_number( (float) ( $attempt['percent'] ?? 0 ) ),
			// A whole-number threshold somebody typed; see `plugixa_exam_format_number()`.
			'pass_mark'   => (string) round( (float) ( $exam['pass_mark'] ?? 0 ), 2 ),
			'outcome'     => ! empty( $attempt['passed'] )
				? __( 'Passed', 'plugixa-exam' )
				: __( 'Not passed', 'plugixa-exam' ),
		);

		if ( ! $released ) {
			foreach ( self::SCORE_TOKENS as $token ) {
				$values[ $token ] = '';
			}
		}

		return $values;
	}

	/**
	 * The tokens `for_attempt()` will have emptied.
	 *
	 * Handed to `apply()` so the lines carrying them go too. Separate from the values rather
	 * than inferred from empty strings, because "empty because withheld" and "empty because
	 * the guest gave no name" want opposite treatment.
	 *
	 * @param bool $released Whether the mark may be disclosed.
	 *
	 * @return string[]
	 */
	public static function blanked( bool $released ): array {
		return $released ? array() : self::SCORE_TOKENS;
	}

	/**
	 * A stored UTC datetime in the site's timezone and format.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function local_date( mixed $value ): string {
		// `plugixa_exam_timestamp()` rather than `strtotime( $value . ' UTC' )`, which is the
		// documented trap in this codebase: a bare timezone means "now, there", so a MySQL zero
		// date does not fail — it silently becomes the current time. This guarded against null
		// and the empty string and not against `0000-00-00 00:00:00`, which would have dated a
		// never-started attempt to the moment the email was written.
		$timestamp = plugixa_exam_timestamp( $value );

		if ( 0 === $timestamp ) {
			return '';
		}

		return (string) wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}

	/**
	 * Link to an attempt's result page.
	 *
	 * Asked of the Router through the container rather than assembled here, because the
	 * catalogue base is filterable and the site may not use pretty permalinks.
	 *
	 * @param int $attempt_id Attempt id.
	 */
	private static function result_url( int $attempt_id ): string {
		if ( 0 === $attempt_id ) {
			return '';
		}

		return \PlugixaExam\Core\Plugin::instance()
			->container()
			->get( \PlugixaExam\Core\Frontend\Router::class )
			->result_url( $attempt_id );
	}
}
