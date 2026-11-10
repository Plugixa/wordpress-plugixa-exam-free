<?php
/**
 * Public certificate verification.
 *
 * Override in a theme at `yourtheme/plugixa-exam/verify.php`.
 *
 * Reached by scanning the QR on a printed certificate, so it is loaded by somebody with no
 * account, often on a phone, who wants one answer: is this real. That answer is the first
 * thing on the page, in words, before any of the detail.
 *
 * A LINK THAT MATCHES NOTHING GETS THE SAME PAGE WHATEVER THE REASON. See
 * `Frontend\VerifyBridge` — distinguishing "no such certificate" from "revoked" would turn
 * this into an oracle for guessing tokens.
 *
 * @package PlugixaExam
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

$pxe_verify = \PlugixaExam\Core\Plugin::instance()->container()
	->get( \PlugixaExam\Modules\Certificates\Frontend\VerifyBridge::class )
	->verdict();

$pxe_title    = __( 'Certificate check', 'plugixa-exam' );
$pxe_modifier = 'pxe--verify';

require plugixa_exam_get_template( 'parts/header.php' );
?>

<?php if ( ! $pxe_verify['found'] ) : ?>

	<div class="pxe-notice pxe-notice--error">
		<p class="pxe-notice__title"><?php esc_html_e( 'No certificate matches this link.', 'plugixa-exam' ); ?></p>
		<p class="pxe-notice__detail">
			<?php esc_html_e( 'Check the address was copied in full. If you scanned a code from a printed certificate, the certificate may have been withdrawn.', 'plugixa-exam' ); ?>
		</p>
	</div>

<?php else : ?>

	<?php
	$pxe_status = (string) $pxe_verify['status'];

	$pxe_headline = array(
		'valid'   => __( 'This certificate is genuine.', 'plugixa-exam' ),
		'revoked' => __( 'This certificate has been withdrawn.', 'plugixa-exam' ),
		'expired' => __( 'This certificate has expired.', 'plugixa-exam' ),
	);
	?>

	<div class="pxe-outcome <?php echo esc_attr( 'valid' === $pxe_status ? 'pxe-outcome--pass' : 'pxe-outcome--fail' ); ?>">
		<p class="pxe-outcome__verdict">
			<?php echo esc_html( $pxe_headline[ $pxe_status ] ?? $pxe_headline['revoked'] ); ?>
		</p>

		<?php if ( 'expired' === $pxe_status && '' !== $pxe_verify['expires'] ) : ?>
			<p class="pxe-outcome__pending">
				<?php
				printf(
					/* translators: %s: the date the certificate expired. */
					esc_html__( 'It was valid until %s. The award itself still stands.', 'plugixa-exam' ),
					esc_html( $pxe_verify['expires'] )
				);
				?>
			</p>
		<?php endif; ?>
	</div>

	<ul class="pxe-facts pxe-facts--large">
		<?php // Blank once the holder's personal data has been erased: no row, not an empty one. ?>
		<?php if ( '' !== $pxe_verify['recipient'] ) : ?>
			<li class="pxe-facts__item">
				<span class="pxe-facts__label"><?php esc_html_e( 'Awarded to', 'plugixa-exam' ); ?></span>
				<span class="pxe-facts__value"><?php echo esc_html( $pxe_verify['recipient'] ); ?></span>
			</li>
		<?php endif; ?>

		<?php if ( '' !== $pxe_verify['exam'] ) : ?>
			<li class="pxe-facts__item">
				<span class="pxe-facts__label"><?php esc_html_e( 'For', 'plugixa-exam' ); ?></span>
				<span class="pxe-facts__value"><?php echo esc_html( $pxe_verify['exam'] ); ?></span>
			</li>
		<?php endif; ?>

		<li class="pxe-facts__item">
			<span class="pxe-facts__label"><?php esc_html_e( 'Certificate number', 'plugixa-exam' ); ?></span>
			<span class="pxe-facts__value"><?php echo esc_html( $pxe_verify['number'] ); ?></span>
		</li>

		<?php if ( '' !== $pxe_verify['issued'] ) : ?>
			<li class="pxe-facts__item">
				<span class="pxe-facts__label"><?php esc_html_e( 'Issued', 'plugixa-exam' ); ?></span>
				<span class="pxe-facts__value"><?php echo esc_html( $pxe_verify['issued'] ); ?></span>
			</li>
		<?php endif; ?>

		<?php if ( '' !== $pxe_verify['expires'] && 'expired' !== $pxe_status ) : ?>
			<li class="pxe-facts__item">
				<span class="pxe-facts__label"><?php esc_html_e( 'Valid until', 'plugixa-exam' ); ?></span>
				<span class="pxe-facts__value"><?php echo esc_html( $pxe_verify['expires'] ); ?></span>
			</li>
		<?php endif; ?>
	</ul>

	<?php
	/*
	 * NO SCORE ON THIS PAGE, DELIBERATELY. See `VerifyBridge` — the question a stranger
	 * holding a printed certificate is entitled to have answered is "is this genuine", not
	 * "how well did they do".
	 */
	?>

<?php endif; ?>

<?php
require plugixa_exam_get_template( 'parts/footer.php' );
