<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\ImportExport\Services;

use PlugixaExam\Modules\ImportExport\Services\Parsers\AikenParser;
use PlugixaExam\Modules\ImportExport\Services\Parsers\CsvParser;
use PlugixaExam\Modules\ImportExport\Services\Parsers\GiftParser;
use PlugixaExam\Modules\ImportExport\Services\Parsers\MoodleXmlParser;
use PlugixaExam\Modules\ImportExport\Services\Parsers\ParserInterface;
use PlugixaExam\Modules\ImportExport\Services\Parsers\QtiParser;
use PlugixaExam\Modules\Questions\Services\QuestionService;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads a question-bank file and, when asked, writes it into the bank.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * PREVIEW AND IMPORT RUN THE SAME PARSE.
 *
 * `inspect()` and `run()` differ in exactly one respect: whether anything is written. The
 * parsing, the type mapping and the problem list are identical, so what an author approves is
 * what they get. The alternative — a cheap preview and a thorough import — produces the worst
 * possible failure mode, where the screen says forty questions and the bank receives thirty.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class Importer {

	/** How much of a file is accepted, in bytes. */
	public const MAX_BYTES = 5 * 1024 * 1024;

	/**
	 * @param QuestionService $questions Writes the questions.
	 */
	public function __construct( private readonly QuestionService $questions ) {}

	/**
	 * Every parser this build has.
	 *
	 * @return ParserInterface[]
	 */
	public function parsers(): array {
		$parsers = array(
			new CsvParser(),
			new GiftParser(),
			new AikenParser(),
			new MoodleXmlParser(),
			new QtiParser(),
		);

		/**
		 * Filter the import parsers.
		 *
		 * A module adds a format by appending to this list; nothing else changes.
		 *
		 * @param ParserInterface[] $parsers Registered parsers.
		 */
		return (array) apply_filters( 'plugixa_exam_import_parsers', $parsers );
	}

	/**
	 * The formats an author can choose from.
	 *
	 * @return array<int, array{id: string, label: string, extensions: string[]}>
	 */
	public function formats(): array {
		return array_map(
			static fn( ParserInterface $parser ): array => array(
				'id'         => $parser->id(),
				'label'      => $parser->label(),
				'extensions' => $parser->extensions(),
			),
			$this->parsers()
		);
	}

	/**
	 * Work out what a file is.
	 *
	 * Content first, filename only as a tie-break — an extension is a claim, and a `.txt`
	 * holding Moodle XML is common enough to be worth handling.
	 *
	 * @param string $content  The file's contents.
	 * @param string $filename Original filename.
	 */
	public function detect( string $content, string $filename = '' ): ?ParserInterface {
		$best  = null;
		$score = 0;

		foreach ( $this->parsers() as $parser ) {
			$confidence = $parser->confidence( $content, $filename );

			if ( $confidence > $score ) {
				$best  = $parser;
				$score = $confidence;
			}
		}

		return $best;
	}

	/**
	 * Parse a file without writing anything.
	 *
	 * @param string $content  The file's contents.
	 * @param string $filename Original filename.
	 * @param string $format   Force a format, or '' to detect.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function inspect( string $content, string $filename = '', string $format = '' ): array|WP_Error {
		if ( '' === trim( $content ) ) {
			return new WP_Error( 'plugixa_exam_empty_file', __( 'The file is empty.', 'plugixa-exam' ), array( 'status' => 400 ) );
		}

		if ( strlen( $content ) > self::MAX_BYTES ) {
			return new WP_Error(
				'plugixa_exam_file_too_large',
				sprintf(
					/* translators: %s: a size such as "5 MB". */
					__( 'The file is larger than %s.', 'plugixa-exam' ),
					size_format( self::MAX_BYTES )
				),
				array( 'status' => 400 )
			);
		}

		$parser = '' === $format ? $this->detect( $content, $filename ) : $this->by_id( $format );

		if ( null === $parser ) {
			return new WP_Error(
				'plugixa_exam_unknown_format',
				__( 'The file is not in a format this site can read.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		$parsed = $parser->parse( $content );

		return array(
			'format'    => $parser->id(),
			'label'     => $parser->label(),
			'total'     => count( $parsed['questions'] ),
			'problems'  => $parsed['problems'],
			// Enough to judge the parse by without shipping a five-megabyte preview.
			'questions' => array_map(
				static fn( array $question ): array => array(
					'title'         => (string) ( $question['title'] ?? '' ),
					'question_type' => (string) ( $question['question_type'] ?? '' ),
					'stem'          => (string) ( $question['stem'] ?? '' ),
					'option_count'  => count( (array) ( $question['options'] ?? array() ) ),
				),
				array_slice( $parsed['questions'], 0, 25 )
			),
		);
	}

	/**
	 * Parse a file and write what it holds.
	 *
	 * @param string $content    The file's contents.
	 * @param string $filename   Original filename.
	 * @param string $format     Force a format, or '' to detect.
	 * @param int    $subject_id Put everything in this subject, or 0.
	 * @param bool   $published  Whether the imported questions are usable straight away.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function run(
		string $content,
		string $filename = '',
		string $format = '',
		int $subject_id = 0,
		bool $published = true
	): array|WP_Error {
		$inspection = $this->inspect( $content, $filename, $format );

		if ( $inspection instanceof WP_Error ) {
			return $inspection;
		}

		$parser = $this->by_id( (string) $inspection['format'] );

		if ( null === $parser ) {
			return new WP_Error( 'plugixa_exam_unknown_format', __( 'The file is not in a format this site can read.', 'plugixa-exam' ), array( 'status' => 400 ) );
		}

		$parsed   = $parser->parse( $content );
		$problems = $parsed['problems'];
		$created  = array();
		$number   = 0;

		foreach ( $parsed['questions'] as $question ) {
			++$number;

			if ( 0 !== $subject_id ) {
				$question['subject_id'] = $subject_id;
			}

			$question['published'] = $published;

			$id = $this->questions->create( $question );

			if ( $id instanceof WP_Error ) {
				/*
				 * A question the service refuses is REPORTED, not silently dropped and not
				 * fatal to the import. A bank of two hundred with three broken ones should
				 * yield 197 questions and three lines explaining the rest — refusing the lot
				 * makes a large import impossible to land.
				 */
				$problems[] = sprintf(
					/* translators: 1: question number, 2: title, 3: the reason. */
					__( 'Question %1$d (%2$s) was not imported: %3$s', 'plugixa-exam' ),
					$number,
					(string) ( $question['title'] ?? '' ),
					$id->get_error_message()
				);

				continue;
			}

			$created[] = $id;
		}

		return array(
			'format'   => $parser->id(),
			'imported' => count( $created ),
			'skipped'  => count( $parsed['questions'] ) - count( $created ),
			'problems' => $problems,
			'ids'      => $created,
		);
	}

	/**
	 * @param string $id Format id.
	 */
	private function by_id( string $id ): ?ParserInterface {
		foreach ( $this->parsers() as $parser ) {
			if ( $parser->id() === $id ) {
				return $parser;
			}
		}

		return null;
	}
}
