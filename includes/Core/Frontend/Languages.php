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
 * How this site says "which language" in a URL.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THIS IS FREE, AND IT IS ABOUT ROUTING RATHER THAN ABOUT TRANSLATION.
 *
 * The premium Multilingual module answers a different question — which language should the
 * CONTENT be in — and it is the only thing that reads a translation. This class answers a
 * question every build has to answer: on a site where every URL is `/fr/…`, does an exam page
 * exist at that address at all.
 *
 * It did not. The plugin's six routes are `^`-anchored, so on a Polylang site in directory
 * mode `/fr/exams/algebra/` was a 404 while `/exams/algebra/` worked — on the free build as
 * much as the premium one. A free site has nothing to translate, but it still has a language,
 * and 404ing half its own URL space is not a premium feature to withhold.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ────────────────────────────────────────────────────────────────────────────────────────
 * IT NAMES POLYLANG, AND IT IS THE ONLY FILE IN THE FREE BUILD THAT DOES.
 *
 * That was previously true of the premium `Locales` alone, and the rule is worth restating
 * rather than quietly dropping: everything Polylang-shaped lives in exactly two files, one per
 * build, and neither of them is reached except through the filters below. A WPML adapter is a
 * second branch here and nothing else changes.
 *
 * Polylang is a free plugin, so naming it in the free build is interoperability, not premium
 * code — the ZIP carries no premium strings and no premium behaviour because of it.
 * ────────────────────────────────────────────────────────────────────────────────────────
 *
 * ⚠️ THE DEFAULT LANGUAGE IS NEVER DECORATED. With `hide_default` on there is no `/en/` URL,
 * so producing one would give every page a second address for Polylang's canonical redirect
 * to argue with. `add_language_to_link()` knows that too, but the check is here as well so the
 * `lang` query argument obeys the same rule.
 */
final class Languages {

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_url_languages', array( $this, 'slugs' ) );
		add_filter( 'plugixa_exam_frontend_url', array( $this, 'localise' ), 10, 3 );

