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
 * What a cloze answer key is allowed to contain.
 *
 * Runs on save, through `plugixa_exam_normalize_answer_key`. Everything here is authored by
 * a trusted role, which is a reason to validate rather than a reason not to: an author who
 * mistypes a blank produces a question that grades wrongly rather than one that errors, and
 * nobody finds out until an examinee disputes a mark.
 *
 * THE SHAPE, from the specification §4.2:
 *
 *   cloze     { blanks: [ { accepted: [], case_insensitive, trim } ], partial_credit: bool }
 */
final class AnswerKeys {

	/**
	 * Ceiling on blanks.
	 *
	 * Not a guess at what anybody needs — a bound on what one question can cost to render
	 * and grade. An answer key is JSON in a `longtext`, and without a limit a paste accident
	 * becomes a question that takes a second to score for every examinee.
	 */
	private const MAX_PARTS = 100;

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_normalize_answer_key', array( $this, 'normalize' ), 10, 2 );
	}

	/**
	 * @param array<string, mixed> $key  The raw answer key.
	 * @param string               $type The question type.
	 *
	 * @return array<string, mixed>
	 */
	public function normalize( array $key, string $type ): array {
		if ( 'cloze' !== $type ) {
			// Not ours. Returning the key untouched matters — every other type passes
			// through this same filter.
			return $key;
		}

		return array(
			'blanks'         => $this->blanks( $key ),
			'partial_credit' => $this->partial_credit( $key ),
		);
	}

	/**
	 * Partial credit, defaulting to ON.
	 *
	 * The opposite of the `mcq_multiple` default, and deliberately. A five-gap passage marked
	 * all-or-nothing scores zero for one misspelt word, which is a harsher instrument than
	 * most authors intend when they pick the type. An author who wants all-or-nothing says
	 * so; an author who does not think about it gets the kinder reading.
	 *
	 * @param array<string, mixed> $key Raw key.
	 */
	private function partial_credit( array $key ): bool {
		return ! isset( $key['partial_credit'] ) || ! empty( $key['partial_credit'] );
	}

	/**
	 * Cloze blanks, in the order their markers appear in the stem.
	 *
	 * Position is the identity here: the first blank answers `[[1]]`. So a blank with no
	 * accepted answers is KEPT rather than filtered out — dropping it would shift every
	 * later blank up by one and silently re-point the whole question at the wrong markers.
	 * An empty blank is simply never correct.
	 *
	 * @param array<string, mixed> $key Raw key.
	 *
	 * @return array<int, array{accepted: string[], case_insensitive: bool, trim: bool}>
	 */
	private function blanks( array $key ): array {
		$blanks = array();

		foreach ( array_slice( (array) ( $key['blanks'] ?? array() ), 0, self::MAX_PARTS ) as $blank ) {
			$blank = is_array( $blank ) ? $blank : array();

			$accepted = array();

			foreach ( (array) ( $blank['accepted'] ?? array() ) as $value ) {
				$value = trim( sanitize_text_field( (string) $value ) );

				if ( '' !== $value ) {
					$accepted[] = $value;
				}
			}

			$blanks[] = array(
				'accepted'         => array_values( array_unique( $accepted ) ),
				'case_insensitive' => ! isset( $blank['case_insensitive'] ) || ! empty( $blank['case_insensitive'] ),
				'trim'             => ! isset( $blank['trim'] ) || ! empty( $blank['trim'] ),
			);
		}

		return $blanks;
	}

	/**
	 * How many `[[n]]` markers a cloze stem contains.
	 *
	 * Shared by the runner payload and — as the same regular expression — the authoring
	 * screen's warning, so the two cannot disagree about what counts as a marker.
	 *
	 * @param string $stem The question stem, as HTML.
	 */
	public static function count_markers( string $stem ): int {
		return (int) preg_match_all( '/\[\[\s*\d+\s*\]\]/', wp_strip_all_tags( $stem ) );
	}
}
