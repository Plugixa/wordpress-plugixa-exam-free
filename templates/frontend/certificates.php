<?php
/**
 * The learner's own certificates.
 *
 * Override in a theme at `yourtheme/plugixa-exam/certificates.php`.
 *
 * A page, not the `#pxe-my-certificates` anchor into the portal that it replaces — and the
 * navigation item for it can finally be highlighted, which it never could while it pointed
 * at a heading. See `portal.php` for the split, and note the state here is the same
 * `Portal::overview()` payload all three learner pages carry.
 *
 * ⚠️ A FREE BUILD RENDERS THIS PAGE AND IT IS EMPTY, WHICH IS CORRECT. Certificates are a
 * premium module answering `plugixa_exam_portal_certificates`; with it absent the filter
 * returns an empty list and the page says so. It does not advertise the upgrade — a learner
 * who followed a menu item to be sold something has been ambushed by their own site.
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

\PlugixaExam\Core\Frontend\PrivateResponse::mark();

$pxe_title    = __( 'My certificates', 'plugixa-exam' );
$pxe_modifier = 'pxe--certificates';
$pxe_view     = 'certificates';

require plugixa_exam_get_template( 'parts/header.php' );
?>

<?php if ( 0 === get_current_user_id() ) : ?>

	<?php
	$pxe_signed_out_title = __( 'Sign in to see your certificates.', 'plugixa-exam' );

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
		/* The same part `[plugixa_exam_my_certificates]` renders. */
		$pxe_certificates = $pxe_state['certificates'];

		require plugixa_exam_get_template( 'parts/my-certificates.php' );
		?>

	<?php require plugixa_exam_get_template( 'parts/view-close.php' ); ?>

<?php endif; ?>

<?php require plugixa_exam_get_template( 'parts/footer.php' ); ?>