		// Polylang's own switcher, corrected for our routes. See {@see switcher_link()}.
		add_filter( 'pll_the_language_link', array( $this, 'switcher_link' ), 10, 2 );
	}

	/**
	 * Whether a language plugin is deciding URLs on this site.
	 */
	public function active(): bool {
		return function_exists( 'pll_current_language' ) && function_exists( 'pll_default_language' );
	}

	/**
	 * Whether the language is expressed as a path segment rather than a query argument.
	 *
	 * Polylang offers four URL modes and only `force_lang = 1` puts a segment in the path. In
	 * the other three a `/fr/` rule would answer a URL that Polylang then reads as the DEFAULT
	 * language — a French-looking address serving English, which is worse than the 404 it
	 * replaced.
	 */
	public function directory_mode(): bool {
		return 1 === (int) ( $this->options()['force_lang'] ?? 0 );
	}

	/**
	 * The language segments the plugin's routes should also answer to.
	 *
	 * @param array<int, string> $languages Accumulated slugs.
	 *
	 * @return array<int, string>
	 */
	public function slugs( array $languages ): array {
		if ( ! $this->directory_mode() || ! function_exists( 'pll_languages_list' ) ) {
			return $languages;
		}

		$slugs = pll_languages_list( array( 'fields' => 'slug' ) );

		if ( ! is_array( $slugs ) ) {
			return $languages;
		}

		return array_merge( $languages, array_diff( array_map( 'strval', $slugs ), array( $this->default_slug() ) ) );
	}

	/**
	 * Add the reader's language to a URL the way this site expresses language in URLs.
	 *
	 * ────────────────────────────────────────────────────────────────────────────────────
	 * WORDPRESS WILL NOT DO THIS FOR US, WHICH IS NOT OBVIOUS.
	 *
	 * Polylang filters `home_url()`, so it looks as though a plugin's links would gain the
	 * prefix for free. They do not: the filter is whitelist-gated by a `debug_backtrace()`
	 * naming the theme root, `wp_nav_menu`, `login_footer`, `get_custom_logo` and two blocks.
	 * A plugin directory is on none of those lists. Measured, not assumed — and it is why
	 * nothing was ever broken and everything was merely in the wrong language.
	 * ────────────────────────────────────────────────────────────────────────────────────
	 *
	 * @param string $url     The URL as built.
	 * @param string $path    The path it was built from.
	 * @param string $context Which link this is; `form` posts to `admin-post.php`.
	 */
	public function localise( string $url, string $path, string $context ): string {
		unset( $path );

		$slug = $this->current_slug();

		if ( '' === $slug ) {
			return $url;
		}

		/*
		 * `form` posts to `admin-post.php`, which is not one of our routes and never will be,
		 * so it cannot carry a path segment — the language travels in its query string
		 * instead, and `FormHandler`'s redirect picks it up from there. See
		 * `Router::form_url()`.
		 */
		if ( $this->directory_mode() && 'form' !== $context ) {
			$links = $this->links_model();

			if ( null !== $links ) {
				return (string) $links->add_language_to_link( $url, $slug );
			}
		}

		/*
		 * The query argument is not a fallback dialect we invented: in Polylang's
		 * "language set from content" mode it IS Polylang's own switch, so a link carrying it
		 * keeps the reader in their language exactly as a prefix would elsewhere.
		 */
		return (string) add_query_arg( 'lang', $slug, $url );
	}

	/**
	 * Correct Polylang's own language switcher when it is drawn on one of our pages.
	 *
	 * ────────────────────────────────────────────────────────────────────────────────────
	 * IT HAS NO POST TO TRANSLATE, SO IT OFFERS THE HOME PAGE.
	 *
	 * The switcher builds its link from whatever post WordPress thinks is being viewed. On a
	 * rewritten route there is none, so it falls back to the language's home page and silently
	 * drops the reader out of the exam they were reading. What they almost certainly want is
	 * THIS page in the other language.
	 *
	 * Built by stripping any language already in the current URL and adding the requested one,
	 * rather than by rebuilding the path: the route may carry a token or an attempt id that
	 * this filter has no business reconstructing.
	 * ────────────────────────────────────────────────────────────────────────────────────
	 *
	 * @param mixed $url  The URL Polylang built.
	 * @param mixed $slug The language it is for.
	 */
	public function switcher_link( $url, $slug ): string {
		$url = (string) $url;

		if ( '' === (string) get_query_var( Router::VIEW ) ) {
			return $url;
		}

		$here  = home_url( add_query_arg( array() ) );
		$links = $this->links_model();

		if ( null === $links || ! $this->directory_mode() ) {
			return (string) add_query_arg( 'lang', (string) $slug, $here );
		}

		$bare = (string) $links->remove_language_from_link( $here );

		return (string) $links->add_language_to_link( $bare, (string) $slug );
	}

	/* ------------------------------------------------------------------ internals */

	/**
	 * The slug to decorate a URL with, or '' when there is nothing to say.
	 *
	 * The default language is never decorated — see the class docblock.
	 */
	private function current_slug(): string {
		if ( ! $this->active() ) {
			return '';
		}

		$slug = pll_current_language( 'slug' );
		$slug = is_string( $slug ) ? $slug : '';

		return '' !== $slug && $slug !== $this->default_slug() ? $slug : '';
	}

	private function default_slug(): string {
		if ( ! $this->active() || empty( $this->options()['hide_default'] ) ) {
			// Without `hide_default` every language has a segment, including the default, so
			// none of them is the one to leave alone.
			return '';
		}

		$slug = pll_default_language( 'slug' );

		return is_string( $slug ) ? $slug : '';
	}

	/**
	 * Polylang's own URL builder, which knows about `hide_default`, the `language/` base and
	 * the site root. Three plugins agreeing on that string by coincidence is not something to
	 * arrange twice.
	 */
	private function links_model(): ?object {
		if ( ! function_exists( 'PLL' ) ) {
			return null;
		}

		$polylang = PLL();
		$links    = is_object( $polylang ) ? ( $polylang->links_model ?? null ) : null;

		return is_object( $links ) && method_exists( $links, 'add_language_to_link' ) ? $links : null;
	}

	/**
	 * Polylang's settings, or an empty array when it is not running.
	 *
	 * @return array<string, mixed>
	 */
	private function options(): array {
		if ( ! $this->active() ) {
			return array();
		}

		$options = get_option( 'polylang' );

		return is_array( $options ) ? $options : array();
	}
}
