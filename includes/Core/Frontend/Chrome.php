<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site's own header and footer, on whichever kind of theme this site runs.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ `get_header()` MEANS NOTHING ON A BLOCK THEME, AND FAILS BY RENDERING SOMETHING.
 *
 * A block theme has no `header.php`. `get_header()` therefore finds no template, falls
 * through to `wp-includes/theme-compat/header.php`, and prints this:
 *
 *     <div id="page"><div id="header" role="banner"><div id="headerimg">
 *       <h1><a href="...">Site name</a></h1><div class="description"></div>
 *     </div></div><hr />
 *
 * A site title and a horizontal rule, from 2003. No navigation, no logo, no template
 * parts, and — because it is real markup rather than an error — nothing anywhere reports a
 * problem. Measured on Twenty Twenty-Five, same request, two URLs: a routed view rendered
 * 0 navigation items and 0 template parts, an ordinary page 84 and 2.
 *
 * This was not one screen. It was every routed URL the plugin has: the catalogue, the exam
 * landing, `/result/<id>/` as linked by every result email ever sent, `/verify/<token>/` as
 * printed on certificates and encoded in their QR codes, and the invigilator's `/monitor/`.
 * A mapped page only changes the links the plugin BUILDS — visiting a route directly still
 * renders the plugin's own template, so a configured site was not exempt.
 *
 * So the question is asked once, here:
 *
 *   - CLASSIC theme — `get_header()` / `get_footer()` do exactly what they always did.
 *   - BLOCK theme  — the plugin opens the document (as the runner has always done, so this
 *     is a proven path rather than a new one) and renders the theme's REAL `header` and
 *     `footer` template parts inside it.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ A THEME OVERRIDING `parts/header.php` INHERITS NONE OF THIS, exactly as documented
 * there. That is the cost of the override seam and is not worth closing.
 */
final class Chrome {

	/**
	 * What this view is called, for the `<title>`.
	 *
	 * Static because `wp_head()` runs INSIDE {@see self::open()} — the filter has to be able
	 * to answer from a hook, and the answer is only known once a template has said what it
	 * is rendering.
	 */
	private static string $title = '';

	/**
	 * The site footer, rendered early and held until {@see self::close()}.
	 *
	 * ⚠️ IT HAS TO BE RENDERED BEFORE `wp_head()`, WHICH IS WHY IT IS STORED RATHER THAN
	 * DRAWN WHEN IT IS NEEDED. See the note in {@see self::open()}.
	 */
	private static string $footer = '';

	/**
	 * Whether this page drops the site's header and footer. See {@see without_site_chrome()}.
	 */
	private static bool $bare = false;

