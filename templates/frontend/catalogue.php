<?php
/**
 * The exam catalogue.
 *
 * Override in a theme at `yourtheme/plugixa-exam/catalogue.php`.
 *
 * The list itself lives in `parts/catalogue-list.php` because `[plugixa_exam_catalogue]`
 * renders exactly the same thing inside somebody else's page. A theme wanting to restyle
 * the cards should override that part, not this file — this one is only the page around it.
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

$pxe_data   = \PlugixaExam\Core\Plugin::instance()->container()->get( \PlugixaExam\Core\Frontend\ViewData::class );
$pxe_router = $pxe_data->router();
$pxe_exams  = $pxe_data->catalogue();

$pxe_title    = __( 'Exams', 'plugixa-exam' );
$pxe_modifier = 'pxe--catalogue';

/*
 * ⚠️ THE CATALOGUE IS PER-VISITOR NOW, FOR ANYONE SIGNED IN.
 *
 * Each card carries that reader's own completion badge, so a full-page cache would serve
 * one learner's marks to the next visitor. Gated rather than unconditional because a
 * signed-out visitor gets no badges at all — `ExamBridge::with_outcomes()` attaches nothing
 * for user 0 — so the public catalogue is byte for byte what it was and stays cacheable,
 * which is the whole reason this page was worth keeping public.
 *
 * Called BEFORE `parts/header.php`, because headers go out with the first byte and
 * `nocache_headers()` after that does nothing at all — the same note `results.php` carries.
 */
if ( is_user_logged_in() ) {
	\PlugixaExam\Core\Frontend\PrivateResponse::mark();
}

require plugixa_exam_get_template( 'parts/header.php' );

require plugixa_exam_get_template( 'parts/catalogue-list.php' );

require plugixa_exam_get_template( 'parts/footer.php' );
