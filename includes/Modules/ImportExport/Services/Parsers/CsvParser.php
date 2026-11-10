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
 * CSV: the format this plugin round-trips without losing anything.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * TWO WAYS TO WRITE THE SAME FILE, AND BOTH ARE SUPPORTED ON PURPOSE.
 *
 * A spreadsheet a teacher types has `option_1 … option_n` and `correct` columns — readable,
 * editable, and unable to express an answer key with tolerances or a rubric. The file this
 * plugin EXPORTS carries those simple columns too, and adds `options_json` and `answer_key`
 * at the end. A reader that understands the trailing columns gets a lossless round trip; one
 * that does not still sees a perfectly ordinary spreadsheet.
 *
 * So the JSON columns win when present, and the simple columns are the fallback. That is what
 * lets somebody export a bank, edit the option text in Excel, and re-import without silently
 * discarding the numeric tolerance on every short answer.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class CsvParser implements ParserInterface {

	/** Columns the importer understands. Everything else is ignored rather than rejected. */
	public const COLUMNS = array(
		'title',
		'question_type',
		'stem',
		'explanation',
		'difficulty',
		'default_points',
		'published',
		'subject',
		'correct',
		'options_json',
		'answer_key',
	);

	public function id(): string {
		return 'csv';
	}

	public function label(): string {
		return __( 'CSV', 'plugixa-exam' );
	}

	/**
	 * @return string[]
	 */
	public function extensions(): array {
		return array( 'csv' );
	}

	public function confidence( string $content, string $filename ): int {
		if ( str_ends_with( strtolower( $filename ), '.csv' ) ) {
			return 95;
		}

		$first = strtok( $content, "\r\n" );

		if ( false === $first ) {
			return 0;
		}

		// A header naming at least two columns this parser knows.
		$known = 0;

		foreach ( str_getcsv( $first, ',', '"', '' ) as $heading ) {
			if ( in_array( self::key( (string) $heading ), self::COLUMNS, true ) ) {
				++$known;
			}
		}

		return $known >= 2 ? 80 : 0;
	}

	/**
	 * @param string $content The file's contents.
	 *
	 * @return array{questions: array<int, array<string, mixed>>, problems: string[]}
	 */
	public function parse( string $content ): array {
		$rows = self::rows( $content );

		if ( array() === $rows ) {
			return array( 'questions' => array(), 'problems' => array( __( 'The file has no rows.', 'plugixa-exam' ) ) );
		}

		$header = array_map( array( self::class, 'key' ), array_shift( $rows ) );

		if ( ! in_array( 'stem', $header, true ) && ! in_array( 'title', $header, true ) ) {
			return array(
				'questions' => array(),
				'problems'  => array( __( 'The file needs at least a “stem” or “title” column.', 'plugixa-exam' ) ),
			);
		}

		$questions = array();
		$problems  = array();
		$line      = 1;

		foreach ( $rows as $row ) {
			++$line;

			if ( array() === array_filter( $row, static fn( $cell ): bool => '' !== trim( (string) $cell ) ) ) {
				continue;
			}

			$data = self::combine( $header, $row );

			$question = self::build( $data, $line, $problems );

			if ( null !== $question ) {
				$questions[] = $question;
			}
		}

		return array( 'questions' => $questions, 'problems' => $problems );
	}

	/**
	 * One row as a question.
	 *
	 * @param array<string, string> $data     The row, keyed by column.
	 * @param int                   $line     Line number, for messages.
	 * @param string[]              $problems Accumulated problems, by reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function build( array $data, int $line, array &$problems ): ?array {
		$stem  = trim( $data['stem'] ?? '' );
		$title = trim( $data['title'] ?? '' );

		if ( '' === $stem && '' === $title ) {
			$problems[] = sprintf(
				/* translators: %d: line number. */
				__( 'Line %d: neither a title nor a question, so it was skipped.', 'plugixa-exam' ),
				$line
			);

			return null;
		}

		$type = sanitize_key( $data['question_type'] ?? '' );
		$type = '' === $type ? 'mcq_single' : $type;

		$question = array(
			'title'          => '' === $title ? AikenParser::title_from( $stem ) : $title,
			'question_type'  => $type,
			// Already HTML if it came from an export; wrapped if it is a bare sentence
			// somebody typed into a spreadsheet.
			'stem'           => str_contains( $stem, '<' ) ? $stem : '<p>' . esc_html( $stem ) . '</p>',
			'explanation'    => $data['explanation'] ?? '',
			'difficulty'     => max( 1, min( 5, (int) ( $data['difficulty'] ?? 1 ) ) ),
			'default_points' => (float) ( $data['default_points'] ?? 1 ),
			// Absent means published: a spreadsheet somebody typed has no such column, and
			// importing a bank nobody can use is not what they meant.
			'published'      => ! isset( $data['published'] ) || self::truthy( $data['published'] ),
		);

		$key = self::json( $data['answer_key'] ?? '' );

		if ( null !== $key ) {
			$question['answer_key'] = $key;
		}

		$options = self::json( $data['options_json'] ?? '' );

		if ( is_array( $options ) ) {
			$question['options'] = $options;

			return $question;
		}

		$simple = self::simple_options( $data );

		if ( array() !== $simple ) {
			$question['options'] = $simple;
		}

		// A true/false row with neither a JSON key nor an options list still needs one, and
		// `correct` is the only place the answer can be.
		if ( 'true_false' === $type && ! isset( $question['answer_key'] ) ) {
			$question['answer_key'] = array( 'correct' => self::truthy( $data['correct'] ?? '' ) );
		}

		if ( 'short_answer' === $type && ! isset( $question['answer_key'] ) ) {
			$accepted = array_values(
				array_filter( array_map( 'trim', explode( '|', $data['correct'] ?? '' ) ) )
			);

			$question['answer_key'] = array(
				'accepted'         => $accepted,
				'case_insensitive' => true,
				'trim'             => true,
			);
		}

		return $question;
	}

	/**
	 * `option_1`, `option_2`, … with a `correct` column naming the right ones.
	 *
	 * `correct` holds either 1-based positions (`2` or `1|3`) or the option text itself, so a
	 * spreadsheet stays readable either way.
	 *
	 * @param array<string, string> $data The row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function simple_options( array $data ): array {
		$texts = array();

		foreach ( $data as $column => $value ) {
			if ( preg_match( '/^option_(\d+)$/', $column, $matches ) && '' !== trim( $value ) ) {
				$texts[ (int) $matches[1] ] = trim( $value );
			}
		}

		if ( array() === $texts ) {
			return array();
		}

		ksort( $texts );

		$correct = array_values( array_filter( array_map( 'trim', explode( '|', $data['correct'] ?? '' ) ) ) );
		$lowered = array_map( 'mb_strtolower', $correct );

		$options = array();

		foreach ( $texts as $position => $text ) {
			$options[] = array(
				'option_text' => $text,
				'is_correct'  => in_array( (string) $position, $correct, true )
					|| in_array( mb_strtolower( $text ), $lowered, true ),
			);
		}

		return $options;
	}

	/**
	 * Parse the file into rows.
	 *
	 * `str_getcsv` line by line rather than `fgetcsv` over a stream, because the content
	 * arrives as a string from an upload that has already been read and validated.
	 *
	 * @param string $content The file's contents.
	 *
	 * @return array<int, string[]>
	 */
	private static function rows( string $content ): array {
		// A UTF-8 BOM, which Excel writes and which otherwise becomes part of the first
		// column name — so the header no longer matches and every row looks empty.
		$content = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $content );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- `php://temp` is an in-memory stream, not a file. `WP_Filesystem` cannot open one, and the point of using it is to touch no filesystem at all.
		$handle = fopen( 'php://temp', 'r+' );

		if ( false === $handle ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- `php://temp` is an in-memory stream, not a file. `WP_Filesystem` cannot open one, and the point of using it is to touch no filesystem at all.
		fwrite( $handle, $content );
		rewind( $handle );

		$rows = array();

		while ( true ) {
			// Empty escape, matching the writer — see `Exporter::csv()` for why the default
			// backslash mechanism breaks the JSON columns this format depends on.
			$row = fgetcsv( $handle, 0, ',', '"', '' );

			if ( false === $row || null === $row ) {
				break;
			}

			$rows[] = array_map( static fn( $cell ): string => (string) $cell, $row );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- `php://temp` is an in-memory stream, not a file. `WP_Filesystem` cannot open one, and the point of using it is to touch no filesystem at all.
		fclose( $handle );

		return $rows;
	}

	/**
	 * Pair a header with a row, tolerating a short or long row.
	 *
	 * `array_combine` would fatal on a length mismatch, and a trailing comma in one row of a
	 * hand-edited file is not worth failing an entire import over.
	 *
	 * @param string[] $header Column keys.
	 * @param string[] $row    Cell values.
	 *
	 * @return array<string, string>
	 */
	private static function combine( array $header, array $row ): array {
		$data = array();

		foreach ( $header as $index => $column ) {
			if ( '' === $column ) {
				continue;
			}

			$data[ $column ] = (string) ( $row[ $index ] ?? '' );
		}

		return $data;
	}

	/**
	 * A column heading as a key.
	 *
	 * @param string $heading Raw heading.
	 */
	private static function key( string $heading ): string {
		return strtolower( trim( str_replace( array( ' ', '-' ), '_', trim( $heading ) ) ) );
	}

	/**
	 * A JSON cell, or null when it is empty or malformed.
	 *
	 * @param string $value Cell contents.
	 *
	 * @return array<mixed>|null
	 */
	private static function json( string $value ): ?array {
		$value = trim( $value );

		if ( '' === $value ) {
			return null;
		}

		$decoded = json_decode( $value, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Whether a spreadsheet cell means yes.
	 *
	 * @param string $value Cell contents.
	 */
	private static function truthy( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'y', 't' ), true );
	}
}
