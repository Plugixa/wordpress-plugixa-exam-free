<?php
/**
 * The My results block, front end.
 *
 * PRIVATE OUTPUT. This is one person's data, so the shortcode method it delegates to sends
 * `nocache_headers()` and marks the wrapper `data-pxe-private`. It renders NOTHING for a
 * signed-out reader rather than a login prompt — a prompt is the thing a page cache would
 * happily store in place of somebody's results.
 *
 * Renders through the same shortcode method `[plugixa_exam_my_results]` uses, so a theme
 * override changes both.
 *
 * @package PlugixaExam
 *
 * @var array<string, mixed> $attributes Block attributes.
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

$pxe_markup = \PlugixaExam\Core\Plugin::instance()->container()
	->get( \PlugixaExam\Modules\Portal\Frontend\Shortcodes::class )
	->my_results( array(
			'limit' => (int) ( $attributes['limit'] ?? 10 ),
		) );

if ( '' === $pxe_markup ) {
	return '';
}

$pxe_wrapper = get_block_wrapper_attributes();

return '<div ' . $pxe_wrapper . '>' . $pxe_markup . '</div>';
