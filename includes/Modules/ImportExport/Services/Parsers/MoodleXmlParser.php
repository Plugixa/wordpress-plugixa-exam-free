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
 * Moodle XML: `<quiz><question type="…">`.
 *
 * The richest of the interchange formats and the one most banks are actually stored in. Only
 * the types this plugin has an equivalent for are imported; the rest are reported by name so
 * an author knows exactly what did not come across rather than finding a short bank later.
 */
final class MoodleXmlParser implements ParserInterface {

	/** Moodle's type names mapped onto this plugin's. */
	private const TYPES = array(
		'multichoice'  => 'mcq_single',
		'truefalse'    => 'true_false',
		'shortanswer'  => 'short_answer',
		'numerical'    => 'short_answer',
		'essay'        => 'essay',
		'matching'     => 'matching',
		'ordering'     => 'ordering',
	);

	public function id(): string {
		return 'moodle_xml';
	}

	public function label(): string {
		return __( 'Moodle XML', 'plugixa-exam' );
	}

	/**
	 * @return string[]
	 */
	public function extensions(): array {
		return array( 'xml' );
	}

	public function confidence( string $content, string $filename ): int {
		unset( $filename );

		return preg_match( '/<quiz\b/i', $content ) ? 95 : 0;
	}

	/**
	 * @param string $content The file's contents.
	 *
	 * @return array{questions: array<int, array<string, mixed>>, problems: string[]}
	 */
	public function parse( string $content ): array {
		$problem = null;
		$xml     = Xml::load( $content, $problem );

		if ( null === $xml ) {
			return array( 'questions' => array(), 'problems' => array( (string) $problem ) );
		}

		$questions = array();
		$problems  = array();
		$skipped   = array();
		$number    = 0;

		foreach ( $xml->question as $node ) {
			++$number;

			$moodle_type = strtolower( trim( (string) $node['type'] ) );

			// Moodle writes the bank's category as a pseudo-question. Silently ignored:
			// reporting it as an unsupported type would put a scary line in every import.
			if ( 'category' === $moodle_type ) {
				continue;
			}

			if ( ! isset( self::TYPES[ $moodle_type ] ) ) {
				$skipped[ $moodle_type ] = ( $skipped[ $moodle_type ] ?? 0 ) + 1;

				continue;
			}

			$question = $this->one( $node, self::TYPES[ $moodle_type ], $moodle_type, $number, $problems );

			if ( null !== $question ) {
				$questions[] = $question;
			}
		}

		foreach ( $skipped as $type => $count ) {
			$problems[] = sprintf(
				/* translators: 1: how many, 2: the Moodle question type. */
				_n(
					'%1$d question of type “%2$s” was skipped: this site has no equivalent.',
					'%1$d questions of type “%2$s” were skipped: this site has no equivalent.',
					$count,
					'plugixa-exam'
				),
				$count,
				$type
			);
		}

		return array( 'questions' => $questions, 'problems' => $problems );
	}

	/**
	 * @param \SimpleXMLElement $node        The `<question>` element.
	 * @param string            $type        This plugin's type.
	 * @param string            $moodle_type Moodle's type.
	 * @param int               $number      Which question, for messages.
	 * @param string[]          $problems    Accumulated problems, by reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private function one(
		\SimpleXMLElement $node,
		string $type,
		string $moodle_type,
		int $number,
		array &$problems
	): ?array {
		$stem = Xml::text( $node->questiontext ?? null );

		if ( '' === $stem ) {
			$problems[] = sprintf(
				/* translators: %d: question number in the file. */
				__( 'Question %d: no question text, so it was skipped.', 'plugixa-exam' ),
				$number
			);

			return null;
		}

		$title = Xml::text( $node->name ?? null );

		$question = array(
			'title'          => '' === $title ? AikenParser::title_from( $stem ) : $title,
			'question_type'  => $type,
			'stem'           => $stem,
			'explanation'    => Xml::text( $node->generalfeedback ?? null ),
			'default_points' => max( 0.0, (float) ( (string) ( $node->defaultgrade ?? '1' ) ) ),
			'published'      => true,
		);

		if ( 'essay' === $type ) {
			return $question + array( 'answer_key' => array() );
		}

		$answers = self::answers( $node );

		if ( 'true_false' === $type ) {
			$correct = '';

			foreach ( $answers as $answer ) {
				if ( $answer['fraction'] > 0 ) {
					$correct = strtolower( $answer['text'] );
				}
			}

			return $question + array(
				'answer_key' => array( 'correct' => in_array( $correct, array( 'true', 't', '1', 'yes' ), true ) ),
			);
		}

		if ( 'short_answer' === $type ) {
			$accepted = array();

			foreach ( $answers as $answer ) {
				if ( $answer['fraction'] > 0 && '' !== $answer['text'] ) {
					$accepted[] = $answer['text'];
				}
			}

			$key = array(
				'accepted'         => $accepted,
				'case_insensitive' => true,
				'trim'             => true,
			);

			/*
			 * A Moodle `numerical` carries a tolerance per answer. Only the first is kept —
			 * this plugin has one tolerance per question, and quietly averaging several
			 * would produce a mark nobody could explain.
			 */
			if ( 'numerical' === $moodle_type ) {
				$key['numeric']   = true;
				$key['tolerance'] = (float) ( (string) ( $node->answer->tolerance ?? '0' ) );
			}

			return $question + array( 'answer_key' => $key );
		}

		if ( array() === $answers ) {
			$problems[] = sprintf(
				/* translators: %d: question number in the file. */
				__( 'Question %d: no answers, so it was skipped.', 'plugixa-exam' ),
				$number
			);

			return null;
		}

		$correct = array_filter( $answers, static fn( array $a ): bool => $a['fraction'] > 0 );

		if ( array() === $correct ) {
			$problems[] = sprintf(
				/* translators: %d: question number in the file. */
				__( 'Question %d: no answer is marked correct, so it cannot be graded.', 'plugixa-exam' ),
				$number
			);

			return null;
		}

		// Moodle's `single` attribute is authoritative; the count of correct answers is the
		// fallback for files that omit it.
		$single = isset( $node->single )
			? in_array( strtolower( trim( (string) $node->single ) ), array( 'true', '1' ), true )
			: count( $correct ) <= 1;

		$question['question_type'] = $single ? 'mcq_single' : 'mcq_multiple';

		$question['options'] = array_map(
			static fn( array $a ): array => array(
				'option_text' => $a['text'],
				'is_correct'  => $a['fraction'] > 0,
				'feedback'    => $a['feedback'],
			),
			$answers
		);

		return $question;
	}

	/**
	 * The `<answer>` children of a question.
	 *
	 * @param \SimpleXMLElement $node The `<question>` element.
	 *
	 * @return array<int, array{text: string, fraction: float, feedback: string}>
	 */
	private static function answers( \SimpleXMLElement $node ): array {
		$answers = array();

		foreach ( $node->answer as $answer ) {
			$answers[] = array(
				'text'     => Xml::text( $answer ),
				'fraction' => (float) ( (string) ( $answer['fraction'] ?? '0' ) ),
				'feedback' => Xml::text( $answer->feedback ?? null ),
			);
		}

		return $answers;
	}
}
