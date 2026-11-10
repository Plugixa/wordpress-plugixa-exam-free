<?php
/**
 * The React mount point every view shares — routed or embedded.
 *
 * Override in a theme at `yourtheme/plugixa-exam/parts/view-open.php`.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * FOUND BY ATTRIBUTE, NUMBERED, AND NAMING ITS OWN STATE. IT USED TO BE AN ID.
 *
 * This printed `id="pxe-view"` and `parts/view-close.php` printed `id="pxe-view-state"`,
 * which held perfectly while a rewrite rule owned the page and there was exactly one of
 * each. A site owner putting two shortcodes on a page of their own — a dashboard with the
 * catalogue above their results — got a second mount handed the FIRST one's state, silently:
 * `getElementById` returns the first match in the document and reports nothing wrong.
 *
 * So the mount carries `data-pxe-view` and names its state tag in `data-pxe-state`.
 * {@see \PlugixaExam\Core\Frontend\ViewRenderer} owns the numbering, because the two halves
 * of one render have to agree on it and neither should be able to guess.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ `data-rest-url` AND `data-nonce` ARE ON EVERY MOUNT, NOT ONLY THE ONES THAT FETCH.
 * With client-side routing, the page a reader LANDS on is not the only page they will see:
 * the catalogue needs the credentials to fetch `/my-results/` when they click it. A view
 * that carried them only when it happened to need them for itself would work perfectly
 * until somebody navigated.
 *
 * The REST URL is read from the page rather than assumed, because a site on plain
 * permalinks serves REST from `?rest_route=` and a hard-coded `/wp-json/` 404s there.
 *
 * THE NONCE IS REQUIRED FOR A SIGNED-IN READER AND HARMLESS FOR A GUEST. WordPress only
 * resolves the current user for a cookie-authenticated REST request carrying a valid
 * `X-WP-Nonce`; without it a learner's own dashboard comes back 401 while the browser is
 * plainly signed in. A guest has no session for it to authenticate, so sending it either
 * way keeps one code path.
 *
 * @package PlugixaExam
 *
 * @var string $pxe_view     The route name — `catalogue`, `exam`, `portal`, `results`, …
 * @var int    $pxe_instance This mount's number within the request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * Template-scope render variables, not globals — see the note in `parts/header.php`.
 */

$pxe_view = $pxe_view ?? '';

/*
 * Claimed here when nothing passed one, which is what a routed template that has not been
 * updated — or a theme's copy of it — looks like. `require`d into the template's own scope,
 * so `parts/view-close.php` a few lines later reads the same number.
 */
$pxe_instance = isset( $pxe_instance )
	? (int) $pxe_instance
	: \PlugixaExam\Core\Frontend\ViewRenderer::next();
?>
<div
	class="pxe-view"
	data-pxe-view="<?php echo esc_attr( $pxe_view ); ?>"
	data-pxe-state="<?php echo esc_attr( \PlugixaExam\Core\Frontend\ViewRenderer::state_id( $pxe_instance ) ); ?>"
	data-rest-url="<?php echo esc_url( rest_url( 'plugixa-exam/v1/' ) ); ?>"
	data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
	<?php /* Where a replacement nonce comes from once this page has outlived the one above — see the same attribute in `take.php`. */ ?>
	data-nonce-url="<?php echo esc_url( admin_url( 'admin-ajax.php?action=rest-nonce' ) ); ?>"
>
