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
 * Pretty URLs for the exam pages.
 *
 * WHY REWRITES RATHER THAN PAGES THE ADMIN HAS TO CREATE.
 *
 * A certificate's QR code encodes `/verify/<token>/`, and that URL is printed on paper. It
 * cannot depend on somebody having created a page called "verify" and left it there. The
 * same goes for the link in a results email, which may be opened months later.
 *
 * Shortcodes exist too (see {@see Shortcodes}) for embedding a catalogue in an existing
 * page — but the canonical routes are these, and they work on a fresh install with nothing
 * configured.
 *
 * ONE QUERY VAR CARRIES THE VIEW, and the rest carry its argument. That keeps
 * `template_include` a single switch rather than a chain of `get_query_var` guesses.
 */
final class Router {

	public const VIEW  = 'plugixa_exam_view';
	public const SLUG  = 'plugixa_exam_slug';
	public const ID    = 'plugixa_exam_id';
	public const TOKEN = 'plugixa_exam_token';

	/**
	 * The exam a page-hosted landing view is about.
	 *
	 * A rewrite rule puts the slug in {@see self::SLUG}. On a page the SITE OWNER built
	 * there is no rewrite rule, so it travels in the query string of their page instead —
	 * the same shape `[plugixa_exam_search]` has always used for `pxe_s`.
	 */
	public const PARAM_EXAM = 'pxe_exam';

	/** The attempt a page-hosted result view is about. See {@see self::PARAM_EXAM}. */
	public const PARAM_RESULT = 'pxe_result';

	/**
	 * ⚠️ WHICH CONTEXTS A PAGE MAY ANSWER IS {@see Pages::VIEWS}, NOT A LIST HERE.
	 *
	 * A second list would let this file offer `exam` while the settings refuse to store it,
	 * and the site owner would have configured it exactly as instructed.
	 *
	 * `take` and `verify` are absent from that list and always will be, and neither is an
	 * oversight. The RUNNER is a live sitting: hosting it on a page the owner may rename,
	 * unpublish or fill with their own blocks puts an attempt that is running, autosaving
	 * and expiring at the mercy of an edit. (It is no longer chrome-less — the site's menu
	 * is on it now, guarded by `runner/useLeaveGuard.ts` — but it is still not a page.)
	 * VERIFICATION is printed on paper and encoded into a QR code; it cannot depend on
	 * somebody having created a page and left it there, which is why
	 * {@see self::verify_url()} does not even carry a language.
	 */

	/** The language set the stored rewrite rules were built for. See {@see flush_if_stale()}. */
	private const LANGUAGES_OPTION = 'plugixa_exam_url_languages';

	/**
	 * Bumped whenever {@see self::add_rules()} changes shape.
	 *
	 * ⚠️ THIS IS WHAT MAKES A NEW ROUTE ACTUALLY EXIST ON AN INSTALL THAT IS ALREADY RUNNING.
	 *
	 * `flush_if_stale()` signed only the LANGUAGE list, so adding a rewrite rule in a new
	 * version changed nothing it could see: `Activator::activate()` raises the flush flag and
	 * an UPDATE never calls it, so the new rules lay dormant behind a 404 until somebody
	 * happened to re-save permalinks. It would have worked perfectly on a development site,
	 * because that is the one place the plugin gets re-activated.
	 *
	 * Signing the rule set alongside the languages means one bump here flushes every site
	 * exactly once, and it keeps working for every route added after this one.
	 */
	private const RULES_VERSION = '2';

