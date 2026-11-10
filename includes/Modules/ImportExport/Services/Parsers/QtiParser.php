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
 * QTI 2.x `assessmentItem`.
 *
 * The interoperability standard, and the most awkward to read: the question, the choices and
 * the correct answer live in three different places, joined by identifiers rather than by
 * nesting. A `<choiceInteraction>` holds the options, `<correctResponse>` names the right
 * ones by id, and the stem is whatever prose sits in `<itemBody>` outside the interaction.
 *
 * Namespaces are stripped before reading. QTI files in the wild declare the same elements
 * under `imsqti_v2p1`, `imsqti_v2p2`, a default namespace or no namespace at all, and a
 * reader that respects them has to try each in turn to find the same tag.
 */
final class QtiParser implements ParserInterface {

	public function id(): string {
		return 'qti';
	}

	public function label(): string {
		return __( 'QTI', 'plugixa-exam' );
	}

	/**
	 * @return string[]
	 */
	public function extensions(): array {
		return array( 'xml', 'qti' );
	}

	public function confidence( string $content, string $filename ): int {
		unset( $filename );

		return preg_match( '/<(\w+:)?assessmentItem\b/i', $content ) ? 95 : 0;
	}

	/**
	 * @param string $content The file's contents.
	 *
	 * @return array{questions: array<int, array<string, mixed>>, problems: string[]}
	 */
	public function parse( string $content ): array {
		$problem = null;
		$xml     = Xml::load( self::strip_namespaces( $content ), $problem );

		if ( null === $xml ) {
			return array( 'questions' => array(), 'problems' => array( (string) $problem ) );
		}

		// A single item, or a wrapper holding several.
		$items = 'assessmentItem' === $xml->getName()
			? array( $xml )
			: iterator_to_array( $xml->xpath( '//assessmentItem' ) ?: array() );

		if ( array() === $items ) {
			return array(
				'questions' => array(),
				'problems'  => array( __( 'No assessment items were found in the file.', 'plugixa-exam' ) ),
			);
		}

		$questions = array();
		$problems  = array();
		$number    = 0;

		foreach ( $items as $item ) {
			++$number;

			$question = $this->one( $item, $number, $problems );

			if ( null !== $question ) {
				$questions[] = $question;
			}
		}

		return array( 'questions' => $questions, 'problems' => $problems );
	}

