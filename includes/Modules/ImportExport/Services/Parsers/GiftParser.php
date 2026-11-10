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
 * GIFT: Moodle's plain-text format.
 *
 * The stem is prose; the answers live in braces. What is inside the braces decides the type:
 *
 *     ::Title:: Capital of France? {~Lyon =Paris ~Nice}      single choice
 *     The Earth orbits the Sun. {T}                          true/false
 *     Two plus two is {=4 =four}                             short answer
 *     Explain the seasons. {}                                essay
 *
 * `=` marks a correct answer, `~` a wrong one, `#` begins per-option feedback, and `\` escapes
 * any of them. The escaping is the part everything else depends on: a stem containing a price
 * like `\{50\}` must not be read as an answer block.
 */
final class GiftParser implements ParserInterface {

	public function id(): string {
		return 'gift';
	}

	public function label(): string {
		return __( 'GIFT', 'plugixa-exam' );
	}

	/**
	 * @return string[]
	 */
	public function extensions(): array {
		return array( 'txt', 'gift' );
	}

	public function confidence( string $content, string $filename ): int {
		unset( $filename );

		// An unescaped brace pair containing at least one answer marker.
		return preg_match( '/(?<!\\\\)\{[^}]*[=~TF][^}]*(?<!\\\\)\}/', $content ) ? 85 : 0;
	}

	/**
	 * @param string $content The file's contents.
	 *
	 * @return array{questions: array<int, array<string, mixed>>, problems: string[]}
	 */
	public function parse( string $content ): array {
		$questions = array();
		$problems  = array();

		foreach ( self::blocks( $content ) as $index => $block ) {
			$question = $this->one( $block, $index + 1, $problems );

			if ( null !== $question ) {
				$questions[] = $question;
			}
		}

		return array( 'questions' => $questions, 'problems' => $problems );
	}

	/**
	 * Split the file into question blocks.
	 *
	 * Blank-line separated, with `//` comment lines removed first. A comment inside a stem is
	 * not a thing GIFT supports, so stripping whole lines is safe.
	 *
	 * @param string $content The file's contents.
	 *
	 * @return string[]
	 */
	private static function blocks( string $content ): array {
		$content = (string) preg_replace( '/^\s*\/\/.*$/m', '', $content );

		return array_values(
			array_filter(
				array_map( 'trim', preg_split( '/\R\s*\R/', $content ) ?: array() ),
				static fn( string $block ): bool => '' !== $block
			)
		);
	}

	/**
	 * One GIFT block.
	 *
	 * @param string   $block    The block.
	 * @param int      $number   Which block, for error messages.
	 * @param string[] $problems Accumulated problems, by reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private function one( string $block, int $number, array &$problems ): ?array {
		$title = '';

		// `::Title::` prefix.
		if ( preg_match( '/^::(.*?)::\s*(.*)$/s', $block, $matches ) ) {
			$title = trim( $matches[1] );
			$block = trim( $matches[2] );
		}

		// The answer block: the last unescaped brace pair.
		if ( ! preg_match( '/^(.*?)(?<!\\\\)\{(.*)(?<!\\\\)\}\s*$/s', $block, $matches ) ) {
			$problems[] = sprintf(
				/* translators: %d: question number in the file. */
				__( 'Question %d: no answer block in braces, so it was skipped.', 'plugixa-exam' ),
				$number
			);

			return null;
		}

		$stem   = self::unescape( trim( $matches[1] ) );
		$answer = trim( $matches[2] );

		if ( '' === $stem ) {
			$problems[] = sprintf(
				/* translators: %d: question number in the file. */
				__( 'Question %d: no question text before the braces.', 'plugixa-exam' ),
				$number
			);

			return null;
		}

		$base = array(
			'title'          => '' === $title ? AikenParser::title_from( $stem ) : $title,
			'stem'           => '<p>' . esc_html( $stem ) . '</p>',
			'default_points' => 1,
			'published'      => true,
		);

		// Empty braces: an essay, which GIFT has no other way to express.
		if ( '' === $answer ) {
			return $base + array( 'question_type' => 'essay', 'answer_key' => array() );
		}

		// A bare T/F/TRUE/FALSE.
		if ( preg_match( '/^(T|F|TRUE|FALSE)$/i', $answer, $tf ) ) {
			return $base + array(
				'question_type' => 'true_false',
				'answer_key'    => array( 'correct' => in_array( strtoupper( $tf[1] ), array( 'T', 'TRUE' ), true ) ),
			);
		}

		$parsed = self::answers( $answer );

		if ( array() === $parsed ) {
			$problems[] = sprintf(
				/* translators: %d: question number in the file. */
				__( 'Question %d: the braces contain no answers.', 'plugixa-exam' ),
				$number
			);

			return null;
		}

		$correct = array_values( array_filter( $parsed, static fn( array $a ): bool => $a['correct'] ) );

		/*
		 * No `~` anywhere means a short answer: GIFT writes accepted spellings as several
		 * `=` entries with no wrong ones. A multiple choice with every option correct is not
		 * a thing anybody writes, so this heuristic is safe and is what Moodle itself does.
		 */
		$has_wrong = (bool) array_filter( $parsed, static fn( array $a ): bool => ! $a['correct'] );

		if ( ! $has_wrong ) {
			return $base + array(
				'question_type' => 'short_answer',
				'answer_key'    => array(
					'accepted'         => array_map( static fn( array $a ): string => $a['text'], $correct ),
					'case_insensitive' => true,
					'trim'             => true,
				),
			);
		}

		if ( array() === $correct ) {
			$problems[] = sprintf(
				/* translators: %d: question number in the file. */
				__( 'Question %d: no option is marked correct, so it cannot be graded.', 'plugixa-exam' ),
				$number
			);

			return null;
		}

		return $base + array(
			// More than one `=` among wrong answers means several may be chosen.
			'question_type' => count( $correct ) > 1 ? 'mcq_multiple' : 'mcq_single',
			'options'       => array_map(
				static fn( array $a ): array => array(
					'option_text' => $a['text'],
					'is_correct'  => $a['correct'],
					'feedback'    => $a['feedback'],
				),
				$parsed
			),
		);
	}

	/**
	 * Split an answer block into its entries.
	 *
	 * Walked character by character rather than split on `[=~]`, because both markers can
	 * appear escaped inside answer text and a regex split would cut a `\=` in half.
	 *
	 * @param string $answer Inside of the braces.
	 *
	 * @return array<int, array{text: string, correct: bool, feedback: string}>
	 */
	private static function answers( string $answer ): array {
		$entries = array();
		$current = null;
		$buffer  = '';
		$target  = 'text';
		$length  = strlen( $answer );

		$commit = static function () use ( &$entries, &$current, &$buffer, &$target ): void {
			if ( null === $current ) {
				return;
			}

			$current[ $target ] = trim( self::unescape( $buffer ) );

			// Weight prefixes like `%50%` are dropped: this plugin's partial credit is a
			// per-question policy, not a per-option weight, so keeping the number would
			// imply a behaviour that does not exist.
			$current['text'] = (string) preg_replace( '/^%-?\d+(\.\d+)?%\s*/', '', (string) $current['text'] );

			$entries[] = $current;
			$current   = null;
			$buffer    = '';
			$target    = 'text';
		};

		for ( $i = 0; $i < $length; $i++ ) {
			$char = $answer[ $i ];

			if ( '\\' === $char && $i + 1 < $length ) {
				$buffer .= $char . $answer[ ++$i ];

				continue;
			}

			if ( '=' === $char || '~' === $char ) {
				$commit();

				$current = array( 'text' => '', 'correct' => '=' === $char, 'feedback' => '' );

				continue;
			}

			if ( '#' === $char && null !== $current && 'text' === $target ) {
				$current['text'] = trim( self::unescape( $buffer ) );
				$buffer          = '';
				$target          = 'feedback';

				continue;
			}

			$buffer .= $char;
		}

		$commit();

		return array_values(
			array_filter( $entries, static fn( array $entry ): bool => '' !== $entry['text'] )
		);
	}

	/**
	 * Undo GIFT's backslash escapes.
	 *
	 * @param string $text Escaped text.
	 */
	private static function unescape( string $text ): string {
		return str_replace(
			array( '\\=', '\\~', '\\#', '\\{', '\\}', '\\:', '\\\\' ),
			array( '=', '~', '#', '{', '}', ':', '\\' ),
			$text
		);
	}
}
