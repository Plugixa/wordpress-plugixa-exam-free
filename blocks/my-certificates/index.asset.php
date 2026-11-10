<?php
/**
 * Script dependencies and version for this block's editor script.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THESE DEPENDENCIES WERE UNDECLARED, AND THE BLOCK WORKED BY COINCIDENCE.
 *
 * `index.js` reads `window.wp.blocks`, `window.wp.element` and the rest straight off the
 * global object. Nothing said the block needed them; it worked because the block editor
 * happens to load them before any block script runs. That is a coincidence of load order,
 * not a contract, and it is the sort of thing that breaks on somebody else's site.
 *
 * WordPress looks for `{script}.asset.php` beside the file named in `block.json`, so this
 * is the supported way to declare dependencies for a block that is NOT built by
 * `@wordpress/scripts`. See `blocks/catalogue/index.js` for why there is no build step.
 *
 * DECLARING `wp-i18n` ALSO SWITCHES THE TRANSLATIONS ON, and that is the larger fix.
 * `register_block_script_handle()` calls `wp_set_script_translations()` for a block whose
 * `block.json` names a `textdomain` — but ONLY when `wp-i18n` is among these dependencies.
 * With no asset file there was no dependency, so core did nothing, and `wp.i18n.__()`
 * returned its input verbatim in every language while translators went on translating
 * those strings into the `.pot`.
 *
 * MEASURED, because two mechanisms now overlap and it matters which is load-bearing:
 * removing this file alone still translates (the explicit call in `Core\Frontend\Blocks`
 * covers it), removing that call alone still translates (this file makes core do it), and
 * removing BOTH leaves the handle with no dependencies and no catalogue — which is exactly
 * what shipped. They are kept together on purpose; see `Blocks::register()`.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * @package PlugixaExam
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array(
		'wp-block-editor',
		'wp-blocks',
		'wp-components',
		'wp-element',
		'wp-i18n',
	),
	'version'      => defined( 'PLUGIXA_EXAM_VERSION' ) ? PLUGIXA_EXAM_VERSION : '1.0.0',
);
