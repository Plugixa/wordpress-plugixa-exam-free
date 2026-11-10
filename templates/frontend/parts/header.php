<?php
/**
 * Shared opening markup for every exam page.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/header.php`.
 *
 * `.pxe` is the scope root. Every rule in the plugin's stylesheet is nested under it and
 * every value comes from a `--pxe-*` custom property, so nothing here reaches out into the
 * theme and the theme's own typography still applies to anything the plugin does not style.
 *
 * @package PlugixaExam
 *
 * @var string $pxe_title    Page title.
 * @var string $pxe_modifier Extra class for the scope root, e.g. `pxe--runner`.
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

$pxe_title    = $pxe_title ?? '';
$pxe_modifier = $pxe_modifier ?? '';

/*
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE SITE KEEPS ITS HEADER, ITS LOGO, ITS MENU AND ITS FOOTER — ON EVERY VIEW, NOW.
 *
 * ⚠️ THIS FILE HAS BEEN WRONG TWICE, IN OPPOSITE DIRECTIONS, AND BOTH TIMES IT LOOKED FINE.
 *
 * First every routed view emitted its own document and the theme's chrome was gone
 * entirely. On screen that is a handsome application; as a product it is a plugin that
 * hides its customer, so it was reverted to `get_header()`.
 *
 * Then `get_header()` turned out to mean nothing on a BLOCK theme — no `header.php` exists,
 * so WordPress falls back to `wp-includes/theme-compat/header.php` and prints a site title
 * and an `<hr />` with no navigation at all. The plugin's own sidebar had been covering
 * that for the entire life of the feature, and deleting the sidebar exposed it. Reported
 * from a real site: "when I clicked to view, the menu disappeared."
 *
 * {@see \PlugixaExam\Core\Frontend\Chrome} now answers this once, for both kinds of theme,
 * and the decision is no longer restated here — two reads of one answer is exactly how they
 * drift apart.
 *
 * ⚠️ THE RUNNER IS NO LONGER AN EXCEPTION, AND THAT IS A DELIBERATE REVERSAL. It used to
 * drop all chrome, on the argument that a menu during a timed, autosaving, expiring attempt
 * is an invitation to navigate away from it and "I lost my exam" is a support ticket the
 * plugin caused. The site owner weighed that and chose the site's navigation everywhere.
 * The argument was not wrong, so it is answered rather than dropped: `useLeaveGuard.ts`
 * asks before a live attempt is abandoned. Autosave limits what is lost; it does nothing
 * about the clock, which is the real hazard.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
$pxe_is_runner = 'pxe--runner' === $pxe_modifier;

/**
 * Whether the exam runner shows the site's header and footer.
 *
 * Yes by default, for the reasons above. An exam meant to feel like a test centre can say no.
 *
 * @param bool                 $show Whether to show them.
 * @param array<string, mixed> $exam The exam row.
 */
if ( $pxe_is_runner && ! apply_filters( 'plugixa_exam_runner_site_chrome', true, (array) ( $pxe_exam ?? array() ) ) ) {
	\PlugixaExam\Core\Frontend\Chrome::without_site_chrome();
}

\PlugixaExam\Core\Frontend\Chrome::open( $pxe_is_runner ? 'plugixa-exam-runner' : '', $pxe_title );

/*
 * WHICH WAY ROUND THE SURFACE IS, DECIDED BEFORE A BYTE IS SENT.
 *
 * Printed here rather than written by the bundle, because the markup below is what the
 * reader looks at until React boots — a mode applied from a `useEffect` styles the whole of
 * that wrong and then flips. `Core\Frontend\Mode` is the only place the question is asked;
 * `styles/frontend.css` and `theme/tokens.ts` both read this attribute.
 */
$pxe_mode = \PlugixaExam\Core\Frontend\Mode::of(
	\PlugixaExam\Core\Plugin::instance()->container()
		->get( \PlugixaExam\Core\Settings::class )->str( 'color_bg', '' )
);
?>
<div
	class="pxe <?php echo esc_attr( $pxe_modifier ); ?>"
	data-pxe-mode="<?php echo esc_attr( $pxe_mode ); ?>"
>
	<div class="pxe__shell">
		<?php if ( '' !== $pxe_title ) : ?>
			<h1 class="pxe__title"><?php echo esc_html( $pxe_title ); ?></h1>
		<?php endif; ?>
