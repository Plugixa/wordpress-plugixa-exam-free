<?php
/**
 * Where the reader stands on one exam, in a few words.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/outcome-badge.php`.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * ONE PART, THREE SURFACES, BECAUSE THE WORDING IS THE FEATURE.
 *
 * The catalogue, "My exams" and the exam landing page all answer the same question — "have
 * I done this, and how did it go?" — and three copies of that answer is three chances for
 * one of them to say "Not passed" where another says "awaiting result". The state is
 * decided once in `Modules\Attempts\Services\Outcome`; this is the only place it is put
 * into words.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ `awaiting` DELIBERATELY SAYS NOTHING ABOUT THE MARK. It covers both "not marked yet"
 * and "marked, but this exam does not release results until later", and it must not
 * distinguish them: on a scheduled release, "your paper is marked" is precisely the fact
 * the schedule exists to withhold. There is no percentage on this branch because
 * `Outcome::of()` does not send one.
 *
 * @package PlugixaExam
 *
 * @var array<string, mixed> $pxe_outcome One entry from `Outcome::of()`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * Template-scope render variables, not globals — see the note in `parts/header.php`.
 */

$pxe_outcome = isset( $pxe_outcome ) && is_array( $pxe_outcome ) ? $pxe_outcome : array();
$pxe_state   = (string) ( $pxe_outcome['state'] ?? 'none' );

// Nothing to say about an exam nobody has opened, and an empty badge is worse than none.
if ( '' === $pxe_state || 'none' === $pxe_state ) {
	return;
}

$pxe_percent = $pxe_outcome['percent'] ?? null;

/*
 * ⚠️ FOUR WHOLE SENTENCES, NOT A LABEL GLUED TO A NUMBER. "Passed" and "92%" concatenated
 * happens to read correctly in English and does not survive a language that inflects the
 * verb, or one that writes the figure first. The same reasoning as `navLabel()` in the
 * runner. The percentage goes through `plugixa_exam_format_number()` because that is the
 * one place a mark becomes text — never `number_format_i18n()`, which follows the WordPress
 * locale rather than the site owner's chosen format.
 */
$pxe_label = match ( $pxe_state ) {
	'passed'      => null === $pxe_percent
		? __( 'Passed', 'plugixa-exam' )
		: sprintf(
			/* translators: %s: the learner's best score as a percentage, already formatted. */
			__( 'Passed: %s%%', 'plugixa-exam' ),
			plugixa_exam_format_number( (float) $pxe_percent )
		),
	'failed'      => null === $pxe_percent
		? __( 'Not passed', 'plugixa-exam' )
		: sprintf(
			/* translators: %s: the learner's best score as a percentage, already formatted. */
			__( 'Not passed: %s%%', 'plugixa-exam' ),
			plugixa_exam_format_number( (float) $pxe_percent )
		),
	'awaiting'    => __( 'Submitted, awaiting result', 'plugixa-exam' ),
	'in_progress' => __( 'In progress', 'plugixa-exam' ),
	default       => '',
};

if ( '' === (string) $pxe_label ) {
	return;
}
?>
<span class="pxe-outcome pxe-outcome--<?php echo esc_attr( $pxe_state ); ?>">
	<?php
	/*
	 * The glyph is decorative and hidden from assistive technology: the sentence beside it
	 * already says everything it says, and a screen reader announcing "check mark Passed"
	 * reads the state twice.
	 */
	?>
	<span class="pxe-outcome__mark" aria-hidden="true"></span>
	<span class="pxe-outcome__text"><?php echo esc_html( (string) $pxe_label ); ?></span>
</span>
