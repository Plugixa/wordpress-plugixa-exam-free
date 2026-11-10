<?php
/**
 * Exams a learner has been assigned, split by whether they can sit them now.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/my-exams.php`.
 *
 * PRIVATE OUTPUT — never cache this. See `parts/my-results.php`.
 *
 * THREE GROUPS, AND THE EMPTY ONES ARE OMITTED RATHER THAN SHOWN EMPTY. A learner with two
 * exams to sit does not need to be told they have no closed ones; the headings only earn
 * their place when there is something under them.
 *
 * Assignments come from access rules, which belong to the Cohorts module. With that module
 * switched off nothing answers and this renders its empty state, which is the honest
 * reading: no rules exist, so nothing has been assigned to anybody.
 *
 * @package PlugixaExam
 *
 * @var array{available: array<int, array<string, mixed>>, upcoming: array<int, array<string, mixed>>, closed: array<int, array<string, mixed>>} $pxe_groups
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

/*
 * ⚠️ "COMPLETED" IS NEW, AND IT SITS BEFORE "CLOSED" ON PURPOSE.
 *
 * These groups were purely about the date window, so an exam somebody had already passed
 * stayed under "Ready to sit" with a live Start link beside it. Only a PASS moves here — a
 * failed attempt with tries left really is ready to sit, and hiding it under "Completed"
 * would bury the retake. See `Portal::assignments()`, which decides the bucket.
 */
$pxe_headings = array(
	'available' => __( 'Ready to sit', 'plugixa-exam' ),
	'upcoming'  => __( 'Opening later', 'plugixa-exam' ),
	'completed' => __( 'Completed', 'plugixa-exam' ),
	'closed'    => __( 'Closed', 'plugixa-exam' ),
);

$pxe_total = 0;

foreach ( array_keys( $pxe_headings ) as $pxe_key ) {
	$pxe_groups[ $pxe_key ] = (array) ( $pxe_groups[ $pxe_key ] ?? array() );
	$pxe_total             += count( $pxe_groups[ $pxe_key ] );
}
?>

<div class="pxe__my-exams">

	<?php if ( 0 === $pxe_total ) : ?>

		<p class="pxe__empty"><?php esc_html_e( 'Nothing has been assigned to you yet.', 'plugixa-exam' ); ?></p>

	<?php else : ?>

		<?php foreach ( $pxe_headings as $pxe_key => $pxe_heading ) : ?>
			<?php if ( array() !== $pxe_groups[ $pxe_key ] ) : ?>

				<h3 class="pxe__my-exams-heading"><?php echo esc_html( $pxe_heading ); ?></h3>

				<ul class="pxe__list">
					<?php foreach ( $pxe_groups[ $pxe_key ] as $pxe_item ) : ?>
						<li class="pxe__my-exams-row">
							<?php
							/*
							 * A closed exam is NOT a link. The landing page would refuse it
							 * anyway, and a link that only ever leads to a refusal is worse
							 * than plain text — it invites the click that produces the
							 * refusal.
							 */
							?>
							<?php if ( 'closed' === $pxe_key ) : ?>
								<span class="pxe__my-exams-title"><?php echo esc_html( (string) $pxe_item['title'] ); ?></span>
							<?php else : ?>
								<a class="pxe__my-exams-title" href="<?php echo esc_url( (string) $pxe_item['url'] ); ?>">
									<?php echo esc_html( (string) $pxe_item['title'] ); ?>
								</a>
							<?php endif; ?>

							<?php
							/*
							 * The badge follows the title on every row, including a closed
							 * one: "you passed this before it shut" is the single most
							 * useful thing a closed row can say, and it is the row most
							 * likely to prompt "did I do that one?".
							 */
							$pxe_outcome = (array) ( $pxe_item['outcome'] ?? array() );
							require plugixa_exam_get_template( 'parts/outcome-badge.php' );
							?>

							<?php if ( 'upcoming' === $pxe_key && ! empty( $pxe_item['from'] ) ) : ?>
								<span class="pxe__my-exams-when">
									<?php
									printf(
										/* translators: %s: date and time the exam opens. */
										esc_html__( 'Opens %s', 'plugixa-exam' ),
										esc_html(
											wp_date(
												get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
												plugixa_exam_timestamp( $pxe_item['from'] )
											)
										)
									);
									?>
								</span>
							<?php endif; ?>

							<?php if ( 'closed' !== $pxe_key && ! empty( $pxe_item['due_at'] ) ) : ?>
								<span class="pxe__my-exams-due">
									<?php
									printf(
										/* translators: %s: date the exam should be finished by. */
										esc_html__( 'Due %s', 'plugixa-exam' ),
										esc_html(
											wp_date(
												(string) get_option( 'date_format' ),
												plugixa_exam_timestamp( $pxe_item['due_at'] )
											)
										)
									);
									?>
								</span>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>

			<?php endif; ?>
		<?php endforeach; ?>

	<?php endif; ?>

</div>
