<?php
/**
 * The exam landing page — what it is, and the one decisive action.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/exam-body.php`.
 *
 * Shared by the routed `/exams/{slug}/` and by `[plugixa_exam_landing]` on a page the site
 * owner built, so a theme that restyles the gate restyles both.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE GATE IS A REAL FORM AND POSTS TO `admin-post.php`. DO NOT MAKE IT FETCH.
 *
 * `FormHandler` owns the nonce, the access-code comparison, the guest token and the redirect
 * for every one of sixteen verdicts. A candidate with scripting off must still be able to
 * start a paper, and one whose network drops mid-submit must get a page back rather than a
 * spinner that never resolves. The controls may become React; the submission stays a POST.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * @package PlugixaExam
 *
 * @var array<string, mixed>                $pxe_exam    The exam.
 * @var array<string, mixed>                $pxe_gate    The access verdict.
 * @var \PlugixaExam\Core\Frontend\Router $pxe_router  Link builder.
 * @var bool                                $pxe_heading Whether to print the exam's name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * Template-scope render variables, not globals — see the note in `parts/header.php`.
 */

/*
 * ⚠️ THE ROUTED PAGE ALREADY SAYS THE NAME AND AN EMBEDDED ONE DOES NOT. `/exams/algebra/`
 * puts the exam's title in the frame's `<h1>`; a page the site owner built puts THEIR page's
 * title there — "Exam" — and the only thing naming the paper was the browser tab.
 *
 * Caught on screen, not by a test: every assertion passed against a landing page that told a
 * candidate nothing about which exam they were starting.
 *
 * Defaults to false so a theme's older copy of this file, and the routed template, are
 * unchanged. The shortcode passes true.
 */
$pxe_heading = ! empty( $pxe_heading );
?>
<?php if ( $pxe_heading ) : ?>
	<h2 class="pxe-embed__title"><?php echo esc_html( (string) $pxe_exam['title'] ); ?></h2>
<?php endif; ?>
<?php
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a display-only redirect marker; nothing is changed by reading it.
$pxe_error = isset( $_GET['pxe_error'] ) ? sanitize_key( wp_unslash( (string) $_GET['pxe_error'] ) ) : '';

if ( '' !== $pxe_error ) :
	?>
	<div class="pxe-notice pxe-notice--error" role="alert">
		<?php
		/*
		 * `plugixa_exam_expired` is FormHandler's: the page had been open, or cached, for so
		 * long that its form no longer verifies. Nothing was started — say so, and the form
		 * below is fresh.
		 */
		if ( \PlugixaExam\Core\Frontend\FormHandler::EXPIRED === $pxe_error ) {
			$pxe_error_message = __( 'This page had been open for a while, so nothing was started. Please press the button again.', 'plugixa-exam' );
		} elseif ( 'plugixa_exam_bad_code' === $pxe_error ) {
			$pxe_error_message = __( 'That did not work. Please check your details and try again.', 'plugixa-exam' );
		} else {
			$pxe_error_message = __( 'Sorry — that could not be started. Please check your details and try again.', 'plugixa-exam' );
		}

		echo esc_html( $pxe_error_message );
		?>
	</div>
<?php endif; ?>

<?php if ( '' !== (string) $pxe_exam['intro'] ) : ?>
	<div class="pxe-prose">
		<?php echo wp_kses( (string) $pxe_exam['intro'], plugixa_exam_allowed_html( 'stem' ) ); ?>
	</div>
<?php endif; ?>

