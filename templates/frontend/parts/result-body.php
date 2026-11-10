<?php
/**
 * The result itself — outcome, certificate, feedback and review.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/result-body.php`.
 *
 * ⚠️ THIS IS THE SERVER'S OWN COPY OF THE PAGE, NOT A PLACEHOLDER. The React view calls
 * `replaceChildren()` on the mount around it, so everything here is what a reader looks at
 * until the bundle boots — and all of it is what a reader with no JavaScript ever sees. It
 * is not a spinner and not a skeleton: it is the result, rendered.
 *
 * Shared by the routed `/result/{id}/` and by `[plugixa_exam_result]` on a page the site
 * owner built, so a theme that restyles it restyles both.
 *
 * @package PlugixaExam
 *
 * @var array<string, mixed> $pxe_state Everything the view draws. See {@see \PlugixaExam\Core\Frontend\ResultState}.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * Template-scope render variables, not globals — see the note in `parts/header.php`.
 */
?>

<?php if ( ! $pxe_state['released'] ) : ?>

	<div class="pxe-notice" role="status">
		<p><?php echo esc_html( wp_strip_all_tags( $pxe_state['message'] ) ); ?></p>
	</div>

<?php else : ?>

	<div class="pxe-outcome <?php echo esc_attr( $pxe_state['passed'] ? 'pxe-outcome--pass' : 'pxe-outcome--fail' ); ?>" role="status">
		<p class="pxe-outcome__verdict">
			<?php echo esc_html( $pxe_state['verdict'] ); ?>

			<?php if ( null !== $pxe_state['grade'] ) : ?>
				<?php
				// A hex colour or nothing. It is going into CSS, where `esc_attr()` alone
				// stops nothing: see `ResultState`, which has already asked the same question.
				$pxe_grade_colour = (string) sanitize_hex_color( (string) ( $pxe_state['grade']['colour'] ?? '' ) );
				?>
				<span
					class="pxe-outcome__grade"
					<?php if ( '' !== $pxe_grade_colour ) : ?>
						style="--pxe-grade: <?php echo esc_attr( $pxe_grade_colour ); ?>"
					<?php endif; ?>
				><?php echo esc_html( $pxe_state['grade']['label'] ); ?></span>
			<?php endif; ?>
		</p>

		<p class="pxe-outcome__score"><?php echo esc_html( $pxe_state['scoreLine'] ); ?></p>

		<?php if ( $pxe_state['pending'] ) : ?>
			<p class="pxe-outcome__pending"><?php echo esc_html( $pxe_state['pendingNote'] ); ?></p>
		<?php endif; ?>
	</div>

	<?php if ( '' !== $pxe_state['message'] ) : ?>
		<div class="pxe-prose">
			<?php echo wp_kses( $pxe_state['message'], plugixa_exam_allowed_html( 'stem' ) ); ?>
		</div>
	<?php endif; ?>

	<?php if ( null !== $pxe_state['certificate'] ) : ?>
		<div class="pxe-notice">
			<h2 class="pxe-notice__title"><?php echo esc_html( $pxe_state['labels']['certificate'] ); ?></h2>
			<p class="pxe-notice__detail"><?php echo esc_html( $pxe_state['certificate']['line'] ); ?></p>

			<p class="pxe-actions">
				<?php if ( empty( $pxe_state['certificate']['withdrawn'] ) ) : ?>
					<a class="pxe-button pxe-button--primary" href="<?php echo esc_url( $pxe_state['certificate']['pdfUrl'] ); ?>">
						<?php echo esc_html( $pxe_state['labels']['downloadPdf'] ); ?>
					</a>
				<?php endif; ?>

				<a href="<?php echo esc_url( $pxe_state['certificate']['verifyUrl'] ); ?>">
					<?php echo esc_html( $pxe_state['labels']['verifyLink'] ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( '' !== $pxe_state['feedback'] ) : ?>
		<div class="pxe-notice">
			<h2 class="pxe-notice__title"><?php echo esc_html( $pxe_state['labels']['feedback'] ); ?></h2>
			<p><?php echo esc_html( $pxe_state['feedback'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( array() !== $pxe_state['review'] ) : ?>
		<h2 class="pxe-section-title"><?php echo esc_html( $pxe_state['labels']['yourAnswers'] ); ?></h2>

		<ol class="pxe-review">
			<?php foreach ( $pxe_state['review'] as $pxe_item ) : ?>
				<li class="pxe-review__item pxe-review__item--<?php echo esc_attr( $pxe_item['state'] ); ?>">
					<div class="pxe-review__head">
						<span class="pxe-question__number"><?php echo esc_html( $pxe_item['number'] ); ?></span>
						<span class="pxe-chip pxe-chip--<?php echo esc_attr( $pxe_item['state'] ); ?>"><?php echo esc_html( $pxe_item['label'] ); ?></span>
						<span class="pxe-review__points"><?php echo esc_html( $pxe_item['points'] ); ?></span>
					</div>

					<?php if ( '' !== $pxe_item['context'] ) : ?>
						<div class="pxe-review__context pxe-prose">
							<?php echo wp_kses( $pxe_item['context'], plugixa_exam_allowed_html( 'stem' ) ); ?>
						</div>
					<?php endif; ?>

					<div class="pxe-question__stem pxe-prose">
						<?php echo wp_kses( $pxe_item['stem'], plugixa_exam_allowed_html( 'stem' ) ); ?>
					</div>

					<?php if ( array() !== $pxe_item['options'] ) : ?>
						<ul class="pxe-review__options">
							<?php foreach ( $pxe_item['options'] as $pxe_option ) : ?>
								<li class="pxe-review__option<?php echo esc_attr( $pxe_option['isCorrect'] ? ' is-correct' : '' ); ?><?php echo esc_attr( $pxe_option['wasChosen'] ? ' is-chosen' : '' ); ?>">
									<?php echo wp_kses( $pxe_option['text'], plugixa_exam_allowed_html() ); ?>

									<?php if ( $pxe_option['wasChosen'] ) : ?>
										<span class="pxe-review__marker"><?php echo esc_html( $pxe_state['labels']['yourAnswer'] ); ?></span>
									<?php endif; ?>

									<?php if ( $pxe_option['isCorrect'] && ! $pxe_option['wasChosen'] ) : ?>
										<span class="pxe-review__marker"><?php echo esc_html( $pxe_state['labels']['rightAnswer'] ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php elseif ( '' !== $pxe_item['given'] ) : ?>
						<p class="pxe-review__given">
							<span class="pxe-review__marker"><?php echo esc_html( $pxe_state['labels']['yourAnswer'] ); ?></span>
							<?php echo esc_html( $pxe_item['given'] ); ?>
						</p>
					<?php endif; ?>

					<?php if ( '' !== $pxe_item['explanation'] ) : ?>
						<div class="pxe-review__explanation pxe-prose">
							<?php echo wp_kses( $pxe_item['explanation'], plugixa_exam_allowed_html( 'stem' ) ); ?>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

<?php endif; ?>

<p class="pxe-actions">
	<a class="pxe-button" href="<?php echo esc_url( $pxe_state['catalogueUrl'] ); ?>">
		<?php echo esc_html( $pxe_state['labels']['backToExams'] ); ?>
	</a>
</p>

