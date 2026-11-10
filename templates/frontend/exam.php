<?php
/**
 * An exam's landing page.
 *
 * Override in a theme at `yourtheme/plugixa-exam/exam.php`.
 *
 * The markup moved to `parts/exam-body.php` so `[plugixa_exam_landing]` can render the same
 * gate on a page the site owner built. What is left here is what a routed page owns:
 * resolving the exam from the URL, the title, and the frame.
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
 * ⚠️ PUBLIC UNTIL THE VISITOR HAS A HISTORY HERE, THEN PRIVATE — first, before any output.
 *
 * A first-time visitor's landing page is the same for everybody and should be cached. Once
 * somebody is signed in or has started as a guest, the gate below describes THEIR attempts —
 * resume, attempts used, a cooldown — and that copy must not be stored under the URL. The same
 * decision `[plugixa_exam_landing]` makes.
 */
if ( \PlugixaExam\Core\Frontend\PrivateResponse::visitor_is_known() || \PlugixaExam\Core\Frontend\PrivateResponse::answers_a_form() ) {
	\PlugixaExam\Core\Frontend\PrivateResponse::mark();
}

$pxe_data   = \PlugixaExam\Core\Plugin::instance()->container()->get( \PlugixaExam\Core\Frontend\ViewData::class );
$pxe_router = $pxe_data->router();
$pxe_exam   = $pxe_data->exam();

$pxe_modifier = 'pxe--exam';

if ( null === $pxe_exam ) {
	/*
	 * A 404, not a page reading "Exam not found" under a 200. The Router tells WordPress every
	 * URL its rules match is a real page — right for an exam that exists — so a deleted or
	 * mistyped slug went out as a success that search engines index and keep. Found on the
	 * clean site, 24 Sep 2026, after an E2E fixture was removed. Before any output, as the
	 * private mark above; `status_header()` does nothing once headers have gone.
	 */
	status_header( 404 );

	$pxe_title = __( 'Exam not found', 'plugixa-exam' );

	require plugixa_exam_get_template( 'parts/header.php' );

	echo '<p class="pxe__empty">' . esc_html__( 'This exam is not available.', 'plugixa-exam' ) . '</p>';

	require plugixa_exam_get_template( 'parts/footer.php' );

	return;
}

$pxe_gate  = $pxe_data->gate( $pxe_exam ) ?? array( 'allowed' => false, 'reason' => 'denied', 'message' => '' );
$pxe_title = (string) $pxe_exam['title'];

require plugixa_exam_get_template( 'parts/header.php' );
require plugixa_exam_get_template( 'parts/exam-body.php' );
require plugixa_exam_get_template( 'parts/footer.php' );
