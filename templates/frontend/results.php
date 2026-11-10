<?php
/**
 * The learner's own results.
 *
 * Override in a theme at `yourtheme/plugixa-exam/results.php`.
 *
 * A page, not the `#pxe-my-results` anchor into the portal that it replaces. See
 * `portal.php` for why that split happened, and note the state here is the SAME
 * `Portal::overview()` payload all three learner pages carry — three readings of one
 * shape, so moving between them needs no second request.
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
 * ⚠️ BEFORE ANY OUTPUT. A page listing somebody's marks must never be handed to the next
 * visitor by a page cache, and headers sent after the first byte do nothing.
 */
\PlugixaExam\Core\Frontend\PrivateResponse::mark();

$pxe_title    = __( 'My results', 'plugixa-exam' );
$pxe_modifier = 'pxe--results';
$pxe_view     = 'results';

require plugixa_exam_get_template( 'parts/header.php' );
?>

<?php if ( 0 === get_current_user_id() ) : ?>

	<?php
	$pxe_signed_out_title = __( 'Sign in to see your results.', 'plugixa-exam' );

	require plugixa_exam_get_template( 'parts/signed-out.php' );
	?>

<?php else : ?>

	<?php
	$pxe_state = \PlugixaExam\Core\Plugin::instance()->container()
		->get( \PlugixaExam\Modules\Portal\Services\Portal::class )
		->overview( get_current_user_id() );

	require plugixa_exam_get_template( 'parts/view-open.php' );
	?>

		<?php
		/*
		 * The same part `[plugixa_exam_my_results]` renders, so scripting off still shows a
		 * learner their marks. React replaces it with the grid and the trend on mount.
		 */
		$pxe_results = $pxe_state['results'];

		require plugixa_exam_get_template( 'parts/my-results.php' );
		?>

	<?php require plugixa_exam_get_template( 'parts/view-close.php' ); ?>

<?php endif; ?>

<?php require plugixa_exam_get_template( 'parts/footer.php' ); ?>
