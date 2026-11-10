<?php
/**
 * Closes the React mount and hands the view its data.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/view-close.php`.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE JSON IS NOT A SECOND SOURCE OF TRUTH. IT IS THE SAME ARRAY THE MARKUP CAME FROM.
 *
 * Each view builds `$pxe_state` once, renders its server copy FROM it, and passes it here.
 * A template that printed one thing while a REST route computed another is how a review
 * screen ends up disagreeing with itself about whether an answer was right.
 *
 * Inlined rather than fetched: the server has just loaded all of this to render the
 * fallback, so sending it twice costs a few kilobytes and removes an entire class of
 * loading state — server markup, then a spinner over it, then the real thing.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ THE ID IS NUMBERED, AND THE NUMBER MUST MATCH THE MOUNT THAT NAMED IT. See
 * `parts/view-open.php` for why two fixed ids stopped being enough the moment a page could
 * carry two views.
 *
 * @package PlugixaExam
 *
 * @var array<string, mixed> $pxe_state    Everything the view renders.
 * @var int                  $pxe_instance The mount number this closes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * Template-scope render variables, not globals — see the note in `parts/header.php`.
 */

$pxe_state = $pxe_state ?? array();

/*
 * The close always immediately follows its own open in document order, so the number most
 * recently claimed is this one's. That fallback is for a theme's copy of this file taken
 * before the numbering existed — without it, such a copy would emit a state tag that no
 * mount names and the view would silently never boot.
 */
$pxe_instance = isset( $pxe_instance )
	? (int) $pxe_instance
	: \PlugixaExam\Core\Frontend\ViewRenderer::current();
?>
</div>

<?php
/*
 * Printed through the enqueue API rather than written here as a tag — see the same call in
 * `take.php` for why. Typed `application/json`, so it is never executed; `JSON_HEX_TAG`
 * keeps a closing tag out of the data, and core's HTML API refuses to print one regardless.
 */
wp_print_inline_script_tag(
	(string) wp_json_encode( $pxe_state, JSON_HEX_TAG | JSON_HEX_AMP ),
	array(
		'type' => 'application/json',
		'id'   => \PlugixaExam\Core\Frontend\ViewRenderer::state_id( $pxe_instance ),
	)
);
