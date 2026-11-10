<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\ImportExport\Services\Parsers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aiken: the format teachers actually type by hand.
 *
 * A stem on one line, lettered options on the lines below, then `ANSWER: B`. It expresses
 * exactly one thing — a single-answer multiple choice — and that limitation is why it is
 * popular: there is nothing to get wrong.
 *
 *     What is the capital of France?
 *     A. Lyon
 *     B. Paris
 *     C. Nice
 *     ANSWER: B
 */
final class AikenParser implements ParserInterface {

	public function id(): string {
		return 'aiken';
	}

	public function label(): string {
		return __( 'Aiken', 'plugixa-exam' );
	}

	/**
	 * @return string[]
	 */
	public function extensions(): array {
		return array( 'txt' );
	}

	public function confidence( string $content, string $filename ): int {
		unset( $filename );

		// The ANSWER line is the format's only unambiguous marker. Lettered options alone
		// appear in GIFT, in Moodle XML and in any prose that happens to contain a list.
		if ( ! preg_match( '/^\s*ANSWER\s*:\s*[A-Z]\s*$/mi', $content ) ) {
			return 0;
		}

		// Beaten by GIFT if the file also contains GIFT's braces, since a GIFT file may
		// legitimately contain the word ANSWER inside a stem.
		return str_contains( $content, '{' ) ? 40 : 90;
	}

	/**
	 * @param string $content The file's contents.
	 *
	 * @return array{questions: array<int, array<string, mixed>>, problems: string[]}
	 */
	public function parse( string $content ): array {
		$questions = array();
		$problems  = array();

		$lines   = preg_split( '/\R/', $content ) ?: array();
		$stem    = '';
		$options = array();
		$line_no = 0;
		$started = 0;

		foreach ( $lines as $raw ) {
			++$line_no;

			$line = trim( (string) $raw );

			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/^ANSWER\s*:\s*([A-Z])\s*$/i', $line, $matches ) ) {
				$letter = strtoupper( $matches[1] );
				$index  = ord( $letter ) - ord( 'A' );

				if ( '' === $stem || array() === $options ) {
					$problems[] = sprintf(
						/* translators: %d: line number. */
						__( 'Line %d: an ANSWER line with no question above it.', 'plugixa-exam' ),
						$line_no
					);
				} elseif ( ! isset( $options[ $index ] ) ) {
					$problems[] = sprintf(
						/* translators: 1: line number, 2: the answer letter. */
						__( 'Line %1$d: the answer is %2$s but there is no such option.', 'plugixa-exam' ),
						$line_no,
						$letter
					);
				} else {
					$questions[] = self::build( $stem, $options, $index );
				}

				$stem    = '';
				$options = array();

				continue;
			}

			// `A. text`, `A) text` or `A text`.
			if ( '' !== $stem && preg_match( '/^([A-Z])[.)]?\s+(.+)$/', $line, $matches ) ) {
				$options[ ord( strtoupper( $matches[1] ) ) - ord( 'A' ) ] = trim( $matches[2] );

				continue;
			}

			/*
			 * Anything else starts a new question. A stem already holding options means the
			 * previous block never had its ANSWER line — reported rather than guessed at,
			 * because a multiple-choice question with no marked answer cannot be graded.
			 */
			if ( '' !== $stem && array() !== $options ) {
				$problems[] = sprintf(
					/* translators: %d: line number. */
					__( 'Line %d: the previous question has no ANSWER line and was skipped.', 'plugixa-exam' ),
					$started
				);
			}

			$stem    = $line;
			$options = array();
			$started = $line_no;
		}

		if ( '' !== $stem && array() !== $options ) {
			$problems[] = sprintf(
				/* translators: %d: line number. */
				__( 'Line %d: the last question has no ANSWER line and was skipped.', 'plugixa-exam' ),
				$started
			);
		}

		return array( 'questions' => $questions, 'problems' => $problems );
	}

	/**
	 * @param string               $stem    Question text.
	 * @param array<int, string>   $options Option text by index.
	 * @param int                  $correct Index of the right one.
	 *
	 * @return array<string, mixed>
	 */
	private static function build( string $stem, array $options, int $correct ): array {
		ksort( $options );

		return array(
			// Aiken carries no separate title, so the stem doubles as one. Trimmed, because
			// the bank list is a single line and a 400-character stem makes it unreadable.
			'title'          => self::title_from( $stem ),
			'question_type'  => 'mcq_single',
			'stem'           => '<p>' . esc_html( $stem ) . '</p>',
			'default_points' => 1,
			'published'      => true,
			'options'        => array_map(
				static fn( int $index, string $text ): array => array(
					'option_text' => $text,
					'is_correct'  => $index === $correct,
				),
				array_keys( $options ),
				array_values( $options )
			),
		);
	}

	/**
	 * A bank title from a stem.
	 *
	 * @param string $stem Question text.
	 */
	public static function title_from( string $stem ): string {
		$flat = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $stem ) ) );

		return mb_strlen( $flat ) > 80 ? mb_substr( $flat, 0, 77 ) . '…' : $flat;
	}
}
