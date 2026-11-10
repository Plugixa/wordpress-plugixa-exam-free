<?php
/**
 * The learner's assigned exams.
 *
 * Override in a theme at `yourtheme/plugixa-exam/portal.php`.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THIS PAGE USED TO BE THREE PAGES STACKED ON TOP OF EACH OTHER.
 *
 * It rendered "Assigned to me", then "My results" behind an `id="pxe-my-results"` anchor,
 * then "My certificates" behind another — and the navigation linked to those anchors. The
 * certificates item could not be highlighted at all, so it hard-coded `current => false`:
 * a menu entry permanently unable to say where the reader was. `/my-results/` and
 * `/my-certificates/` are real routes now, and this page keeps assignments alone.
 *
 * ⚠️ ALL THREE STILL SHARE ONE STATE, AND THAT IS DELIBERATE. `Portal::overview()` returns
 * assignments, results and certificates together, and it is exactly what `GET /portal`
 * returns — so the three views are three readings of one payload rather than three shapes.
 * Landing here and clicking through to results needs no second request at all.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * @package PlugixaExam
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * TEMPLATE-SCOPE RENDER VARIABLES, NOT GLOBALS — measured, not assumed. See the long note
 * in `parts/header.php`: `TemplateLoader::render()` includes a template inside a closure, so
 * every `$pxe_*` here is function-scope and disappears when the template returns.
 */

\PlugixaExam\Core\Frontend\PrivateResponse::mark();

$pxe_title    = __( 'My exams', 'plugixa-exam' );
$pxe_modifier = 'pxe--portal';
$pxe_view     = 'portal';

require plugixa_exam_get_template( 'parts/header.php' );
?>

<?php if ( 0 === get_current_user_id() ) : ?>

	<?php require plugixa_exam_get_template( 'parts/signed-out.php' ); ?>

<?php else : ?>

	<?php
	$pxe_state = \PlugixaExam\Core\Plugin::instance()->container()
		->get( \PlugixaExam\Modules\Portal\Services\Portal::class )
		->overview( get_current_user_id() );

	require plugixa_exam_get_template( 'parts/view-open.php' );
	?>

		<?php
		/*
		 * A NO-JAVASCRIPT FALLBACK THAT IS THE REAL LIST, not a "please enable JavaScript"
		 * apology. This is the same template part the `[plugixa_exam_my_exams]` shortcode
		 * renders, so somebody with scripting off gets their exams — read-only, which is all
		 * this page is. React replaces the contents on mount.
		 *
		 * A dashboard that is blank without JavaScript is a dashboard that is blank on a
		 * corporate network with a strict CSP.
		 */
		$pxe_groups = $pxe_state['exams'];

		require plugixa_exam_get_template( 'parts/my-exams.php' );
		?>

	<?php require plugixa_exam_get_template( 'parts/view-close.php' ); ?>

<?php endif; ?>

<?php require plugixa_exam_get_template( 'parts/footer.php' ); ?>
