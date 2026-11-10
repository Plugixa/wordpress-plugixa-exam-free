<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Questions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin strings for the question bank.
 */
final class Strings {

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'questions'            => __( 'Question bank', 'plugixa-exam' ),
			'questionsDesc' => __( 'The bank every exam draws its questions from.', 'plugixa-exam' ),
			'question'             => __( 'Question', 'plugixa-exam' ),
			'newQuestion'          => __( 'New question', 'plugixa-exam' ),
			'editQuestion'         => __( 'Edit question', 'plugixa-exam' ),
			'questionsEmpty'       => __( 'Your question bank is empty. Add a question to get started.', 'plugixa-exam' ),
			'questionsIntro'       => __( 'Every exam is built from questions in this bank: either picked one by one, or drawn at random from a subject.', 'plugixa-exam' ),

			// The editor's in-card section headings.
			'questionSectionBasics'  => __( 'Basic information', 'plugixa-exam' ),
			/* translators: A section heading on the question editor, above the options and the answer key. */
			'questionSectionAnswer'  => __( 'Answer', 'plugixa-exam' ),
			'questionSectionScoring' => __( 'Scoring and publishing', 'plugixa-exam' ),

			// Fields.
			'questionTitle'        => __( 'Title', 'plugixa-exam' ),
			'questionTitleHelp'    => __( 'An internal label, so you can find this question in the bank. Examinees never see it.', 'plugixa-exam' ),
			'questionStem'         => __( 'Question', 'plugixa-exam' ),
			'questionStemHelp'     => __( 'What the examinee reads.', 'plugixa-exam' ),
			'questionType'         => __( 'Type', 'plugixa-exam' ),
			'questionSubject'      => __( 'Subject', 'plugixa-exam' ),
			'questionDifficulty'   => __( 'Difficulty', 'plugixa-exam' ),
			'questionPoints'       => __( 'Points', 'plugixa-exam' ),
			'questionPointsHelp'   => __( 'The default. An exam can override it per question.', 'plugixa-exam' ),
			'questionExplanation'  => __( 'Explanation', 'plugixa-exam' ),
			'questionExplanationHelp' => __( 'Shown when an examinee reviews their answers, if the exam allows it.', 'plugixa-exam' ),
			'questionShuffle'      => __( 'Shuffle options', 'plugixa-exam' ),
			'questionPublished'    => __( 'Published', 'plugixa-exam' ),
			'questionTags'         => __( 'Tags', 'plugixa-exam' ),

			// Options.
			'options'              => __( 'Answer options', 'plugixa-exam' ),
			'addOption'            => __( 'Add option', 'plugixa-exam' ),
			'optionText'           => __( 'Option', 'plugixa-exam' ),
			'optionCorrect'        => __( 'Correct', 'plugixa-exam' ),

			/*
			 * Used by `OptionsEditor`'s `match` and `sequence` modes, which ship in the FREE
			 * build even though no free type declares them. The editor is generic — an
			 * option that pairs with something, a list whose order is the answer — and any
			 * registered type may ask for either. A string used by free code belongs in free
			 * strings, whichever type happens to use it today.
			 */
			'matchesWith'          => __( 'Matches with', 'plugixa-exam' ),
			'matchesWithHelp'      => __( 'What the examinee should pair this item with.', 'plugixa-exam' ),
			'correctOrderHelp'     => __( 'Drag the items into the right sequence. The examinee sees them shuffled.', 'plugixa-exam' ),
			'optionFeedback'       => __( 'Feedback', 'plugixa-exam' ),
			'optionFeedbackHelp'   => __( 'Shown to an examinee who picks this option.', 'plugixa-exam' ),
			'optionsNeedTwo'       => __( 'This question type needs at least two answer options.', 'plugixa-exam' ),

