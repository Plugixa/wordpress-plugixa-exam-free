<?php
/**
 * An attempt's result.
 *
 * Override in a theme at `yourtheme/plugixa-exam/result.php`.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THIS FILE USED TO BE FOUR HUNDRED LINES, AND ALL BUT TWENTY OF THEM BELONGED TO SOMEBODY
 * ELSE.
 *
 * It built the whole state at the top and rendered the whole page below it, which was right
 * for exactly as long as `/result/{id}/` was the only way to reach a result. A site owner
 * can now put the same view on a page of their own with `[plugixa_exam_result]`, and a
 * template is not something a shortcode can call into.
 *
 * So the computation moved to {@see \PlugixaExam\Core\Frontend\ResultState} and the markup
 * to `parts/result-body.php`, and both are now shared. What is left here is what a routed
 * page genuinely owns: resolving the attempt from the URL, the title, and the frame.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * @package PlugixaExam
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * Template-scope render variables, not globals — see the note in `parts/header.php`.
 */

/*
 * ⚠️ BEFORE ANY OUTPUT. ONE ATTEMPT'S MARK, WHOEVER ASKS.
 *
 * Ownership is checked in PHP, so a cached copy skips the check entirely: attempt ids are
 * sequential, and a page cache that stored a guest's `/result/41/` would show it to anybody
 * who typed 41. `[plugixa_exam_result]` has always been marked private; this template, the
 * other route to the same view, never was. The "not available" answer is marked too — it is
 * what a stranger sees, and must not be what the owner is served from cache.
 */
\PlugixaExam\Core\Frontend\PrivateResponse::mark();

$pxe_data  = \PlugixaExam\Core\Plugin::instance()->container()->get( \PlugixaExam\Core\Frontend\ViewData::class );
$pxe_state = \PlugixaExam\Core\Frontend\ResultState::of( $pxe_data );

$pxe_modifier = 'pxe--result';
$pxe_view     = 'result';

/*
 * ⚠️ NO REDIRECT, AND `take.php` IS THE EXCEPTION RATHER THAN THE PATTERN. A result that
 * is missing, or belongs to somebody else, renders a sentence and stops. The same code has
 * to work inside `the_content` on a page the owner built, where headers went out long ago
 * and `wp_safe_redirect()` is a fatal error dressed as a warning.
 */
if ( null === $pxe_state ) {
	// A 404 for the same reason as `exam.php` — and it says nothing about whether a result
	// that belongs to somebody else exists, which a "forbidden" would.
	status_header( 404 );

	$pxe_title = __( 'Result not found', 'plugixa-exam' );

	require plugixa_exam_get_template( 'parts/header.php' );

	echo '<p class="pxe__empty">' . esc_html__( 'This result is not available.', 'plugixa-exam' ) . '</p>';

	require plugixa_exam_get_template( 'parts/footer.php' );

	return;
}

$pxe_title = $pxe_state['title'];

require plugixa_exam_get_template( 'parts/header.php' );
require plugixa_exam_get_template( 'parts/view-open.php' );
require plugixa_exam_get_template( 'parts/result-body.php' );
require plugixa_exam_get_template( 'parts/view-close.php' );
require plugixa_exam_get_template( 'parts/footer.php' );