<ul class="pxe-facts pxe-facts--large">
	<?php if ( (int) $pxe_exam['time_limit'] > 0 ) : ?>
		<li class="pxe-facts__item">
			<span class="pxe-facts__label"><?php esc_html_e( 'Time limit', 'plugixa-exam' ); ?></span>
			<span class="pxe-facts__value">
				<?php
				printf(
					/* translators: %d: number of minutes. */
					esc_html( _n( '%d minute', '%d minutes', (int) $pxe_exam['time_limit'], 'plugixa-exam' ) ),
					(int) $pxe_exam['time_limit']
				);
				?>
			</span>
		</li>
	<?php endif; ?>

	<li class="pxe-facts__item">
		<span class="pxe-facts__label"><?php esc_html_e( 'Pass mark', 'plugixa-exam' ); ?></span>
		<span class="pxe-facts__value"><?php echo esc_html( (string) round( (float) $pxe_exam['pass_mark'] ) ); ?>%</span>
	</li>

	<?php if ( 0 !== (int) $pxe_exam['max_attempts'] ) : ?>
		<li class="pxe-facts__item">
			<span class="pxe-facts__label"><?php esc_html_e( 'Attempts allowed', 'plugixa-exam' ); ?></span>
			<span class="pxe-facts__value"><?php echo esc_html( (string) (int) $pxe_exam['max_attempts'] ); ?></span>
		</li>
	<?php endif; ?>
</ul>

<?php if ( ! empty( $pxe_gate['allowed'] ) ) : ?>

	<?php
	/*
	 * ⚠️ SAY WHAT ALREADY HAPPENED BEFORE OFFERING TO DO IT AGAIN.
	 *
	 * Somebody who passed this exam last week and still has attempts left saw a plain
	 * "Start" button and nothing else — no hint that they had done it, no score, no reason
	 * not to sit it a second time. That is the reported complaint at its sharpest, because
	 * this is the screen where the decision to repeat gets made.
	 *
	 * The button is NOT taken away. Attempts that remain are theirs to use, and an exam
	 * they may legitimately retake must not be presented as finished — that is the refusal
	 * branch's job further down, when the cap is actually reached.
	 */
	$pxe_outcome = (array) ( $pxe_exam['outcome'] ?? array() );

	if ( array() !== $pxe_outcome && 'none' !== (string) ( $pxe_outcome['state'] ?? 'none' ) ) {
		echo '<p class="pxe-gate__standing">';
		require plugixa_exam_get_template( 'parts/outcome-badge.php' );

		if ( ! empty( $pxe_outcome['submitted'] ) ) {
			echo ' <span class="pxe-gate__when">';
			printf(
				/* translators: %s: the date the learner last sat this exam. */
				esc_html__( 'last sat %s', 'plugixa-exam' ),
				esc_html(
					wp_date(
						(string) get_option( 'date_format' ),
						plugixa_exam_timestamp( $pxe_outcome['submitted'] )
					)
				)
			);
			echo '</span>';
		}

		echo '</p>';
	}

	// A resumable attempt is the common case on a second visit — say so, rather than
	// offering "Start" and quietly resuming something they had forgotten about.
	$pxe_resuming = isset( $pxe_gate['resume'] );
	?>
	<form class="pxe-gate" method="post" action="<?php echo esc_url( $pxe_router->form_url() ); ?>">
		<?php wp_nonce_field( 'plugixa_exam_gate' ); ?>
		<input type="hidden" name="action" value="plugixa_exam_gate">
		<input type="hidden" name="exam_id" value="<?php echo esc_attr( (string) $pxe_exam['id'] ); ?>">

		<button type="submit" class="pxe-button pxe-button--primary">
			<?php echo esc_html( $pxe_resuming ? __( 'Resume', 'plugixa-exam' ) : __( 'Start', 'plugixa-exam' ) ); ?>
		</button>
	</form>

<?php elseif ( 'needs_code' === ( $pxe_gate['reason'] ?? '' ) ) : ?>

	<form class="pxe-gate" method="post" action="<?php echo esc_url( $pxe_router->form_url() ); ?>">
		<?php wp_nonce_field( 'plugixa_exam_gate' ); ?>
		<input type="hidden" name="action" value="plugixa_exam_gate">
		<input type="hidden" name="exam_id" value="<?php echo esc_attr( (string) $pxe_exam['id'] ); ?>">

		<p class="pxe-gate__intro"><?php echo esc_html( (string) $pxe_gate['message'] ); ?></p>

		<label class="pxe-field">
			<span class="pxe-field__label"><?php esc_html_e( 'Access code', 'plugixa-exam' ); ?></span>
			<?php /* autocomplete off: a shared room code is not the visitor's own credential. */ ?>
			<input class="pxe-field__input" type="text" name="access_code" autocomplete="off" required>
		</label>

		<button type="submit" class="pxe-button pxe-button--primary"><?php esc_html_e( 'Continue', 'plugixa-exam' ); ?></button>
	</form>