	/**
	 * Whether the plugin, rather than the theme, writes the opening `<html>`.
	 *
	 * ⚠️ THIS IS A QUESTION ABOUT THE THEME, NOT ABOUT THE VIEW. It used to be
	 * `'take' === $view` — the runner alone stood outside the theme's document. The runner
	 * now takes the site's chrome like every other view, so what is left is the only reason
	 * the plugin ever needs to open a document of its own: `get_header()` cannot open one
	 * worth having on a block theme.
	 */
	public static function emits_document(): bool {
		return self::$bare || ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() );
	}

	/**
	 * Leave the site's header and footer off this page.
	 *
	 * Always through the plugin's own document, because on a classic theme `get_header()` is the
	 * only thing that opens one and it cannot be asked to leave the header out.
	 */
	public static function without_site_chrome(): void {
		self::$bare = true;
	}

	/**
	 * Open the document and render the site's header.
	 *
	 * @param string $body_class Extra classes for `<body>`, space separated.
	 * @param string $title      What this view is called.
	 */
	public static function open( string $body_class = '', string $title = '' ): void {
		/*
		 * ⚠️ BEFORE THE BRANCH, because `get_header()` calls `wp_head()` too. Registering
		 * the title filter on only the block path would have left every classic-theme site
		 * with the bug this fixes, and nothing on screen here would have shown it.
		 */
		self::$title = $title;
		add_filter( 'document_title_parts', array( self::class, 'document_title' ) );

		if ( ! self::emits_document() ) {
			get_header();

			return;
		}

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * ⚠️ BOTH PARTS ARE RENDERED HERE, BEFORE `wp_head()`, AND THE ORDER IS THE POINT.
		 *
		 * Rendering a block is what REGISTERS the CSS that block needs. Layout support —
		 * `is-layout-flex`, `justify-content: space-between`, the block gap — is generated
		 * per block into the style engine's store while `do_blocks()` runs, and that store
		 * is printed during `wp_head()`. Render the header afterwards and the markup is
		 * perfect and the rules that arrange it were never printed.
		 *
		 * Measured, and it is a good demonstration of why markup comparison is not enough:
		 * the routed page's header was byte-identical to an ordinary page's apart from
		 * `current-menu-item` — same wrapper, same `items-justified-right` — and rendered
		 * completely differently, with the navigation flush against the site title instead
		 * of ranged right. Nothing was missing; the stylesheet for it simply did not exist.
		 *
		 * `wp-includes/template-canvas.php` says this out loud, above its own first line:
		 * "This needs to run before <head> so that blocks can add scripts and styles in
		 * wp_head()." The footer is therefore rendered now and held, not drawn on demand.
		 * ════════════════════════════════════════════════════════════════════════════════
		 */
		$header       = self::$bare ? '' : self::part( 'header' );
		self::$footer = self::$bare ? '' : self::part( 'footer' );

		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo( 'charset' ); ?>">
			<?php
			/*
			 * ⚠️ THE VIEWPORT TAG IS CORE'S TO PRINT, AND PRINTING A SECOND ONE IS A BUG.
			 *
			 * `locate_block_template()` hooks `_block_template_viewport_meta_tag` onto
			 * `wp_head` at priority 0 whenever a block template resolves — and it resolves
			 * for our routed URLs too, because it runs BEFORE `template_include`, which is
			 * where this plugin swaps in its own template. Measured: a routed page already
			 * carries exactly one viewport tag with nothing here printing one.
			 *
			 * So this fills a gap rather than staking a claim, for the case where core did
			 * not hook it — a theme or plugin that unhooked it, or a resolution path that
			 * never ran.
			 *
			 * ⚠️ `false !==`, NOT `!`. `has_action()` returns the PRIORITY the callback is
			 * hooked at, and core hooks this one at priority ZERO — which is falsy, so `!
			 * has_action(...)` is TRUE for a hook that is present. Written the obvious way
			 * first and measured: two viewport tags on every routed page. The one guard in
			 * this class whose correct form is the less readable one.
			 */
			if ( false === has_action( 'wp_head', '_block_template_viewport_meta_tag' ) ) {
				echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
			}

			/*
			 * Every enqueued stylesheet and script, the theme's global styles (which is
			 * what makes the header part below look like the rest of the site), and the
			 * plugin's inline `--pxe-*` palette.
			 *
			 * ⚠️ SKIPPING THIS COSTS EVERYTHING. An earlier version of the runner opened
			 * its own document without `wp_head()` and rendered browser-default serif with
			 * no plugin CSS and no React bundle — and every gate stayed green, because
			 * nothing in PHP or TypeScript is wrong with a page that has no `<head>`.
			 */
			wp_head();
			?>
		</head>
		<body <?php body_class( trim( 'plugixa-exam-app ' . $body_class ) ); ?>>
		<?php
		wp_body_open();

		/*
		 * ⚠️ `.wp-site-blocks` IS LOAD-BEARING, NOT DECORATION. It is the wrapper core
		 * itself emits (`wp-includes/block-template.php`), and a block theme's generated
		 * global styles hang spacing off it — `:root :where(.wp-site-blocks) > *` sets the
		 * block gap between the header, the content and the footer. Without it the theme's
		 * header sits flush against the plugin's content.
		 */
		echo '<div class="wp-site-blocks">';

		/*
		 * THE THEME'S OWN HEADER, RENDERED BY `do_blocks()`, PRINTED AS CORE PRINTS IT.
		 *
		 * This is the one kind of value here that cannot go through an escaper or `wp_kses`:
		 * it is the site's navigation, search form, icons and the attributes its scripts
		 * read, and an allow-list would take the header apart. WordPress prints the same
		 * thing the same way — `wp-includes/template-canvas.php` echoes
		 * `get_the_block_template_html()` and `block_template_part()` echoes `do_blocks()` —
		 * and nothing in it comes from this plugin or from a request to it. It is held in a
		 * variable only because it has to be rendered before `wp_head()`; see `open()`.
		 */
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the active theme's header template part as rendered by do_blocks(), printed the way wp-includes/template-canvas.php prints a block template; nothing in it comes from this plugin or from the request.
		echo $header;
	}

	/** Render the site's footer and close the document. */
	public static function close(): void {
		if ( ! self::emits_document() ) {
			get_footer();

			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the active theme's footer template part as rendered by do_blocks() in open(), printed the way wp-includes/template-canvas.php prints a block template; nothing in it comes from this plugin or from the request.
		echo self::$footer;

		echo '</div>';

		/*
		 * The frontend bundles are enqueued in the footer; without this they never load and
		 * the no-JS fallback silently becomes the only interface there is.
		 */
		wp_footer();

		echo '</body></html>';
	}

	/**
	 * One of the theme's template parts, or something honest when it has not got one.
	 *
	 * ⚠️ `block_template_part()` RETURNS SILENTLY WHEN THE PART IS MISSING, which would
	 * leave a page with no header at all — the same emptiness this class exists to fix,
	 * reached a different way. Most block themes ship `parts/header.html`, but a theme is
	 * free to compose its header inline in each template instead, and then there is nothing
	 * to render. A linked site title is not the theme's header, but it is a way back to the
	 * site, which is the thing a learner actually needs.
	 */
	private static function part( string $name ): string {
		/*
		 * ⚠️ THE `core/template-part` BLOCK, NOT `block_template_part()`, AND THE DIFFERENCE
		 * IS VISIBLE ON SCREEN.
		 *
		 * `block_template_part()` does `echo do_blocks( $part->content )` — the part's blocks
		 * and nothing around them. What a block theme's own template renders is the BLOCK:
		 * `<!-- wp:template-part {"slug":"header"} /-->`, which wraps the same blocks in
		 * `<header class="wp-block-template-part">`. That wrapper is not decoration — it is
		 * the element the theme's layout and spacing rules select, and it is a landmark.
		 *
		 * Measured side by side: the helper rendered the navigation flush left in a row with
		 * the site title, while an ordinary page rendered the site title left and the menu
		 * right. Same links, same order, wrong layout — the kind of difference an HTML count
		 * says nothing about, which is why this was found by looking at the two pages.
		 *
		 * Rendering the block is also how the semantic tag gets chosen (`header` / `footer`
		 * come from the part's declared area) and how anything hooked into template parts
		 * keeps working. This is a copy of the theme's own line, not an approximation of it.
		 */
		$markup = do_blocks( '<!-- wp:template-part {"slug":"' . $name . '"} /-->' );

		if ( '' !== trim( $markup ) ) {
			return $markup;
		}

		if ( 'header' !== $name ) {
			return '';
		}

		return sprintf(
			'<header class="pxe-chrome-fallback"><a class="pxe-chrome-fallback__home" href="%1$s">%2$s</a></header>',
			esc_url( home_url( '/' ) ),
			esc_html( (string) get_bloginfo( 'name' ) )
		);
	}

	/**
	 * Give a routed view a real `<title>`.
	 *
	 * ⚠️ MEASURED, NOT ASSUMED: `/verify/<token>/` and `/exams/` both rendered
	 * `<title>Plugixa Exam</title>` — the site name and nothing else — while an ordinary
	 * page rendered `Browse exams – Plugixa Exam`. A routed URL has no queried object, so
	 * `wp_get_document_title()` has nothing to name it with and quietly names the site
	 * instead. Every routed view shared one title, which is a browser tab, a bookmark and a
	 * search result that cannot say what they are.
	 *
	 * ⚠️ IT OVERWRITES `title` RATHER THAN FILLING A BLANK ONE, and the difference matters.
	 * A routed URL is not a 404 and has no queried object, so `wp_get_document_title()` takes
	 * its "this is the home page" branch and sets the title to the SITE NAME — non-empty, so
	 * a fill-the-gap version of this method would have done nothing at all and looked right.
	 *
	 * @param array<string, string> $parts The title's parts.
	 *
	 * @return array<string, string>
	 */
	public static function document_title( array $parts ): array {
		$title = (string) apply_filters( 'plugixa_exam_document_title', self::$title );

		if ( '' === $title ) {
			return $parts;
		}

		$parts['title'] = $title;

		// The site name follows the view's own name; the tagline belongs to the front page.
		unset( $parts['tagline'] );

		if ( empty( $parts['site'] ) ) {
			$parts['site'] = (string) get_bloginfo( 'name', 'display' );
		}

		return $parts;
	}
}
