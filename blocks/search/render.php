<?php
/**
 * The exam search block, front end.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THIS BLOCK IS PREMIUM AND THE FEATURE IT WRAPS IS NOT.
 *
 * `[plugixa_exam_search]` is a FREE shortcode and stays one. This is the block packaging of
 * it, shipped here so the five site modules the Joomla original provides arrive together as
 * a set — not because searching is being withheld from anybody. A free site embeds the
 * shortcode and gets identical results from identical code: this file calls the free
 * `Core\Frontend\Shortcodes::search()` rather than reimplementing anything.
 *
 * Worth being explicit about, because a premium block whose free twin exists looks like
 * gating when it is packaging.
 * ════════════════════════════════════════════════════════════════════════════════════════
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
	->get( \PlugixaExam\Core\Frontend\Shortcodes::class )
	->search( array( 'per_page' => (int) ( $attributes['per_page'] ?? 10 ) ) );

if ( '' === $pxe_markup ) {
	return '';
}

$pxe_wrapper = get_block_wrapper_attributes();

return '<div ' . $pxe_wrapper . '>' . $pxe_markup . '</div>';
