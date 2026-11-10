<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\ImportExport\Services;

use PlugixaExam\Modules\Questions\Repositories\QuestionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes a question bank out.
 */
final class Exporter {

	/**
	 * @param QuestionRepository $questions The bank.
	 */
	public function __construct( private readonly QuestionRepository $questions ) {}

	/**
	 * The formats this build can write.
	 *
	 * @return array<string, string>
	 */
	public static function formats(): array {
		return array(
			'csv'        => __( 'CSV', 'plugixa-exam' ),
			'moodle_xml' => __( 'Moodle XML', 'plugixa-exam' ),
			'qti'        => __( 'QTI 2.1', 'plugixa-exam' ),
		);
	}

	/**
	 * Export questions.
	 *
	 * @param string $format     One of `formats()`.
	 * @param int[]  $ids        Question ids, or empty for everything.
	 * @param int    $subject_id Narrow to one subject, or 0.
	 *
	 * @return array{filename: string, mime: string, body: string, count: int}
	 */
	public function export( string $format, array $ids = array(), int $subject_id = 0 ): array {
		$rows = $this->rows( $ids, $subject_id );

		$body = match ( $format ) {
			'moodle_xml' => $this->moodle_xml( $rows ),
			'qti'        => $this->qti( $rows ),
			default      => $this->csv( $rows ),
		};

		$extension = 'csv' === $format ? 'csv' : 'xml';
		$mime      = 'csv' === $format ? 'text/csv' : 'application/xml';

		return array(
			'filename' => sprintf( 'plugixa-exam-questions-%s.%s', gmdate( 'Y-m-d' ), $extension ),
			'mime'     => $mime,
			'body'     => $body,
			'count'    => count( $rows ),
		);
	}

	/**
	 * The questions to write, with their options.
	 *
	 * @param int[] $ids        Question ids, or empty for everything.
	 * @param int   $subject_id Narrow to one subject, or 0.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( array $ids, int $subject_id ): array {
		$query = $this->questions->query()->order_by( 't.id', 'asc' );

		if ( array() !== $ids ) {
			$query->where_in( 't.id', array_map( 'absint', $ids ) );
		}

		if ( 0 !== $subject_id ) {
			/** This filter is documented in includes/Modules/Questions/Http/QuestionsController.php */
			$subtree = (array) apply_filters( 'plugixa_exam_subject_subtree_ids', array( $subject_id ), $subject_id );

