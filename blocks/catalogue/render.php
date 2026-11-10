<?php
/**
 * The exam catalogue block, front end.
 *
 * Included by the render callback `Core\Frontend\Blocks` registers, and it RETURNS its markup
 * rather than printing it. The same callback serves the editor's `ServerSideRender` preview
 * through `/wp/v2/block-renderer/`. One implementation, two surfaces.
 *
 * `$attributes`, `$content` and `$block` are that callback's parameters.
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

$pxe_container = \PlugixaExam\Core\Plugin::instance()->container();
$pxe_data      = $pxe_container->get( \PlugixaExam\Core\Frontend\ViewData::class );

$pxe_exams = $pxe_data->catalogue();
$pxe_limit = (int) ( $attributes['limit'] ?? 0 );

if ( $pxe_limit > 0 ) {
	$pxe_exams = array_slice( $pxe_exams, 0, $pxe_limit );
}

$pxe_markup = \PlugixaExam\Core\Frontend\TemplateLoader::render(
	'parts/catalogue-list.php',
	array(
		'pxe_exams'  => $pxe_exams,
		'pxe_router' => $pxe_data->router(),
		'pxe_empty'  => __( 'There are no exams available at the moment.', 'plugixa-exam' ),
	)
);

/*
 * `get_block_wrapper_attributes()` carries the alignment, spacing and any custom class the
 * author set. Emitting a bare div instead would silently drop every one of those — the
 * block would declare `align` support in `block.json` and then ignore it, which looks like
 * the editor being broken rather than the block.
 *
 * The `.pxe` class is the plugin's style scope: every `--pxe-*` custom property is defined
 * on it, so a card outside it renders unstyled.
 */
/*
 * ⚠️ PRIVATE FOR A SIGNED-IN READER, PUBLIC FOR EVERYONE ELSE — see the same decision in
 * `Core\Frontend\Shortcodes::catalogue()`. The badges are that person's own history.
 * `PrivateResponse::mark()` sends the headers and the constant page caches read;
 * `data-pxe-private` only labels the markup.
 */
$pxe_private = is_user_logged_in();

if ( $pxe_private ) {
	\PlugixaExam\Core\Frontend\PrivateResponse::mark();
}

$pxe_wrapper = get_block_wrapper_attributes(
	array_merge(
		array( 'class' => 'pxe pxe--embed' ),
		$pxe_private ? array( 'data-pxe-private' => '1' ) : array()
	)
);

return '<div ' . $pxe_wrapper . '>' . $pxe_markup . '</div>';
