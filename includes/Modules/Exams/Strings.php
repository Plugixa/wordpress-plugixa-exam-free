<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Exams;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin strings for the exam builder.
 */
final class Strings {

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'exams'          => __( 'Exams', 'plugixa-exam' ),
			'examsDesc' => __( 'Compose a paper from sections, and set the rules for sitting it.', 'plugixa-exam' ),
			'exam'           => __( 'Exam', 'plugixa-exam' ),
			'newExam'        => __( 'New exam', 'plugixa-exam' ),
			'editExam'       => __( 'Edit exam', 'plugixa-exam' ),
			'examsEmpty'     => __( 'No exams yet. Build one from the questions in your bank.', 'plugixa-exam' ),
			'examsIntro'     => __( 'An exam is a set of sections — either a list of questions you choose, or a rule that draws them at random.', 'plugixa-exam' ),

			// Tabs.
			'tabGeneral'     => __( 'General', 'plugixa-exam' ),
			'tabQuestions'   => __( 'Questions', 'plugixa-exam' ),
			'tabTiming'      => __( 'Timing', 'plugixa-exam' ),
			'tabAccess'      => __( 'Access', 'plugixa-exam' ),
			'tabResults'     => __( 'Results', 'plugixa-exam' ),

			// General.
			'examTitle'      => __( 'Title', 'plugixa-exam' ),
			'examDescription' => __( 'Description', 'plugixa-exam' ),
			'examIntro'      => __( 'Rules and instructions', 'plugixa-exam' ),
			'examIntroHelp'  => __( 'Shown on the landing page before the examinee starts.', 'plugixa-exam' ),
			'examSubject'    => __( 'Subject', 'plugixa-exam' ),
			'examPublished'  => __( 'Published', 'plugixa-exam' ),

			// Composition.
			'sections'       => __( 'Sections', 'plugixa-exam' ),
			'addSection'     => __( 'Add section', 'plugixa-exam' ),
			'sectionTitle'   => __( 'Section title', 'plugixa-exam' ),
			'sectionType'    => __( 'How questions are chosen', 'plugixa-exam' ),
			'sectionFixed'   => __( 'A list I choose', 'plugixa-exam' ),
			'sectionPool'    => __( 'Drawn at random', 'plugixa-exam' ),
			'sectionPoints'  => __( 'Points per question', 'plugixa-exam' ),
			'sectionPointsHelp' => __( 'Leave blank to use each question’s own points.', 'plugixa-exam' ),
			'addQuestions'   => __( 'Add questions', 'plugixa-exam' ),
			'addRule'        => __( 'Add draw rule', 'plugixa-exam' ),
			'drawCount'      => __( 'How many to draw', 'plugixa-exam' ),
			'ruleSubject'    => __( 'From subject', 'plugixa-exam' ),
			'ruleSubjectHelp' => __( 'Includes every subject nested underneath it.', 'plugixa-exam' ),
			'ruleDifficulty' => __( 'Difficulty', 'plugixa-exam' ),
			'ruleType'       => __( 'Question type', 'plugixa-exam' ),
			'ruleAny'        => __( 'Any', 'plugixa-exam' ),
			'sectionsEmpty'  => __( 'This exam has no questions yet. Add a section to get started.', 'plugixa-exam' ),
			'questionCount'  => __( '{count} question(s)', 'plugixa-exam' ),

			// Timing.
			'timeLimit'      => __( 'Time limit', 'plugixa-exam' ),
			'timeLimitHelp'  => __( 'In minutes. Leave at 0 for no limit.', 'plugixa-exam' ),
			'maxAttempts'    => __( 'Attempts allowed', 'plugixa-exam' ),
			'maxAttemptsHelp' => __( '0 means unlimited. An attempt that was started but never submitted still counts.', 'plugixa-exam' ),
			'cooldown'       => __( 'Wait between attempts', 'plugixa-exam' ),
			'openFrom'       => __( 'Opens', 'plugixa-exam' ),
			'openUntil'      => __( 'Closes', 'plugixa-exam' ),
			'allowPause'     => __( 'Examinees can pause', 'plugixa-exam' ),
			'maxPauses'      => __( 'Pauses allowed', 'plugixa-exam' ),
			'layout'         => __( 'Layout', 'plugixa-exam' ),
			'layoutSingle'   => __( 'All questions on one page', 'plugixa-exam' ),
			'layoutPaged'    => __( 'One question per page', 'plugixa-exam' ),
			'shuffleQuestions' => __( 'Shuffle question order', 'plugixa-exam' ),
			'shuffleOptions' => __( 'Shuffle answer options', 'plugixa-exam' ),
			'reviewBeforeSubmit' => __( 'Show a summary before submitting', 'plugixa-exam' ),
			'requireAnswer'  => __( 'Unanswered questions', 'plugixa-exam' ),
			'requireOff'     => __( 'Allow', 'plugixa-exam' ),
			'requireWarn'    => __( 'Warn before submitting', 'plugixa-exam' ),
			'requireBlock'   => __( 'Block moving on', 'plugixa-exam' ),