			$query->where_in( 't.subject_id', array_map( 'intval', $subtree ) );
		}

		// Capped rather than unbounded: this builds the whole file in memory, and a bank of
		// a hundred thousand questions would exhaust the process rather than fail politely.
		$rows = $query->paginate( 5000 )->get();

		foreach ( $rows as $index => $row ) {
			/*
			 * CAST, because `QueryBuilder::get()` does not.
			 *
			 * `Repository::find()` casts; a raw query hands back exactly what MySQL returned,
			 * so `answer_key` arrives as the JSON STRING it is stored as. Encoding that
			 * string again produced a doubly-encoded cell, and every re-import of an exported
			 * short answer came back with no accepted answers — refused by the very
			 * validation that should have caught the export being wrong.
			 *
			 * `QuestionRepository::cast()` says as much in its own docblock: a caller that
			 * forgets gets a string where it expects an array. This is that caller.
			 */
			$row = $this->questions->cast( $row );

			$row['options']      = $this->questions->options( (int) $row['id'] );
			$rows[ $index ]      = $row;
		}

		return $rows;
	}

	/* --------------------------------------------------------------------- csv */

	/**
	 * CSV, lossless.
	 *
	 * The simple columns keep the file editable in a spreadsheet; `options_json` and
	 * `answer_key` at the end preserve everything the simple columns cannot express. See
	 * `CsvParser` for the reading half of that bargain.
	 *
	 * @param array<int, array<string, mixed>> $rows Questions.
	 */
	private function csv( array $rows ): string {
		$widest = 0;

		foreach ( $rows as $row ) {
			$widest = max( $widest, count( (array) $row['options'] ) );
		}

		$header = array( 'title', 'question_type', 'stem', 'explanation', 'difficulty', 'default_points', 'published' );

		for ( $i = 1; $i <= $widest; $i++ ) {
			$header[] = 'option_' . $i;
		}

		$header[] = 'correct';
		$header[] = 'options_json';
		$header[] = 'answer_key';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- `php://temp` is an in-memory stream, not a file. `WP_Filesystem` cannot open one, and the point of using it is to touch no filesystem at all.
		$handle = fopen( 'php://temp', 'r+' );

		if ( false === $handle ) {
			return '';
		}

		/*
		 * AN EMPTY ESCAPE ARGUMENT, WHICH IS NOT PHP'S DEFAULT AND IS LOAD BEARING.
		 *
		 * With the default backslash escape, a cell holding JSON is written as
		 * triple-quoted text with backslash-escaped inner quotes. That is not RFC 4180, no
		 * spreadsheet reads it as intended, and `fgetcsv` does not reverse it reliably — so the
		 * lossless round trip this format promises quietly failed: an exported short answer came
		 * back with no accepted answers and was refused on re-import.
		 *
		 * `''` turns the mechanism off, so quotes are simply doubled. `CsvParser` reads with the
		 * same setting, and the two must stay in step.
		 */
		fputcsv( $handle, $header, ',', '"', '' );

		foreach ( $rows as $row ) {
			$options = (array) $row['options'];
			$correct = array();

			$line = array(
				$row['title'],
				$row['question_type'],
				$row['stem'],
				$row['explanation'],
				$row['difficulty'],
				$row['default_points'],
				empty( $row['published'] ) ? '0' : '1',
			);

			for ( $i = 0; $i < $widest; $i++ ) {
				$option = $options[ $i ] ?? null;

				$line[] = null === $option ? '' : (string) $option['option_text'];

				if ( null !== $option && ! empty( $option['is_correct'] ) ) {
					$correct[] = (string) ( $i + 1 );
				}
			}

			$line[] = implode( '|', $correct );
			$line[] = (string) wp_json_encode(
				array_map(
					static fn( array $option ): array => array(
						'option_text' => $option['option_text'],
						'is_correct'  => (bool) $option['is_correct'],
						'match_key'   => $option['match_key'],
						'feedback'    => $option['feedback'],
					),
					$options
				)
			);
			$line[] = (string) wp_json_encode( $row['answer_key'] );

			fputcsv( $handle, array_map( array( self::class, 'defuse' ), $line ), ',', '"', '' );
		}

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- `php://temp` is an in-memory stream, not a file. `WP_Filesystem` cannot open one, and the point of using it is to touch no filesystem at all.
		fclose( $handle );

		// A BOM, so Excel opens a UTF-8 file as UTF-8 instead of as the system codepage —
		// without it every accented character in an exported bank arrives mangled.
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Neutralise a cell that a spreadsheet would treat as a formula.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * CSV INJECTION. THIS IS NOT ABOUT THIS SITE — IT IS ABOUT THE MACHINE THAT OPENS THE FILE.
	 *
	 * A question whose text begins `=cmd|'/c calc'!A1` is harmless in the bank and harmless
	 * in the browser. Written to CSV and opened in Excel it is a formula, and some of those
	 * formulas execute. The exporting site is fine; the person who downloaded the export is
	 * the one attacked, which is exactly why it gets missed.
	 *
	 * A leading apostrophe forces the cell to text. Excel does not display it, and the
	 * importing half strips it back off — see `CsvParser`, which trims cells anyway.
	 *
	 * Tab and carriage return are included because Excel accepts them as leading whitespace
	 * before a formula character, so `\t=1+1` is still a formula.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param mixed $value Cell value.
	 */
	private static function defuse( mixed $value ): string {
		$text = (string) $value;

		if ( '' === $text ) {
			return $text;
		}

		return in_array( $text[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ? "'" . $text : $text;
	}

	/* -------------------------------------------------------------- moodle xml */

	/**
	 * @param array<int, array<string, mixed>> $rows Questions.
	 */
	private function moodle_xml( array $rows ): string {
		$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<quiz>\n";

		foreach ( $rows as $row ) {
			$type = self::moodle_type( (string) $row['question_type'] );

			if ( '' === $type ) {
				continue;
			}

			$out .= sprintf( "  <question type=\"%s\">\n", esc_attr( $type ) );
			$out .= sprintf( "    <name><text>%s</text></name>\n", self::cdata( (string) $row['title'] ) );
			$out .= sprintf( "    <questiontext format=\"html\"><text>%s</text></questiontext>\n", self::cdata( (string) $row['stem'] ) );

			if ( '' !== (string) $row['explanation'] ) {
				$out .= sprintf( "    <generalfeedback format=\"html\"><text>%s</text></generalfeedback>\n", self::cdata( (string) $row['explanation'] ) );
			}

			$out .= sprintf( "    <defaultgrade>%s</defaultgrade>\n", esc_html( (string) $row['default_points'] ) );

			$key = (array) $row['answer_key'];

			if ( 'multichoice' === $type ) {
				$options = (array) $row['options'];
				$correct = array_filter( $options, static fn( array $o ): bool => ! empty( $o['is_correct'] ) );
				$single  = count( $correct ) <= 1;

				$out .= sprintf( "    <single>%s</single>\n", $single ? 'true' : 'false' );

				foreach ( $options as $option ) {
					// Moodle grades on fractions. For a multi-answer question every correct
					// option carries an equal share, which is the closest thing Moodle has
					// to this plugin's proportional policy.
					$fraction = empty( $option['is_correct'] )
						? 0
						: ( $single ? 100 : round( 100 / max( 1, count( $correct ) ), 5 ) );

					$out .= sprintf( "    <answer fraction=\"%s\"><text>%s</text></answer>\n", esc_attr( (string) $fraction ), self::cdata( (string) $option['option_text'] ) );
				}
			} elseif ( 'truefalse' === $type ) {
				$true = ! empty( $key['correct'] );

				$out .= sprintf( "    <answer fraction=\"%d\"><text>true</text></answer>\n", $true ? 100 : 0 );
				$out .= sprintf( "    <answer fraction=\"%d\"><text>false</text></answer>\n", $true ? 0 : 100 );
			} elseif ( 'shortanswer' === $type ) {
				foreach ( (array) ( $key['accepted'] ?? array() ) as $accepted ) {
					$out .= sprintf( "    <answer fraction=\"100\"><text>%s</text></answer>\n", self::cdata( (string) $accepted ) );
				}
			}

			$out .= "  </question>\n";
		}

		return $out . "</quiz>\n";
	}

	/**
	 * This plugin's type as Moodle's, or '' when there is no equivalent.
	 *
	 * @param string $type This plugin's type.
	 */
	private static function moodle_type( string $type ): string {
		return match ( $type ) {
			'mcq_single', 'mcq_multiple' => 'multichoice',
			'true_false'                 => 'truefalse',
			'short_answer'               => 'shortanswer',
			'essay'                      => 'essay',
			'matching'                   => 'matching',
			'ordering'                   => 'ordering',
			default                      => '',
		};
	}

	/* --------------------------------------------------------------------- qti */

	/**
	 * @param array<int, array<string, mixed>> $rows Questions.
	 */
	private function qti( array $rows ): string {
		$out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= "<questestinterop xmlns=\"http://www.imsglobal.org/xsd/imsqti_v2p1\">\n";

		foreach ( $rows as $row ) {
			$type    = (string) $row['question_type'];
			$options = (array) $row['options'];
			$key     = (array) $row['answer_key'];

			$out .= sprintf(
				"  <assessmentItem identifier=\"q%d\" title=\"%s\" adaptive=\"false\" timeDependent=\"false\">\n",
				(int) $row['id'],
				esc_attr( (string) $row['title'] )
			);

			if ( in_array( $type, array( 'mcq_single', 'mcq_multiple' ), true ) ) {
				$correct = array();

				foreach ( $options as $index => $option ) {
					if ( ! empty( $option['is_correct'] ) ) {
						$correct[] = 'c' . $index;
					}
				}

				$out .= sprintf(
					"    <responseDeclaration identifier=\"RESPONSE\" cardinality=\"%s\" baseType=\"identifier\">\n",
					'mcq_single' === $type ? 'single' : 'multiple'
				);
				$out .= "      <correctResponse>\n";

				foreach ( $correct as $identifier ) {
					$out .= sprintf( "        <value>%s</value>\n", esc_html( $identifier ) );
				}

				$out .= "      </correctResponse>\n    </responseDeclaration>\n";
				$out .= "    <itemBody>\n";
				$out .= sprintf( "      <div>%s</div>\n", self::cdata( (string) $row['stem'] ) );
				$out .= sprintf(
					"      <choiceInteraction responseIdentifier=\"RESPONSE\" shuffle=\"false\" maxChoices=\"%d\">\n",
					'mcq_single' === $type ? 1 : 0
				);

				foreach ( $options as $index => $option ) {
					$out .= sprintf(
						"        <simpleChoice identifier=\"c%d\">%s</simpleChoice>\n",
						$index,
						esc_html( (string) $option['option_text'] )
					);
				}

				$out .= "      </choiceInteraction>\n    </itemBody>\n";
			} elseif ( 'essay' === $type ) {
				$out .= "    <responseDeclaration identifier=\"RESPONSE\" cardinality=\"single\" baseType=\"string\"/>\n";
				$out .= "    <itemBody>\n";
				$out .= sprintf( "      <div>%s</div>\n", self::cdata( (string) $row['stem'] ) );
				$out .= "      <extendedTextInteraction responseIdentifier=\"RESPONSE\"/>\n";
				$out .= "    </itemBody>\n";
			} else {
				$accepted = 'true_false' === $type
					? array( empty( $key['correct'] ) ? 'false' : 'true' )
					: (array) ( $key['accepted'] ?? array() );

				$out .= "    <responseDeclaration identifier=\"RESPONSE\" cardinality=\"single\" baseType=\"string\">\n";
				$out .= "      <correctResponse>\n";

				foreach ( $accepted as $value ) {
					$out .= sprintf( "        <value>%s</value>\n", esc_html( (string) $value ) );
				}

				$out .= "      </correctResponse>\n    </responseDeclaration>\n";
				$out .= "    <itemBody>\n";
				$out .= sprintf( "      <div>%s</div>\n", self::cdata( (string) $row['stem'] ) );
				$out .= "      <textEntryInteraction responseIdentifier=\"RESPONSE\"/>\n";
				$out .= "    </itemBody>\n";
			}

			$out .= "  </assessmentItem>\n";
		}

		return $out . "</questestinterop>\n";
	}

	/**
	 * Wrap authored HTML so it survives as markup rather than as escaped text.
	 *
	 * `]]>` is the one sequence CDATA cannot contain, so it is split across two sections —
	 * the standard trick, and the reason this is a helper rather than string concatenation
	 * at each call site.
	 *
	 * @param string $html Authored HTML.
	 */
	private static function cdata( string $html ): string {
		return '<![CDATA[' . str_replace( ']]>', ']]]]><![CDATA[>', $html ) . ']]>';
	}
}
