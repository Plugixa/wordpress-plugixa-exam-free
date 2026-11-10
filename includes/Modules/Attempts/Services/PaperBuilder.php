<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Services;

use PlugixaExam\Modules\Exams\Repositories\ExamRepository;
use PlugixaExam\Modules\Questions\Repositories\QuestionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an exam's composition into one examinee's frozen paper.
 *
 * RUNS EXACTLY ONCE PER ATTEMPT, at start, and the result is written to
 * `attempts.question_set` and never recomputed. That is the single most important property
 * in the runner:
 *
 *  - a pool section draws at random, so recomputing would hand the examinee a different
 *    paper on every page load;
 *  - an author editing the exam mid-session would otherwise change the rules under somebody
 *    already sitting it — adding a question they have no time left to answer, or removing
 *    one they already answered;
 *  - the per-question points are resolved here, so a later change to a question's default
 *    cannot retroactively alter a mark.
 *
 * `Attempts` depends on `Questions` and `Exams`, and never the reverse — neither of those
 * modules mentions attempts.
 */
final class PaperBuilder {

	/**
	 * @param ExamRepository     $exams     Exam composition.
	 * @param QuestionRepository $questions The bank.
	 */
	public function __construct(
		private readonly ExamRepository $exams,
		private readonly QuestionRepository $questions
	) {}

