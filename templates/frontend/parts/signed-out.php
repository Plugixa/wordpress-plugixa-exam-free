<?php
/**
 * What a signed-out visitor sees on one of the learner's own pages.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/signed-out.php`.
 *
 * Shared by `/my-exams/`, `/my-results/` and `/my-certificates/`, which are the same
 * refusal three times. There is deliberately no React here: there is nothing to render and
 * nothing to fetch, and a bundle that booted to draw one sentence would be a bundle
 * downloaded for nothing.
 *
 * ⚠️ IT SENDS THEM BACK WHERE THEY WERE, not to the dashboard. `add_query_arg( array() )`
 * is the current URL, so a learner who clicked "My results" while signed out lands on their
 * results after signing in rather than on the site's front page wondering what happened.
 *
 * @package PlugixaExam
 *
 * @var string $pxe_signed_out_title The sentence to lead with.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * Template-scope render variables, not globals — see the note in `parts/header.php`.
 */

$pxe_signed_out_title = $pxe_signed_out_title ?? __( 'Sign in to see your exams.', 'plugixa-exam' );
?>
<div class="pxe-notice">
	<p class="pxe-notice__title"><?php echo esc_html( $pxe_signed_out_title ); ?></p>
	<p class="pxe-notice__detail">
		<a href="<?php echo esc_url( wp_login_url( home_url( add_query_arg( array() ) ) ) ); ?>">
			<?php esc_html_e( 'Log in', 'plugixa-exam' ); ?>
		</a>
	</p>
</div>