			// Access.
			'accessType'     => __( 'Who can take this', 'plugixa-exam' ),
			'accessOpen'     => __( 'Anyone', 'plugixa-exam' ),
			'accessGuest'    => __( 'Anyone, after giving a name and email', 'plugixa-exam' ),
			'accessCode'     => __( 'Anyone with an access code', 'plugixa-exam' ),
			'accessLevel'    => __( 'Logged-in users with a role', 'plugixa-exam' ),
			'accessRestricted' => __( 'Only people I list', 'plugixa-exam' ),
			'accessCodeField' => __( 'Access code', 'plugixa-exam' ),
			'accessCodeSet'  => __( 'A code is set. Type a new one to change it, or leave blank to keep it.', 'plugixa-exam' ),

			// Results.
			'passMark'       => __( 'Pass mark', 'plugixa-exam' ),
			'resultRelease'  => __( 'When results are shown', 'plugixa-exam' ),
			'releaseImmediate' => __( 'Straight away', 'plugixa-exam' ),
			'releaseAfterClose' => __( 'After the exam closes', 'plugixa-exam' ),
			'releaseManual'  => __( 'When I release them', 'plugixa-exam' ),
			'releaseScheduled' => __( 'At a set time', 'plugixa-exam' ),
			'releaseAt'      => __( 'Release at', 'plugixa-exam' ),
			'showAnswers'    => __( 'Let examinees review the correct answers', 'plugixa-exam' ),
			'passMessage'    => __( 'Message when they pass', 'plugixa-exam' ),
			'failMessage'    => __( 'Message when they do not pass', 'plugixa-exam' ),
			'attemptMode'    => __( 'Mode', 'plugixa-exam' ),
			'modeExam'       => __( 'Exam', 'plugixa-exam' ),
			'modePractice'   => __( 'Practice', 'plugixa-exam' ),
			'idleTimeout'    => __( 'Close an abandoned attempt after', 'plugixa-exam' ),
			'idleTimeoutHelp' => __( 'In minutes. The answers already saved are marked. 0 never closes it.', 'plugixa-exam' ),
			'accessCodeHelp'           => __( 'a shared code', 'plugixa-exam' ),
			'accessCodeNew'            => __( 'Anybody with this code can sit the exam.', 'plugixa-exam' ),
			'accessGuestHelp'          => __( 'name and email, no account', 'plugixa-exam' ),
			'accessLoggedIn'           => __( 'Logged in', 'plugixa-exam' ),
			'accessLoggedInHelp'       => __( 'any signed-in user', 'plugixa-exam' ),
			'accessOpenHelp'           => __( 'no sign-in needed', 'plugixa-exam' ),
			'accessRestrictedHelp'     => __( 'named people only', 'plugixa-exam' ),
			'add'                      => __( 'Add', 'plugixa-exam' ),
			'allQuestionsAlreadyAdded' => __( 'Every question here is already in this section.', 'plugixa-exam' ),
			'allowEarlyFinish'         => __( 'Let examinees finish before time is up', 'plugixa-exam' ),
			'anyDifficulty'            => __( 'Any', 'plugixa-exam' ),
			'anySubject'               => __( 'Any subject', 'plugixa-exam' ),
			'anyType'                  => __( 'Any type', 'plugixa-exam' ),
			'autoCloseAbandon'         => __( 'Abandon the attempt', 'plugixa-exam' ),
			'autoCloseAction'          => __( 'Then', 'plugixa-exam' ),
			'autoCloseHeld'            => __( 'Also close attempts left paused', 'plugixa-exam' ),
			'autoCloseIntro'           => __( 'What to do about an attempt somebody started and walked away from.', 'plugixa-exam' ),
			'autoCloseNotify'          => __( 'Email about auto-closed attempts', 'plugixa-exam' ),
			'autoCloseSubmit'          => __( 'Mark what they answered', 'plugixa-exam' ),
			'autoCloseTimeSpent'       => __( 'Record time spent as', 'plugixa-exam' ),
			'closesAt'                 => __( 'Closes', 'plugixa-exam' ),
			'composition'              => __( 'Composition', 'plugixa-exam' ),
			'confirmDeleteExam'        => __( 'Delete this exam?', 'plugixa-exam' ),
			'confirmDeleteExamBody'    => __( 'Attempts already taken are kept, so past results stay readable. The exam disappears from the catalogue.', 'plugixa-exam' ),
			'examAttempts'             => __( 'Attempts allowed', 'plugixa-exam' ),
			'examCooldown'             => __( 'Wait between attempts', 'plugixa-exam' ),
			'examDescriptionHelp'      => __( 'Shown in the catalogue listing.', 'plugixa-exam' ),
			'examHasNoQuestions'       => __( 'No section will produce a question, so this exam cannot be sat.', 'plugixa-exam' ),
			'examLayout'               => __( 'Layout', 'plugixa-exam' ),
			'examPassMark'             => __( 'Pass mark', 'plugixa-exam' ),
			'examPublishedLabel'       => __( 'Published — visible in the catalogue', 'plugixa-exam' ),
			'examSlug'                 => __( 'URL slug', 'plugixa-exam' ),
			'examSlugHelp'             => __( 'Leave empty to build one from the title.', 'plugixa-exam' ),
			'examTimeLimit'            => __( 'Time limit', 'plugixa-exam' ),
			'examWindow'               => __( 'Availability window', 'plugixa-exam' ),
			'fullscreenOff'            => __( 'Off', 'plugixa-exam' ),
			'fullscreenOffered'        => __( 'Offered', 'plugixa-exam' ),
			'fullscreenRequired'       => __( 'Required', 'plugixa-exam' ),
			'layoutPerPage'            => __( 'One question per page', 'plugixa-exam' ),
			'lockAuto'                 => __( 'Automatically', 'plugixa-exam' ),
			'lockCapOnly'              => __( 'Only at the attempt limit', 'plugixa-exam' ),
			'lockManual'               => __( 'Only when I say so', 'plugixa-exam' ),
			'lockNever'                => __( 'Never', 'plugixa-exam' ),
			'lockPolicy'               => __( 'When to stop further attempts', 'plugixa-exam' ),
			'maxPauseMinutes'          => __( 'Total pause time', 'plugixa-exam' ),
			'minutesZeroNoLimit'       => __( 'Minutes. 0 means no limit.', 'plugixa-exam' ),
			/* translators: %d is a number. */
			'missingQuestion'          => __( 'Question #%d (not found)', 'plugixa-exam' ),
			/* translators: %d is a number. */
			'nQuestionsTotal'          => __( '%d questions', 'plugixa-exam' ),
			/* translators: %d is a number. */
			'nSelected'                => __( '%d selected', 'plugixa-exam' ),
			'noQuestionsInSection'     => __( 'No questions in this section yet.', 'plugixa-exam' ),
			'noQuestionsMatch'         => __( 'No questions match these filters.', 'plugixa-exam' ),
			'noSectionsYet'            => __( 'This exam has no sections, so there is nothing to sit. Add one to begin.', 'plugixa-exam' ),
			'noTimeLimit'              => __( 'No limit', 'plugixa-exam' ),
			'noneSelected'             => __( 'Nothing selected', 'plugixa-exam' ),
			'notifyAuto'               => __( 'As the exam normally would', 'plugixa-exam' ),
			'notifyNormal'             => __( 'Always', 'plugixa-exam' ),
			'notifySilent'             => __( 'Never', 'plugixa-exam' ),
			'opensAt'                  => __( 'Opens', 'plugixa-exam' ),
			'points'                   => __( 'Points', 'plugixa-exam' ),
			'pointsPerQuestion'        => __( 'Points per question', 'plugixa-exam' ),
			'pointsPerQuestionHelp'    => __( 'Leave empty to use each question’s own value.', 'plugixa-exam' ),
			'poolHelp'                 => __( 'Each rule draws its own questions when somebody starts. Leave a filter empty to ignore it.', 'plugixa-exam' ),
			'practiceReveal'           => __( 'Reveal answers', 'plugixa-exam' ),
			'removeSection'            => __( 'Remove section', 'plugixa-exam' ),
			'revealOnSubmit'           => __( 'When they submit', 'plugixa-exam' ),
			'revealPerQuestion'        => __( 'After each question', 'plugixa-exam' ),
			'sectionDescription'       => __( 'Description', 'plugixa-exam' ),
			/* translators: %d is a number. */
			'sectionTitlePlaceholder'  => __( 'Part %d', 'plugixa-exam' ),
			'tabAutoClose'             => __( 'Unattended', 'plugixa-exam' ),
			'tabComposition'           => __( 'Questions', 'plugixa-exam' ),
			'tabDetails'               => __( 'Details', 'plugixa-exam' ),
			'tabPresentation'          => __( 'Presentation', 'plugixa-exam' ),
			'timeCapped'               => __( 'Up to the time limit', 'plugixa-exam' ),
			'timeWallclock'            => __( 'Until it was closed', 'plugixa-exam' ),
			'trackIntegrity'           => __( 'Record focus changes and paste events (never affects the score)', 'plugixa-exam' ),
			'unlimited'                => __( 'Unlimited', 'plugixa-exam' ),
			'viewOnSite'               => __( 'View on site', 'plugixa-exam' ),
			'whoMaySit'                => __( 'Who may sit this exam', 'plugixa-exam' ),
			'windowClose'              => __( 'Close attempts still open', 'plugixa-exam' ),
			'windowCloseAction'        => __( 'When the exam window closes', 'plugixa-exam' ),
			'windowRunOut'             => __( 'Let their own clock run out', 'plugixa-exam' ),
			'zeroUnlimited'            => __( '0 means unlimited.', 'plugixa-exam' ),
		);
	}
}