	/**
	 * Build the frozen paper for one attempt.
	 *
	 * @param array<string, mixed> $exam The exam row.
	 *
	 * @return array<string, mixed> The `question_set` payload.
	 */
	public function build( array $exam ): array {
		$exam_id = (int) $exam['id'];
		$seed    = Shuffle::new_seed();

		$questions = array();

		foreach ( $this->exams->sections( $exam_id ) as $section ) {
			$section_id = (int) $section['id'];

			$drawn = 'pool' === $section['section_type']
				? $this->draw_pool( $section )
				: $this->fixed_questions( $section );

			foreach ( $drawn as $entry ) {
				$questions[] = $entry;
			}

			unset( $section_id );
		}

		// De-duplicate across sections. The same question drawn by two pool rules would
		// otherwise appear twice, and `attempt_responses` has a UNIQUE key on
		// (attempt_id, question_id) — the second write would silently overwrite the first,
		// so the examinee would answer it twice and be marked once.
		$seen   = array();
		$unique = array();

		foreach ( $questions as $entry ) {
			if ( isset( $seen[ $entry['id'] ] ) ) {
				continue;
			}

			$seen[ $entry['id'] ] = true;
			$unique[]             = $entry;
		}

		$questions = $unique;

		if ( ! empty( $exam['shuffle_questions'] ) ) {
			// Salted with 0 rather than a question id: this shuffles the whole paper, and
			// the salt only needs to differ from the per-question option shuffles.
			$questions = Shuffle::order( $questions, $seed, 0 );
		}

		/**
		 * The paper's questions, before they are frozen.
		 *
		 * Runs after the shuffle, so a listener that needs related questions to stay
		 * together has the last word on their order. An entry may gain keys, which are then
		 * frozen with the rest of it; `id`, `type`, `section_id` and `points` must survive.
		 *
		 * @param array<int, array<string, mixed>> $questions The entries, in paper order.
		 * @param array<string, mixed>             $exam      The exam row.
		 */
		$questions = (array) apply_filters( 'plugixa_exam_paper_questions', $questions, $exam );

		return array(
			'seed'            => $seed,
			// Frozen so that editing the exam cannot change the flow of a paper in progress.
			'mode'            => (string) $exam['attempt_mode'],
			'practice_reveal' => (string) $exam['practice_reveal'],
			'layout'          => (string) $exam['layout'],
			'shuffle_options' => (bool) $exam['shuffle_options'],
			'require_answer'  => (string) $exam['require_answer'],
			'review_before_submit' => (bool) $exam['review_before_submit'],
			'allow_early_finish'   => (bool) $exam['allow_early_finish'],
			'fullscreen_mode'      => (string) ( $exam['fullscreen_mode'] ?? 'off' ),
			/*
			 * Frozen for a different reason from everything above it.
			 *
			 * The others are frozen so that editing an exam cannot change the FLOW of a
			 * paper somebody is sitting. This one is frozen so that it cannot start
			 * COLLECTING from one. Turning integrity tracking on halfway through a sitting
			 * would begin recording a candidate's behaviour under terms they were never
			 * shown, which is the one direction a live read must not be allowed to move in.
			 *
			 * The premium Integrity module reads this AND the exam's current value, and
			 * captures only when both agree — so switching tracking OFF still takes effect
			 * immediately, on attempts already in flight. See its `Recorder`.
			 *
			 * Written by the FREE build even though only premium code reads it: an attempt
			 * frozen while the licence lapsed would otherwise be permanently unreadable to
			 * a module installed a day later, and this costs one boolean.
			 */
			'track_integrity' => (bool) $exam['track_integrity'],

			/*
			 * ════════════════════════════════════════════════════════════════════════════
			 * THE PAPER FREEZES THE LANGUAGE, FOR THE SAME REASON IT FREEZES EVERYTHING.
			 *
			 * A site can serve one exam in several languages — that is what the premium
			 * Multilingual module is for — and the version somebody is reading has to stay
			 * the version they are reading. Without this, switching the site language, or
			 * simply following a link with a different language prefix, would swap the
			 * wording of the questions underneath a candidate halfway through, and any
			 * answer they had already given would now belong to a question they never saw.
			 *
			 * Written by the FREE build even though only premium code reads it, exactly as
			 * `track_integrity` above is: an attempt frozen while no licence was active
			 * would otherwise be permanently unreadable to a module installed a day later,
			 * and this costs one string.
			 *
			 * `determine_locale()` rather than `get_locale()`: a logged-in candidate who
			 * has set their own language in their profile sits the paper in THEIR language,
			 * not the site's.
			 *
			 * AND IT IS FILTERED, because `determine_locale()` is not always the last word.
			 * On a Polylang site the reader's language comes from the URL, and `/exams/x/`
			 * with no language segment answers the SITE default however French the visitor
			 * is — so a candidate who reached the paper in French would have frozen an
			 * English one, and been marked against it. The listener is the Multilingual
			 * module's `Locales::current()`, which knows how to ask Polylang; unhooked, this
			 * is exactly what it was.
			 * ════════════════════════════════════════════════════════════════════════════
			 */

			/**
			 * The language a paper is frozen in.
			 *
			 * @param string $locale What WordPress alone would answer.
			 */
			'locale'          => (string) apply_filters( 'plugixa_exam_paper_locale', determine_locale() ),
			'questions'       => array_values( $questions ),
		);
	}

