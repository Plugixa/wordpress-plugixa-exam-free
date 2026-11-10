<?php
/**
 * One learner's own attempts.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/my-results.php`.
 *
 * PRIVATE OUTPUT — never cache this. It is one person's data, and the caller sends
 * `nocache_headers()` and marks the wrapper `data-pxe-private`. A theme override that keeps
 * the markup but is rendered through some other path inherits neither.
 *
 * AN UNRELEASED ROW CARRIES NO NUMBERS AT ALL, not numbers this template declines to print.
 * `Portal::results()` omits `percent`, `passed` and the result link entirely, so there is
 * nothing here to accidentally reveal — and nothing in the page source either.
 *
 * @package PlugixaExam
 *
 * @var array<int, array<string, mixed>> $pxe_results The learner's attempts, newest first.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * TEMPLATE-SCOPE RENDER VARIABLES, NOT GLOBALS — measured, not assumed.
 *
 * PHPCS reads each file standalone, sees assignments at file scope and concludes they are
 * globals. They are not: `TemplateLoader::render()` includes a template INSIDE A CLOSURE
 * (and WordPress includes a block's `render.php` inside `WP_Block::render()`), so every
 * `$pxe_*` here is function-scope and disappears when the template returns. Probed on a
 * live site by assigning a marker in a template and checking `$GLOBALS` afterwards: absent.
 *
 * The names are already prefixed `pxe_`, which is three characters — one under this
 * sniff's minimum — and lengthening it would rename a hundred and twenty-six variables
 * across twenty-two files to satisfy a rule about a scope they never reach.
 */
?>

<div class="pxe__my-results">

	<?php if ( array() === $pxe_results ) : ?>

		<p class="pxe__empty"><?php esc_html_e( 'You have not finished any exams yet.', 'plugixa-exam' ); ?></p>

	<?php else : ?>

		<ul class="pxe__list">
			<?php foreach ( $pxe_results as $pxe_result ) : ?>
				<li class="pxe__result-row">
					<span class="pxe__result-exam"><?php echo esc_html( (string) $pxe_result['exam_title'] ); ?></span>

					<?php if ( ! empty( $pxe_result['submitted'] ) ) : ?>
						<span class="pxe__result-date">
							<?php
							echo esc_html(
								wp_date(
									(string) get_option( 'date_format' ),
									plugixa_exam_timestamp( $pxe_result['submitted'] )
								)
							);
							?>
						</span>
					<?php endif; ?>

					<?php if ( empty( $pxe_result['released'] ) ) : ?>

						<?php
						/*
						 * "Not out yet" rather than "no score": the second reads as a failure
						 * to record the paper, which is the interpretation a learner reaches
						 * for when they cannot see a number they expected.
						 */
						?>
						<span class="pxe__result-pending"><?php esc_html_e( 'Results are not out yet.', 'plugixa-exam' ); ?></span>

					<?php else : ?>

						<span class="pxe__result-score">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: score as a percentage. */
									__( '%s%%', 'plugixa-exam' ),
									plugixa_exam_format_number( (float) $pxe_result['percent'] )
								)
							);
							?>
						</span>

						<?php if ( null !== ( $pxe_result['passed'] ?? null ) ) : ?>
							<span class="pxe__result-outcome pxe__result-outcome--<?php echo esc_attr( $pxe_result['passed'] ? 'pass' : 'fail' ); ?>">
								<?php echo esc_html( $pxe_result['passed'] ? __( 'Passed', 'plugixa-exam' ) : __( 'Not passed', 'plugixa-exam' ) ); ?>
							</span>
						<?php endif; ?>

						<a class="pxe__result-link" href="<?php echo esc_url( (string) $pxe_result['url'] ); ?>">
							<?php esc_html_e( 'View', 'plugixa-exam' ); ?>
						</a>

					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

	<?php endif; ?>

</div>
