<?php
/**
 * One exam, as a card.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/exam-card.php`.
 *
 * Renders an `<li>` because both callers put it in a `.pxe__list`: the catalogue lists
 * many, and `[plugixa_exam id="N"]` lists exactly one. A single-item list is valid, reads
 * correctly to a screen reader, and means there is one piece of card markup rather than two
 * that drift apart.
 *
 * @package PlugixaExam
 *
 * @var array<string, mixed>                     $pxe_exam   The exam row.
 * @var \PlugixaExam\Core\Frontend\Router         $pxe_router Link builder.
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

$pxe_opens   = (string) ( $pxe_exam['open_from'] ?? '' );
$pxe_future  = '' !== $pxe_opens && $pxe_opens > current_time( 'mysql', true );
$pxe_outcome = (array) ( $pxe_exam['outcome'] ?? array() );
?>
<li class="pxe-card">
	<h2 class="pxe-card__title">
		<a href="<?php echo esc_url( $pxe_router->exam_url( (string) $pxe_exam['slug'] ) ); ?>">
			<?php echo esc_html( (string) $pxe_exam['title'] ); ?>
		</a>
	</h2>

	<?php
	/*
	 * ⚠️ ABOVE THE FACTS, BELOW THE TITLE. A card that says "45 minutes, pass mark 60%" and
	 * nothing else reads identically whether you have never opened the exam or passed it
	 * last week — which is what was reported. `ViewData` attaches `outcome` only for a
	 * signed-in reader, so this renders nothing at all for a visitor and the public
	 * catalogue markup is unchanged.
	 */
	require plugixa_exam_get_template( 'parts/outcome-badge.php' );
	?>

	<?php if ( '' !== (string) ( $pxe_exam['description'] ?? '' ) ) : ?>
		<p class="pxe-card__summary"><?php echo esc_html( (string) $pxe_exam['description'] ); ?></p>
	<?php endif; ?>

	<ul class="pxe-facts">
		<?php if ( (int) $pxe_exam['time_limit'] > 0 ) : ?>
			<li class="pxe-facts__item">
				<?php
				printf(
					/* translators: %d: number of minutes. */
					esc_html( _n( '%d minute', '%d minutes', (int) $pxe_exam['time_limit'], 'plugixa-exam' ) ),
					(int) $pxe_exam['time_limit']
				);
				?>
			</li>
		<?php endif; ?>

		<li class="pxe-facts__item">
			<?php
			printf(
				/* translators: %s: pass mark as a percentage. */
				esc_html__( 'Pass mark %s%%', 'plugixa-exam' ),
				esc_html( (string) round( (float) $pxe_exam['pass_mark'] ) )
			);
			?>
		</li>

		<?php if ( 0 !== (int) $pxe_exam['max_attempts'] ) : ?>
			<li class="pxe-facts__item">
				<?php
				printf(
					/* translators: %d: number of attempts. */
					esc_html( _n( '%d attempt', '%d attempts', (int) $pxe_exam['max_attempts'], 'plugixa-exam' ) ),
					(int) $pxe_exam['max_attempts']
				);
				?>
			</li>
		<?php endif; ?>

		<?php if ( $pxe_future ) : ?>
			<li class="pxe-facts__item pxe-facts__item--muted">
				<?php
				printf(
					/* translators: %s: date and time the exam opens. */
					esc_html__( 'Opens %s', 'plugixa-exam' ),
					esc_html(
						wp_date(
							get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
							(int) strtotime( $pxe_opens . ' UTC' )
						)
					)
				);
				?>
			</li>
		<?php endif; ?>

		<?php
		/**
		 * Fires at the end of a card's facts, to add more of them.
		 *
		 * A listener prints `<li class="pxe-facts__item">` elements. The public catalogue is
		 * cached, so a fact must be the same for every visitor — a price, never "you own
		 * this".
		 *
		 * @param array<string, mixed> $exam The exam.
		 */
		do_action( 'plugixa_exam_card_facts', $pxe_exam );
		?>
	</ul>
</li>