	/**
	 * The base segment for exam URLs.
	 *
	 * Filterable because `/exams/` is a plausible collision with an existing page on a site
	 * that already had one — and because a French or German site should be able to use its
	 * own word.
	 */
	public function base(): string {
		/**
		 * The first path segment of every exam URL.
		 *
		 * @param string $base Default `exams`.
		 */
		return (string) apply_filters( 'plugixa_exam_url_base', 'exams' );
	}

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_filter( 'template_include', array( $this, 'route' ) );

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * THE ADMIN BAR IS NO LONGER SUPPRESSED ANYWHERE, AND ITS ABSENCE IS THE RECORD.
		 *
		 * A `template_redirect` hook used to add `show_admin_bar => __return_false` for the
		 * runner, because the runner rendered as a standalone document precisely so that a
		 * candidate under a clock had nothing to navigate away to — and the admin bar is a
		 * menu with a "Log Out" in it.
		 *
		 * The site owner has since chosen the site's full navigation on every view, the
		 * runner included. Hiding WordPress's toolbar while showing the theme's menu would
		 * be an incoherent half of a decision that has been made, so it goes. What replaces
		 * the integrity argument is `runner/useLeaveGuard.ts`, which asks before a live attempt
		 * is abandoned by any route out — menu, toolbar, or the browser's own back button,
		 * which no amount of hiding ever covered.
		 *
		 * Worth keeping from what was removed, because it will be rediscovered otherwise:
		 * this decision belongs BEFORE `wp_head()`, since the bar's stylesheet is enqueued
		 * there and deciding later leaves the CSS loaded and a 32px gap above nothing. And
		 * `wp_loaded` is too early to read a view — the main query has not run, so
		 * `get_query_var()` is empty and the filter is silently never added.
		 * ════════════════════════════════════════════════════════════════════════════════
		 */

