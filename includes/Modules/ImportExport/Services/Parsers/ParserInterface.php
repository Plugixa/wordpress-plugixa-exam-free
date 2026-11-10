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
 * One question-bank file format.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * A PARSER PRODUCES CANDIDATE QUESTIONS AND NOTHING ELSE.
 *
 * It never writes, never validates against the site's registered types, and never decides
 * whether a question is good enough to keep. It turns bytes into the array shape
 * `QuestionService::create()` accepts, and reports what it could not understand.
 *
 * That separation is what makes the preview step honest: the same parse runs for the preview
 * and for the import, so what an author is shown is exactly what they will get. A parser that
 * quietly repaired things during the real run would make the preview a lie.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
interface ParserInterface {

	/**
	 * The format's machine id.
	 */
	public function id(): string;

	/**
	 * A name for the format picker.
	 */
	public function label(): string;

	/**
	 * File extensions this format usually arrives as, without the dot.
	 *
	 * @return string[]
	 */
	public function extensions(): array;

	/**
	 * How strongly this parser believes it recognises the content.
	 *
	 * 0 means "not mine". Higher wins. Deliberately a score rather than a boolean, because
	 * several formats are plain text and only differ in their punctuation — Aiken and GIFT
	 * both look like prose until you find a `{` or an `ANSWER:` line.
	 *
	 * @param string $content  The file's contents.
	 * @param string $filename Original filename, which may be empty.
	 */
	public function confidence( string $content, string $filename ): int;

	/**
	 * Turn the file into candidate questions.
	 *
	 * @param string $content The file's contents.
	 *
	 * @return array{questions: array<int, array<string, mixed>>, problems: string[]}
	 */
	public function parse( string $content ): array;
}
