<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Subjects;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin strings for the Subjects screens.
 */
final class Strings {

	/**
	 * @return array<string, string>
	 */
	public static function all(): array {
		return array(
			'subjects'              => __( 'Subjects', 'plugixa-exam' ),
			'subjectsDesc' => __( 'The tree of subjects that questions and exams are filed under.', 'plugixa-exam' ),
			'subject'               => __( 'Subject', 'plugixa-exam' ),
			'newSubject'            => __( 'New subject', 'plugixa-exam' ),
			'editSubject'           => __( 'Edit subject', 'plugixa-exam' ),
			'subjectTitle'          => __( 'Title', 'plugixa-exam' ),
			'subjectCode'           => __( 'Code', 'plugixa-exam' ),
			'subjectCodeHelp'       => __( 'An optional short reference, e.g. MATH-101. Must be unique.', 'plugixa-exam' ),
			'subjectParent'         => __( 'Parent subject', 'plugixa-exam' ),
			'subjectNoParent'       => __( 'None (top level)', 'plugixa-exam' ),
			'subjectDescription'    => __( 'Description', 'plugixa-exam' ),
			'subjectPublished'      => __( 'Published', 'plugixa-exam' ),
			'subjectsEmpty'         => __( 'No subjects yet. Create one to start filing questions.', 'plugixa-exam' ),
			'subjectsIntro'         => __( 'Subjects organise your question bank. Random-draw exam sections can pull from a subject and everything beneath it.', 'plugixa-exam' ),
			'subjectDeleteConfirm'  => __( 'Delete “{title}”?', 'plugixa-exam' ),
			'subjectHasChildren'    => __( 'Move or delete this subject’s child subjects first.', 'plugixa-exam' ),
			'subjectAddChild'       => __( 'Add child subject', 'plugixa-exam' ),
			'subjectQuestionCount'  => __( '{count} question(s)', 'plugixa-exam' ),
		);
	}
}