		// Stops WordPress 404ing a URL that matches one of our rules but no post.
		add_action( 'pre_get_posts', array( $this, 'suppress_404' ) );

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * AND AGAIN AFTER THE QUERY HAS RUN, BECAUSE `pre_get_posts` IS TOO EARLY.
		 *
		 * `WP_Query::get_posts()` sets `is_404` at the END of itself, when it found no posts
		 * — long after `pre_get_posts` said otherwise. Our routes match no post by design, so
		 * the early suppression only ever held because the main query happened to return the
		 * site's ordinary posts anyway.
		 *
		 * Measured the day the language-prefixed routes were added: `/exams/` returned three
		 * posts and did not 404, while `/fr/exams/` returned none — Polylang narrows the main
		 * query by its `language` taxonomy — and 404'd with the rule matched, the view query
		 * var set and everything else correct. The same thing would happen to a brand-new
		 * site with no posts at all, with no Polylang anywhere near it.
		 * ════════════════════════════════════════════════════════════════════════════════
		 */
		add_action( 'wp', array( $this, 'suppress_404_late' ) );
	}

	/**
	 * @return string[]
	 */
	public function register_query_vars( array $vars ): array {
		return array_merge(
			$vars,
			array( self::VIEW, self::SLUG, self::ID, self::TOKEN, self::PARAM_EXAM, self::PARAM_RESULT )
		);
	}

	public function register_rules(): void {
		$base = $this->base();

		$this->add_rules( $base, '' );

		/**
		 * Language segments the front-end routes should ALSO answer to.
		 *
		 * ════════════════════════════════════════════════════════════════════════════════
		 * WHY THE PREFIX IS SWALLOWED RATHER THAN CAPTURED.
		 *
		 * On a Polylang site in directory mode every URL is `/fr/…`, and these rules are
		 * `^`-anchored, so none of them matched: a French visitor had no URL at which they
		 * could read or sit an exam. Polylang cannot fix that from its side either — it only
		 * prefixes rules that reach it through a named `{group}_rewrite_rules` filter, and
		 * `add_rewrite_rule()` puts ours in `extra_rules_top`, which its own code excludes.
		 *
		 * So the rules are registered again behind each language segment, and the segment is
		 * matched WITHOUT a capture group. That is the whole trick: Polylang has already read
		 * the language off the path at `plugins_loaded` priority 1, long before WordPress
		 * parses a rewrite rule — so by the time this matches, the language is set and we
		 * only have to let the URL through. Capturing it would shift every `$matches[n]` by
		 * one for no gain whatever.
		 *
		 * EMPTY BY DEFAULT, and that is the free/premium seam: a free build ships no
		 * per-language content, registers nothing here, and behaves exactly as it did.
		 *
		 * @param string[] $languages Language slugs, e.g. `array( 'fr', 'de' )`.
		 */
		$languages = (array) apply_filters( 'plugixa_exam_url_languages', array() );

		$languages = array_values(
			array_unique(
				array_filter(
					array_map(
						/*
						 * A slug reaches a REGEX, so it is reduced to what a language slug may
						 * contain rather than trusted. Anything else becomes '' and `array_filter`
						 * drops it.
						 */
						static fn( $language ): string => (string) preg_replace( '/[^a-z0-9_-]/i', '', (string) $language ),
						$languages
					)
				)
			)
		);

		foreach ( $languages as $language ) {
			$this->add_rules( $base, $language . '/' );
		}

		/**
		 * Register additional rewrite rules.
		 *
		 * A module owning its own page adds it here. Fired AFTER the built-in rules so a
		 * module cannot accidentally shadow the runner or the result page by registering
		 * something greedier — `add_rewrite_rule` with `top` puts the newest first, and an
		 * exam that stopped opening because an add-on claimed `^exams/` would be a genuinely
		 * miserable afternoon.
		 *
		 * @param string $base The exam URL base, for a module that wants to sit under it.
		 */
		do_action( 'plugixa_exam_register_rewrites', $base );

		$this->flush_if_stale( $languages );
	}

	/**
	 * The eight routes, once per path prefix.
	 *
	 * @param string $base   The exam URL base.
	 * @param string $prefix Leading path segment, already trailing-slashed, or ''.
	 */
	private function add_rules( string $base, string $prefix ): void {
		// Ordered most specific first: `exams/([^/]+)/?$` would otherwise swallow
		// `exams/algebra/take` and route it to the landing page with a slug of "algebra".
		add_rewrite_rule( "^{$prefix}{$base}/([^/]+)/take/?$", 'index.php?' . self::VIEW . '=take&' . self::SLUG . '=$matches[1]', 'top' );
		add_rewrite_rule( "^{$prefix}{$base}/([^/]+)/?$", 'index.php?' . self::VIEW . '=exam&' . self::SLUG . '=$matches[1]', 'top' );
		add_rewrite_rule( "^{$prefix}{$base}/?$", 'index.php?' . self::VIEW . '=catalogue', 'top' );

		add_rewrite_rule( "^{$prefix}result/([0-9]+)/?$", 'index.php?' . self::VIEW . '=result&' . self::ID . '=$matches[1]', 'top' );
		add_rewrite_rule( "^{$prefix}verify/([A-Za-z0-9]+)/?$", 'index.php?' . self::VIEW . '=verify&' . self::TOKEN . '=$matches[1]', 'top' );

		/*
		 * THE LEARNER'S THREE DESTINATIONS, AND THEY USED TO BE ONE.
		 *
		 * `my-results` and `my-certificates` were anchors into the portal — `#pxe-my-results`
		 * and `#pxe-my-certificates` — which is why the certificates item in the navigation
		 * hard-coded `current => false`: there was no page for it to be current on. They are
		 * pages now, and the portal keeps assignments alone.
		 */
		add_rewrite_rule( "^{$prefix}my-exams/?$", 'index.php?' . self::VIEW . '=portal', 'top' );
		add_rewrite_rule( "^{$prefix}my-results/?$", 'index.php?' . self::VIEW . '=results', 'top' );
		add_rewrite_rule( "^{$prefix}my-certificates/?$", 'index.php?' . self::VIEW . '=certificates', 'top' );
	}

	/**
	 * Flush when the rule set has actually changed shape.
	 *
	 * ────────────────────────────────────────────────────────────────────────────────────
	 * THE ACTIVATION FLAG WAS NEVER ENOUGH, AND NOT ONLY BECAUSE OF LANGUAGES.
	 *
	 * `Activator::activate()` sets `plugixa_exam_flush_rewrites`, but activation runs only on
	 * activation — an UPDATE goes through `migrate()`, which does not set it. Rules added by a
	 * new version therefore lay dormant until somebody happened to re-save permalinks. That is
	 * what {@see self::RULES_VERSION} is for: it is part of the signature, so adding a route
	 * and bumping it flushes every site once, by itself.
	 *
	 * Languages make the same problem worse: an administrator who adds French in Polylang next month must
	 * not have to know that Settings → Permalinks exists. So the language list is signed, and
	 * a signature differing from the stored one raises the same flag the activator raises.
	 * Self-healing, at the cost of one option read per request.
	 * ────────────────────────────────────────────────────────────────────────────────────
	 *
	 * @param string[] $languages The language slugs this request registered rules for.
	 */
	private function flush_if_stale( array $languages ): void {
		sort( $languages );

		/*
		 * WHICH BUILD IS SERVING IS SIGNED TOO. The premium build adds addresses of its own
		 * (`/lti/…`, `/monitor/`), and it usually arrives while the free one is active: on
		 * that request the copy being activated stands down (see the main plugin file), so
		 * its activation never raises the flag. Without this the premium addresses answered
		 * 404 until somebody re-saved permalinks — caught by the release E2E the day the
		 * stand-down was written, 10 Oct 2026. Going back to the free build flushes as well,
		 * which takes the premium rules out again.
		 */
		$signature = self::RULES_VERSION . '|' . implode( ',', $languages ) . '|' . ( is_dir( PLUGIXA_EXAM_PATH . 'pro' ) ? 'premium' : 'free' );

		if ( (string) get_option( self::LANGUAGES_OPTION, '' ) !== $signature ) {
			update_option( self::LANGUAGES_OPTION, $signature );
			update_option( 'plugixa_exam_flush_rewrites', '1' );
		}

		/*
		 * Flushing is expensive — it rewrites the whole rule set — so it happens once, when
		 * something has left a flag. Activation itself cannot flush usefully: `init` has not
		 * run at that point, so the rules above do not exist yet and the flush would write a
		 * set without them.
		 */
		if ( get_option( 'plugixa_exam_flush_rewrites' ) ) {
			delete_option( 'plugixa_exam_flush_rewrites' );
			flush_rewrite_rules( false );
		}
	}

	/**
	 * Tell WordPress that a matched exam URL is a real page.
	 *
	 * Without this, `/exams/algebra/` resolves to no post, `is_404()` is true, and themes
	 * that key off it render a "not found" header above a perfectly good exam.
	 *
	 * @param \WP_Query $query The main query.
	 */
	public function suppress_404( $query ): void {
		if ( ! $query->is_main_query() || '' === $this->view() ) {
			return;
		}

		$query->is_404  = false;
		$query->is_home = false;

		status_header( 200 );
	}

	/**
	 * The same again, once the query has actually run.
	 *
	 * `WP::handle_404()` has sent a 404 header by now and `WP_Query::get_posts()` has set the
	 * flag, so both have to be undone rather than pre-empted. `is_home` is left alone here —
	 * it was already corrected before the query, which is when it mattered for choosing a
	 * template.
	 *
	 * See {@see register_hooks()} for why this exists at all.
	 */
	public function suppress_404_late(): void {
		global $wp_query;

		if ( ! $wp_query instanceof \WP_Query || '' === $this->view() ) {
			return;
		}

		if ( ! $wp_query->is_404 ) {
			return;
		}

		$wp_query->is_404 = false;

		status_header( 200 );
	}

	/**
	 * Swap in one of the plugin's templates.
	 *
	 * @param string $template The template WordPress chose.
	 */
	public function route( string $template ): string {
		$view = $this->view();

		if ( '' === $view ) {
			return $template;
		}

		$file = TemplateLoader::locate( $view . '.php' );

		return file_exists( $file ) ? $file : $template;
	}

	/**
	 * The view this request resolved to, if any.
	 */
	public function view(): string {
		$view = (string) get_query_var( self::VIEW );

		/**
		 * The views this plugin answers to.
		 *
		 * A WHITELIST, NOT A PATTERN. `plugixa_exam_view` is a query var, so anything can put
		 * anything in it; `route()` turns whatever comes back from here into a template
		 * filename. Without the list, `?plugixa_exam_view=../../wp-config` is a path the
		 * template loader would be asked to resolve — {@see TemplateLoader::locate()} strips
		 * traversal as well, but one guard that can be reasoned about beats two that both
		 * have to hold.
		 *
		 * @param string[] $views Registered view names.
		 */
		$views = (array) apply_filters(
			'plugixa_exam_views',
			array( 'catalogue', 'exam', 'take', 'result', 'verify', 'portal', 'results', 'certificates' )
		);

		return in_array( $view, $views, true ) ? $view : '';
	}

	/*
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * ⚠️ `owns_document()` WAS HERE AND IS REMOVED, NOT DEPRECATED.
	 *
	 * It answered "does this request emit the plugin's own document?" and it has meant
	 * three different things. First a setting (`frontend_chrome = app`, retired — see
	 * `Core\Activator::RETIRED_SETTINGS`). Then `'take' === $view`: the runner alone stood
	 * outside the theme's document, because a menu during a timed sitting is a way out of a
	 * live attempt. The site owner has since chosen the site's navigation on every view,
	 * the runner included, so that exception went with it.
	 *
	 * What remains is not a question about the REQUEST at all — it is a question about the
	 * THEME, and {@see Chrome::emits_document()} is where it is asked. Leaving a forwarder
	 * on `Router` would have been worse than removing it: same name, same signature, an
	 * answer that no longer varies with the view anybody was passing it. This codebase has
	 * already made that call once, for `plugixa_exam_frontend_nav`.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 */

	/* ----------------------------------------------------------------- links */

	/**
	 * One URL, with whatever the reader's language needs added to it.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * EVERY LINK IN THE PLUGIN COMES THROUGH HERE, WHICH IS WHY THE LANGUAGE CAN.
	 *
	 * There is exactly one place a front-end URL is built — these four methods — and not one
	 * URL is assembled in JavaScript anywhere in the runner or the portal. So a language only
	 * has to be added once to survive the whole journey: catalogue card, gate, runner,
	 * result, the redirect out of `admin-post.php`, the link in a results email.
	 *
	 * WordPress will not do it for us. Polylang filters `home_url()`, but its filter is
	 * whitelist-gated by a `debug_backtrace()` — theme root, `wp_nav_menu`, `login_footer`,
	 * `get_custom_logo`, the site-title and home-link blocks — and a plugin directory is on
	 * none of those lists. Measured, not assumed: our links come out unprefixed today, which
	 * is why nothing is broken and everything is simply in the wrong language.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string $path    Absolute path, leading slash, already encoded.
	 * @param string $context Which link this is: `catalogue`, `exam`, `take` or `result`.
	 */
	private function link( string $path, string $context, string $argument = '' ): string {
		/*
		 * ⚠️ A MAPPED PAGE DOES NOT GO THROUGH `plugixa_exam_frontend_url`, AND THAT IS NOT
		 * AN OMISSION. That filter's entire job is to add the language a permalink does not
		 * carry — a `/fr/` segment, or a `lang` argument. `get_permalink()` on a page has
		 * already done exactly that, in the language the page itself is in, so running the
		 * filter over it would prefix a URL that is already prefixed.
		 *
		 * Which page a language should get is a different question, and it is asked in the
		 * right place: `plugixa_exam_page_id` in {@see self::page_id()}, where a translation
		 * plugin can swap the id for its own translation of that page.
		 */
		$page = $this->page_url( $context, $argument );

		if ( null !== $page ) {
			return $page;
		}

		/**
		 * Decorate a front-end URL for the language the reader is in.
		 *
		 * A listener returns the URL unchanged unless it has something to add — a `/fr/` path
		 * segment on a Polylang site in directory mode, a `lang` query argument otherwise.
		 * Unhooked, this is `home_url()` and nothing else, which is the free build.
		 *
		 * ⚠️ THE CERTIFICATE VERIFICATION URL DELIBERATELY DOES NOT COME THROUGH HERE. See
		 * {@see verify_url()}.
		 *
		 * @param string $url     The URL as built.
		 * @param string $path    The path it was built from.
		 * @param string $context Which link this is.
		 */
		return (string) apply_filters( 'plugixa_exam_frontend_url', home_url( $path ), $path, $context );
	}

	/**
	 * The site owner's own page for this link, if they have named one.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THIS IS WHAT STOPS A HAND-BUILT MENU LEAKING.
	 *
	 * A site owner puts the catalogue on `/learning/exams/` and adds it to their menu. Every
	 * card on it used to link to `/exams/algebra/` — the plugin's own route, with the
	 * plugin's own sidebar — so the first thing a learner clicked took them out of the
	 * section the menu had just put them in. A menu that leads somewhere and then leaks is
	 * more confusing than no menu at all.
	 *
	 * Every URL in this plugin is built by {@see self::link()} — cards, the gate, the
	 * redirect out of `admin-post.php`, the link in a results email, anything built in cron.
	 * So one lookup here is the whole feature.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * ⚠️ AN UNPUBLISHED OR TRASHED PAGE FALLS BACK TO THE ROUTE. `get_permalink()` answers
	 * for a draft as happily as for a published page — with a `?page_id=` URL that shows a
	 * visitor nothing. A site owner who trashes the page they mapped last year should get
	 * the built-in route back, not a dead link in every results email.
	 *
	 * @param string $context Which link this is.
	 * @param string $argument The exam slug or attempt id it is about, if any.
	 */
	private function page_url( string $context, string $argument ): ?string {
		if ( ! Pages::supports( $context ) ) {
			return null;
		}

		$id = $this->page_id( $context );

		if ( $id <= 0 || 'publish' !== get_post_status( $id ) ) {
			return null;
		}

		$url = get_permalink( $id );

		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}

		if ( '' === $argument ) {
			return $url;
		}

		$parameter = 'exam' === $context ? self::PARAM_EXAM : self::PARAM_RESULT;

		return add_query_arg( $parameter, rawurlencode( $argument ), $url );
	}

	/**
	 * The page id mapped to a view, or 0.
	 *
	 * ⚠️ NOT MEMOISED, ON PURPOSE. The answer can change inside a single request: rendering
	 * a batch of results emails switches locale between recipients, and a translation plugin
	 * answering the filter below has to be asked again each time. Both reads underneath are
	 * already cached — `Settings::all()` memoises, and `get_permalink()` uses the post cache.
	 *
	 * @param string $view The view name.
	 */
	private function page_id( string $view ): int {
		try {
			$settings = \PlugixaExam\Core\Plugin::instance()->container()
				->get( \PlugixaExam\Core\Settings::class );
		} catch ( \Throwable $e ) {
			// Settings briefly unreadable is not a reason to hand back a broken link; the
			// built-in route always works. Same reasoning as the rest of this class:
			// a link that works beats a link that is ideal.
			return 0;
		}

		$pages = $settings->get( Pages::SETTING );
		$id    = is_array( $pages ) ? (int) ( $pages[ $view ] ?? 0 ) : 0;

		/**
		 * The page a front-end view is hosted on.
		 *
		 * Where a translation plugin swaps the id for its own translation of that page, so
		 * a French reader gets the French page rather than the one the owner happened to
		 * pick in the settings. Unhooked, this is the stored id and nothing else.
		 *
		 * @param int    $id   The page id from the settings, or 0.
		 * @param string $view The view being linked to.
		 */
		return (int) apply_filters( 'plugixa_exam_page_id', $id, $view );
	}

	/**
	 * Where a front-end form posts.
	 *
	 * ────────────────────────────────────────────────────────────────────────────────────
	 * IT EXISTS SO THE LANGUAGE SURVIVES `admin-post.php`.
	 *
	 * The gate and the no-JS runner post to `admin-post.php`, which is not one of our routes
	 * and never will be — so it cannot carry a `/fr/` path segment, and there is no language
	 * context on that request for `FormHandler` to rebuild its redirect from. Sending the
	 * language along in the ACTION's query string is what closes that gap: the POST arrives
	 * carrying it, `Locales::current()` reads it, and every URL `FormHandler` then builds
	 * comes back through {@see link()} already localised. No hidden field, and no change to
	 * `FormHandler` at all.
	 * ────────────────────────────────────────────────────────────────────────────────────
	 */
	public function form_url(): string {
		/** This filter is documented in includes/Core/Frontend/Router.php */
		return (string) apply_filters( 'plugixa_exam_frontend_url', admin_url( 'admin-post.php' ), '', 'form' );
	}

	/**
	 * Link to the exam catalogue.
	 */
	public function catalogue_url(): string {
		return $this->link( '/' . $this->base() . '/', 'catalogue' );
	}

	/**
	 * Link to the learner's own portal.
	 *
	 * The rewrite rule for `^my-exams/?$` has existed since Portal became a free module;
	 * this accessor had not, so every link to it was either a hard-coded `/my-exams/` or a
	 * `home_url()` call that skipped {@see link()} and therefore skipped the language
	 * prefix. The frontend navigation is the first thing that needed it by name.
	 */
	public function portal_url(): string {
		return $this->link( '/my-exams/', 'portal' );
	}

	/**
	 * The learner's own results.
	 *
	 * Hard-coded like {@see self::portal_url()} rather than built from {@see self::base()}:
	 * these are the READER's pages, not the exam area's, and a site that renamed its exam
	 * base to `assessments` has not thereby renamed "my results".
	 */
	public function results_url(): string {
		return $this->link( '/my-results/', 'results' );
	}

	/** The learner's own certificates. Same reasoning as {@see self::results_url()}. */
	public function certificates_url(): string {
		return $this->link( '/my-certificates/', 'certificates' );
	}

	/**
	 * Link to an exam's landing page.
	 *
	 * The slug must be a real one. With an empty slug this is `/exams//`, an address with
	 * nothing at it — a caller that may not have an exam asks for {@see self::catalogue_url()}.
	 *
	 * @param string $slug Exam slug.
	 */
	public function exam_url( string $slug ): string {
		return $this->link( '/' . $this->base() . '/' . rawurlencode( $slug ) . '/', 'exam', $slug );
	}

	/**
	 * Link to the runner.
	 *
	 * @param string $slug Exam slug.
	 */
	public function take_url( string $slug ): string {
		return $this->link( '/' . $this->base() . '/' . rawurlencode( $slug ) . '/take/', 'take' );
	}

	/**
	 * Link to an attempt's result.
	 *
	 * @param int $attempt_id Attempt id.
	 */
	public function result_url( int $attempt_id ): string {
		return $this->link( '/result/' . $attempt_id . '/', 'result', (string) $attempt_id );
	}

	/**
	 * Public verification link for a certificate.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE ONE URL THAT MUST NEVER CARRY A LANGUAGE, AND IT IS NOT A STYLE CHOICE.
	 *
	 * This is printed on a certificate and encoded into its QR code
	 * ({@see \PlugixaExam\Modules\Certificates\Services\Issuer}). It is on paper, in
	 * somebody's file, for years. A URL that carried the language of the browser that happened
	 * to issue it would be a promise this plugin cannot keep: change the site's languages,
	 * remove French from Polylang, and every certificate printed that season stops resolving.
	 *
	 * So it stays exactly what it has always been, the unprefixed rule remains registered
	 * alongside the language ones, and the verification page takes its language from the
	 * CERTIFICATE — which knows the attempt, and the attempt froze a locale.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string $token The certificate's verification token.
	 */
	public function verify_url( string $token ): string {
		return home_url( '/verify/' . rawurlencode( $token ) . '/' );
	}
}