<?php elseif ( 'needs_identify' === ( $pxe_gate['reason'] ?? '' ) ) : ?>

	<form class="pxe-gate" method="post" action="<?php echo esc_url( $pxe_router->form_url() ); ?>">
		<?php wp_nonce_field( 'plugixa_exam_gate' ); ?>
		<input type="hidden" name="action" value="plugixa_exam_gate">
		<input type="hidden" name="exam_id" value="<?php echo esc_attr( (string) $pxe_exam['id'] ); ?>">

		<p class="pxe-gate__intro"><?php echo esc_html( (string) $pxe_gate['message'] ); ?></p>

		<label class="pxe-field">
			<span class="pxe-field__label"><?php esc_html_e( 'Your name', 'plugixa-exam' ); ?></span>
			<input class="pxe-field__input" type="text" name="guest_name" autocomplete="name" required>
		</label>

		<label class="pxe-field">
			<span class="pxe-field__label"><?php esc_html_e( 'Your email', 'plugixa-exam' ); ?></span>
			<input class="pxe-field__input" type="email" name="guest_email" autocomplete="email" required>
			<span class="pxe-field__help"><?php esc_html_e( 'We use this to send you your result.', 'plugixa-exam' ); ?></span>
		</label>

		<button type="submit" class="pxe-button pxe-button--primary"><?php esc_html_e( 'Continue', 'plugixa-exam' ); ?></button>
	</form>

<?php elseif ( 'login_required' === ( $pxe_gate['reason'] ?? '' ) ) : ?>

	<div class="pxe-notice" role="status">
		<p><?php echo esc_html( (string) $pxe_gate['message'] ); ?></p>
		<a class="pxe-button pxe-button--primary" href="<?php echo esc_url( wp_login_url( $pxe_router->exam_url( (string) $pxe_exam['slug'] ) ) ); ?>">
			<?php esc_html_e( 'Log in', 'plugixa-exam' ); ?>
		</a>
	</div>

<?php else : ?>

	<div class="pxe-notice pxe-notice--muted" role="status">
		<p><?php echo esc_html( (string) $pxe_gate['message'] ); ?></p>

		<?php if ( isset( $pxe_gate['attempts_used'], $pxe_gate['attempts_allowed'] ) ) : ?>
			<p class="pxe-notice__detail">
				<?php
				printf(
					/* translators: 1: attempts used, 2: attempts allowed. */
					esc_html__( 'You have used %1$d of %2$d attempts.', 'plugixa-exam' ),
					(int) $pxe_gate['attempts_used'],
					(int) $pxe_gate['attempts_allowed']
				);
				?>
			</p>
		<?php endif; ?>
	</div>

<?php endif; ?>
<?php
/**
 * Fires after the gate, whichever way it went.
 *
 * ONE SEAM AFTER EVERY BRANCH, not one per branch: a listener reads `$gate['reason']` and
 * decides for itself whether it has anything to add — a way to get access after a refusal,
 * a line about the access somebody holds after an admission. The verdict's message above is
 * escaped plain text by design, so this is the only place anything actionable can go.
 *
 * ⚠️ WHAT A LISTENER PRINTS FOR A SIGNED-OUT VISITOR MUST BE THE SAME FOR EVERY SIGNED-OUT
 * VISITOR. A first-time visitor's landing page is served publicly and is cacheable; anything
 * personal belongs behind a sign-in, where the page is already private.
 *
 * A theme that overrides this template keeps working without the hook — it just shows
 * nothing extra — so add it to an override to get it back.
 *
 * @param array<string, mixed> $exam The exam.
 * @param array<string, mixed> $gate The access verdict.
 */
do_action( 'plugixa_exam_landing_gate', $pxe_exam, $pxe_gate );