			// Per-type answer keys.
			'trueLabel'            => __( 'True', 'plugixa-exam' ),
			'falseLabel'           => __( 'False', 'plugixa-exam' ),
			'correctAnswer'        => __( 'Correct answer', 'plugixa-exam' ),
			'acceptedAnswers'      => __( 'Accepted answers', 'plugixa-exam' ),
			'acceptedAnswersHelp'  => __( 'One per line. An answer matching any of these is marked correct.', 'plugixa-exam' ),
			'caseInsensitive'      => __( 'Ignore capitalisation', 'plugixa-exam' ),
			'trimWhitespace'       => __( 'Ignore leading and trailing spaces', 'plugixa-exam' ),
			'useRegex'             => __( 'Treat accepted answers as regular expressions', 'plugixa-exam' ),
			'numericAnswer'        => __( 'Numeric answer', 'plugixa-exam' ),
			'tolerance'            => __( 'Tolerance', 'plugixa-exam' ),
			'toleranceHelp'        => __( 'How far from the accepted value still counts as correct.', 'plugixa-exam' ),
			'partialPolicy'        => __( 'Partial credit', 'plugixa-exam' ),
			'partialAllOrNothing'  => __( 'All or nothing', 'plugixa-exam' ),
			'partialProportional'  => __( 'Proportional', 'plugixa-exam' ),
			'partialProportionalHelp' => __( 'Award the fraction of correct options selected, less any incorrect ones.', 'plugixa-exam' ),
			// The switch on every type answered in parts — cloze here, and the premium ordering,
			// matching, EMQ and visual types. Owned by the core so each can use it.
			'partialCredit'        => __( 'Give credit for the parts that are right', 'plugixa-exam' ),
			'partialCreditHelp'    => __( 'Off means the whole answer must be correct to score anything.', 'plugixa-exam' ),
			'negativeMarking'      => __( 'Negative marking', 'plugixa-exam' ),
			'negativeMarkingHelp'  => __( 'Subtract points for each incorrect option selected.', 'plugixa-exam' ),
			'minScore'             => __( 'Minimum score', 'plugixa-exam' ),
			'negativeMarkingSingleHelp' => __( 'Subtract points when the wrong option is chosen.', 'plugixa-exam' ),
			'minScoreSingleHelp'   => __( 'What a wrong answer scores, e.g. -0.33. A blank answer scores 0.', 'plugixa-exam' ),
			'minWords'             => __( 'Minimum words', 'plugixa-exam' ),
			'maxWords'             => __( 'Maximum words', 'plugixa-exam' ),
			'essayManualNote'      => __( 'Essays are graded by hand. This question will appear in the grading queue after each attempt.', 'plugixa-exam' ),

			// Rubric.
			'rubric'               => __( 'Rubric', 'plugixa-exam' ),
			'useRubric'            => __( 'Grade with a rubric', 'plugixa-exam' ),
			'addCriterion'         => __( 'Add criterion', 'plugixa-exam' ),
			'criterionLabel'       => __( 'Criterion', 'plugixa-exam' ),
			'criterionMaxPoints'   => __( 'Max points', 'plugixa-exam' ),
			'criterionDescription' => __( 'Description', 'plugixa-exam' ),
			'rubricTotal'          => __( 'Rubric total', 'plugixa-exam' ),
			'rubricMismatch'       => __( 'The rubric total does not match the question’s points. Grading will cap at the question’s points.', 'plugixa-exam' ),

			// List.
			'difficultyLabel'      => __( 'Difficulty {n}', 'plugixa-exam' ),
			'gradedAuto'           => __( 'Auto-graded', 'plugixa-exam' ),
			'gradedManual'         => __( 'Graded by hand', 'plugixa-exam' ),
			'duplicate'            => __( 'Duplicate', 'plugixa-exam' ),
			'filterBySubject'      => __( 'All subjects', 'plugixa-exam' ),
			'filterByType'         => __( 'All types', 'plugixa-exam' ),
			'noSubject'            => __( 'No subject', 'plugixa-exam' ),
		);
	}
}