	/**
	 * Questions explicitly placed in a fixed section.
	 *
	 * @param array<string, mixed> $section The section row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function fixed_questions( array $section ): array {
		$entries = array();

		foreach ( $this->exams->section_questions( (int) $section['id'] ) as $placement ) {
			$question = $this->questions->find( (int) $placement['question_id'] );

			// A question deleted after the exam was built. Skipped rather than fatal: the
			// exam should still run, one question shorter, rather than refusing to start.
			if ( null === $question || ! $question['published'] ) {
				continue;
			}

			$entries[] = $this->entry( $question, $section, $placement['points'] );
		}

		return $entries;
	}

	/**
	 * Questions drawn at random by a pool section's rules.
	 *
	 * @param array<string, mixed> $section The section row.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function draw_pool( array $section ): array {
		$entries = array();
		$taken   = array();

		foreach ( $this->exams->section_rules( (int) $section['id'] ) as $rule ) {
			$candidates = $this->candidates( $rule, $taken );

			if ( array() === $candidates ) {
				continue;
			}

			// A dedicated seed per draw, so two rules with the same filters do not select
			// the same questions in the same order.
			shuffle( $candidates );

			$drawn = array_slice( $candidates, 0, (int) $rule['draw_count'] );

			foreach ( $drawn as $question_id ) {
				$question = $this->questions->find( (int) $question_id );

				if ( null === $question ) {
					continue;
				}

				$taken[ (int) $question_id ] = true;

				$entries[] = $this->entry( $question, $section, $rule['points_per_question'] );
			}
		}

		return $entries;
	}

	/**
	 * Question ids a pool rule may draw from.
	 *
	 * @param array<string, mixed> $rule  The rule.
	 * @param array<int, bool>     $taken Ids already drawn by an earlier rule.
	 *
	 * @return int[]
	 */
	private function candidates( array $rule, array $taken ): array {
		$query = $this->questions->query()
			->select( 't.id' )
			->where( 't.published = %d', array( 1 ) );

		if ( ! empty( $rule['subject_id'] ) ) {
			/** This filter is documented in includes/Modules/Subjects/Module.php */
			$subject_ids = (array) apply_filters(
				'plugixa_exam_subject_subtree_ids',
				array( (int) $rule['subject_id'] ),
				(int) $rule['subject_id']
			);

			$query->where_in( 't.subject_id', array_map( 'intval', $subject_ids ) );
		}

		if ( ! empty( $rule['difficulty'] ) ) {
			$query->where( 't.difficulty = %d', array( (int) $rule['difficulty'] ) );
		}

		if ( ! empty( $rule['question_type'] ) ) {
			$query->where( 't.question_type = %s', array( (string) $rule['question_type'] ) );
		}

		$rows = $query->paginate( 2000 )->get();
		$ids  = array_map( static fn( array $row ): int => (int) $row['id'], $rows );

		if ( array() !== ( $rule['tag_ids'] ?? array() ) ) {
			/**
			 * Narrow a candidate list to questions carrying any of these tags.
			 *
			 * A filter rather than a join, so this module never names the Tags module —
			 * and so a site with Tags switched off draws from the unnarrowed pool rather
			 * than from nothing, which would silently produce an empty section.
			 *
			 * @param int[] $ids     Candidate question ids.
			 * @param int[] $tag_ids Tags the rule requires.
			 */
			$ids = (array) apply_filters(
				'plugixa_exam_filter_questions_by_tags',
				$ids,
				array_map( 'intval', (array) $rule['tag_ids'] )
			);
		}

		// Never draw a question an earlier rule in the same section already took.
		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static fn( int $id ): bool => ! isset( $taken[ $id ] )
			)
		);
	}

	/**
	 * One frozen paper entry.
	 *
	 * POINT RESOLUTION HAPPENS HERE AND NOWHERE ELSE, in the documented order: the explicit
	 * per-placement override, then the section's per-question value, then the question's own
	 * default. Resolving it at grading time instead would mean a question's default changing
	 * after the fact could alter a mark already awarded.
	 *
	 * @param array<string, mixed> $question The question row.
	 * @param array<string, mixed> $section  The section row.
	 * @param float|null           $override The placement's or rule's points.
	 *
	 * @return array<string, mixed>
	 */
	private function entry( array $question, array $section, ?float $override ): array {
		$points = $override
			?? ( $section['points_per_question'] ?? null )
			?? (float) $question['default_points'];

		return array(
			'id'            => (int) $question['id'],
			'type'          => (string) $question['question_type'],
			'section_id'    => (int) $section['id'],
			/*
			 * Frozen with the rest, because saving an exam rebuilds its sections under new
			 * ids: a title looked up live by the frozen id vanishes the moment an author
			 * saves while somebody is sitting the paper.
			 */
			'section_title' => (string) ( $section['title'] ?? '' ),
			'points'        => round( (float) $points, 2 ),
		);
	}
}
