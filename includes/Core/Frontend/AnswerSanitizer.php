<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cleans the answers a form posted, before anything else is allowed to see them.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS AN OBJECT WITH ONE METHOD AND NOT A FILTER.
 *
 * {@see FormHandler} used to read `$_POST['answers']` and pass it, unslashed and otherwise
 * untouched, through two hooks on its way to the service that cleaned it per question type.
 * The cleaning was real and it was in the right place for the REST runner, which enters at
 * the service. For the form it came one step late: WordPress.org's review of 1.1.1 pointed
 * at the first of those hooks, and it was right — a hook hands its value to whatever is
 * attached, and a callback written by somebody else had the raw request in its hands.
 *
 * Asking for the cleaning through ANOTHER hook would have moved the problem one line up. So
 * the form calls this, directly, in the statement that reads the request, and only what
 * comes back is passed on.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * WHY CORE DOES NOT DO THE CLEANING ITSELF. It cannot know how. An essay keeps its
 * paragraphs, a short answer is one line, a choice is an option id and an answer to a
 * question that is not on the candidate's paper is nothing at all — and which of those a
 * posted value is depends on the FROZEN PAPER, which belongs to the attempts module. One
 * sanitiser for every value would have to be loose enough for the essay or strict enough
 * for the id, and either mangles somebody's answer. Core may not name a module, so the
 * module hands its cleaner in through {@see self::clean_with()}.
 *
 * NOTHING REGISTERED MEANS NOTHING GETS THROUGH. With no cleaner the answer is an empty
 * list, not the input: a build in which nothing knows what an answer should look like must
 * not store one.
 */
final class AnswerSanitizer {

	/**
	 * What cleans a set of posted answers for one attempt.
	 *
	 * @var (callable(int, array<int|string, mixed>): array<int, mixed>)|null
	 */
	private $cleaner = null;

	/**
	 * Name the cleaner. Called once, by the module that owns attempts.
	 *
	 * @param callable(int, array<int|string, mixed>): array<int, mixed> $cleaner Receives the attempt id and the posted answers; returns question id => cleaned answer.
	 */
	public function clean_with( callable $cleaner ): void {
		$this->cleaner = $cleaner;
	}

	/**
	 * The posted answers that belong on this attempt's paper, each cleaned for its question.
	 *
	 * @param int                      $attempt_id The attempt the form named.
	 * @param array<int|string, mixed> $answers    Question id => answer, as posted.
	 *
	 * @return array<int, mixed> Question id => cleaned answer.
	 */
	public function sanitize( int $attempt_id, array $answers ): array {
		if ( null === $this->cleaner || array() === $answers ) {
			return array();
		}

		return (array) ( $this->cleaner )( $attempt_id, $answers );
	}
}