	/**
	 * @param \SimpleXMLElement $item     One `<assessmentItem>`.
	 * @param int               $number   Which item, for messages.
	 * @param string[]          $problems Accumulated problems, by reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private function one( \SimpleXMLElement $item, int $number, array &$problems ): ?array {
		$title = trim( (string) ( $item['title'] ?? '' ) );
		$body  = $item->itemBody ?? null;

		if ( null === $body ) {
			$problems[] = sprintf(
				/* translators: %d: item number in the file. */
				__( 'Item %d: no itemBody, so it was skipped.', 'plugixa-exam' ),
				$number
			);

			return null;
		}

		$correct = self::correct_ids( $item );

		$choice = $body->choiceInteraction ?? null;

		if ( null !== $choice ) {
			return self::choice( $item, $body, $choice, $correct, $title, $number, $problems );
		}

		// A text-entry or extended-text item: short answer or essay.
		$stem = self::stem( $body, null );

		if ( '' === $stem ) {
			$problems[] = sprintf(
				/* translators: %d: item number in the file. */
				__( 'Item %d: no question text, so it was skipped.', 'plugixa-exam' ),
				$number
			);

			return null;
		}

		$base = array(
			'title'          => '' === $title ? AikenParser::title_from( $stem ) : $title,
			'stem'           => $stem,
			'default_points' => 1,
			'published'      => true,
		);

		if ( isset( $body->extendedTextInteraction ) ) {
			return $base + array( 'question_type' => 'essay', 'answer_key' => array() );
		}

		return $base + array(
			'question_type' => 'short_answer',
			'answer_key'    => array(
				// For a text entry the correct response values ARE the accepted strings,
				// not identifiers pointing at choices.
				'accepted'         => $correct,
				'case_insensitive' => true,
				'trim'             => true,
			),
		);
	}

	/**
	 * A `<choiceInteraction>` item.
	 *
	 * @param \SimpleXMLElement $item     The item.
	 * @param \SimpleXMLElement $body     Its body.
	 * @param \SimpleXMLElement $choice   The interaction.
	 * @param string[]          $correct  Identifiers of the right choices.
	 * @param string            $title    Item title.
	 * @param int               $number   Item number, for messages.
	 * @param string[]          $problems Accumulated problems, by reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function choice(
		\SimpleXMLElement $item,
		\SimpleXMLElement $body,
		\SimpleXMLElement $choice,
		array $correct,
		string $title,
		int $number,
		array &$problems
	): ?array {
		unset( $item );

		$options = array();

		foreach ( $choice->simpleChoice as $node ) {
			$identifier = trim( (string) ( $node['identifier'] ?? '' ) );

			$options[] = array(
				'option_text' => trim( wp_strip_all_tags( (string) $node->asXML() === '' ? (string) $node : self::inner( $node ) ) ),
				'is_correct'  => in_array( $identifier, $correct, true ),
			);
		}

		if ( array() === $options ) {
			$problems[] = sprintf(
				/* translators: %d: item number in the file. */
				__( 'Item %d: the interaction has no choices.', 'plugixa-exam' ),
				$number
			);

			return null;
		}

		if ( array() === array_filter( $options, static fn( array $o ): bool => $o['is_correct'] ) ) {
			$problems[] = sprintf(
				/* translators: %d: item number in the file. */
				__( 'Item %d: no choice matches the correct response, so it cannot be graded.', 'plugixa-exam' ),
				$number
			);

			return null;
		}

		// `maxChoices` of exactly 1 means single-answer; 0 means unlimited.
		$max    = (int) ( (string) ( $choice['maxChoices'] ?? '1' ) );
		$prompt = self::stem( $body, $choice );

		$stem = '' !== $prompt ? $prompt : trim( (string) ( $choice->prompt ?? '' ) );

		return array(
			'title'          => '' === $title ? AikenParser::title_from( $stem ) : $title,
			'question_type'  => 1 === $max ? 'mcq_single' : 'mcq_multiple',
			'stem'           => '' === $stem ? '<p></p>' : $stem,
			'default_points' => 1,
			'published'      => true,
			'options'        => $options,
		);
	}

	/**
	 * Identifiers or values listed in `<correctResponse>`.
	 *
	 * @param \SimpleXMLElement $item The item.
	 *
	 * @return string[]
	 */
	private static function correct_ids( \SimpleXMLElement $item ): array {
		$values = array();

		foreach ( $item->xpath( './/correctResponse/value' ) ?: array() as $value ) {
			$text = trim( (string) $value );

			if ( '' !== $text ) {
				$values[] = $text;
			}
		}

		return $values;
	}

	/**
	 * The prose in an item body, excluding the interaction itself.
	 *
	 * @param \SimpleXMLElement      $body        The item body.
	 * @param \SimpleXMLElement|null $interaction The interaction to leave out.
	 */
	private static function stem( \SimpleXMLElement $body, ?\SimpleXMLElement $interaction ): string {
		$parts = array();

		foreach ( $body->children() as $child ) {
			$name = $child->getName();

			// Every interaction element ends in "Interaction"; skipping by suffix means a
			// type this parser does not handle still contributes no noise to the stem.
			if ( str_ends_with( $name, 'Interaction' ) ) {
				if ( null !== $interaction && isset( $child->prompt ) ) {
					$parts[] = trim( (string) $child->prompt );
				}

				continue;
			}

			$text = trim( self::inner( $child ) );

			if ( '' !== $text ) {
				$parts[] = $text;
			}
		}

		$joined = trim( implode( ' ', array_filter( $parts ) ) );

		if ( '' === $joined ) {
			return '';
		}

		return str_contains( $joined, '<' ) ? $joined : '<p>' . esc_html( $joined ) . '</p>';
	}

	/**
	 * A node's inner XML, so inline markup in a stem survives.
	 *
	 * @param \SimpleXMLElement $node The node.
	 */
	private static function inner( \SimpleXMLElement $node ): string {
		$xml = (string) $node->asXML();

		// Strip the node's own opening and closing tag, keeping what is between them.
		return (string) preg_replace( array( '/^<[^>]+>/', '/<\/[^>]+>$/' ), '', $xml );
	}

	/**
	 * Remove namespace prefixes and declarations.
	 *
	 * @param string $content The file's contents.
	 */
	private static function strip_namespaces( string $content ): string {
		$content = (string) preg_replace( '/\sxmlns(:\w+)?\s*=\s*"[^"]*"/i', '', $content );

		return (string) preg_replace( '/<(\/?)\w+:/', '<$1', $content );
	}
}
