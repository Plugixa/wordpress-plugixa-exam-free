<?php
/**
 * Certificates one learner has earned.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/my-certificates.php`.
 *
 * PRIVATE OUTPUT — never cache this. See `parts/my-results.php`.
 *
 * A WITHDRAWN CERTIFICATE IS ABSENT, not greyed out. This is the page somebody comes to in
 * order to download one, and a revoked credential rendered as unavailable-today reads as a
 * temporary problem worth retrying rather than a decision somebody made.
 *
 * The link goes to the PUBLIC verification page rather than straight to a PDF: it is the URL
 * printed on the certificate, it works for whoever the learner forwards it to, and it is the
 * one that says whether the credential is still valid.
 *
 * @package PlugixaExam
 *
 * @var array<int, array<string, mixed>> $pxe_certificates What they have earned.
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

<div class="pxe__my-certificates">

	<?php if ( array() === $pxe_certificates ) : ?>

		<p class="pxe__empty"><?php esc_html_e( 'You have not earned a certificate yet.', 'plugixa-exam' ); ?></p>

	<?php else : ?>

		<ul class="pxe__list">
			<?php foreach ( $pxe_certificates as $pxe_certificate ) : ?>
				<?php
				$pxe_expires = plugixa_exam_timestamp( $pxe_certificate['expires_at'] ?? null );
				$pxe_expired = $pxe_expires > 0 && $pxe_expires < time();
				?>
				<li class="pxe__certificate-row">
					<span class="pxe__certificate-number"><?php echo esc_html( (string) $pxe_certificate['number'] ); ?></span>

					<?php if ( ! empty( $pxe_certificate['issued_at'] ) ) : ?>
						<span class="pxe__certificate-date">
							<?php
							echo esc_html(
								wp_date(
									(string) get_option( 'date_format' ),
									plugixa_exam_timestamp( $pxe_certificate['issued_at'] )
								)
							);
							?>
						</span>
					<?php endif; ?>

					<?php if ( $pxe_expired ) : ?>
						<?php
						/*
						 * Expiry is shown and the certificate still is: it was genuinely
						 * earned, it is still evidence of that, and hiding it would be a
						 * different claim from the one expiry makes.
						 */
						?>
						<span class="pxe__certificate-expired"><?php esc_html_e( 'Expired', 'plugixa-exam' ); ?></span>
					<?php endif; ?>

					<a class="pxe__certificate-link" href="<?php echo esc_url( (string) $pxe_certificate['verify_url'] ); ?>">
						<?php esc_html_e( 'View', 'plugixa-exam' ); ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>

	<?php endif; ?>

</div>
