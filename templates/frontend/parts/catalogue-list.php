<?php
/**
 * A list of exams, with its empty state.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/catalogue-list.php`.
 *
 * Shared by the `/exams/` page and by `[plugixa_exam_catalogue]`, so that a catalogue
 * embedded in a page and the catalogue at its own URL are the same thing rather than two
 * implementations that agree today.
 *
 * @package PlugixaExam
 *
 * @var array<int, array<string, mixed>>  $pxe_exams  Exams to list.
 * @var \PlugixaExam\Core\Frontend\Router $pxe_router Link builder.
 * @var string                            $pxe_empty  Text shown when there are none.
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

$pxe_empty = $pxe_empty ?? __( 'There are no exams available at the moment.', 'plugixa-exam' );
?>

<?php if ( array() === $pxe_exams ) : ?>

	<p class="pxe__empty"><?php echo esc_html( $pxe_empty ); ?></p>

<?php else : ?>

	<ul class="pxe__list pxe__list--cards">
		<?php foreach ( $pxe_exams as $pxe_exam ) : ?>
			<?php require plugixa_exam_get_template( 'parts/exam-card.php' ); ?>
		<?php endforeach; ?>
	</ul>

<?php endif; ?>
