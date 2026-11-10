<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load the plugin's own translation catalogue.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * TWO CATALOGUES, ONE DOMAIN — AND THEY DO MERGE. THIS FILE ONCE SAID THE OPPOSITE.
 *
 * The free half lives in `languages/` (it is all translate.wordpress.org can ever produce,
 * since it only ever sees the free ZIP) and the premium half in `pro/languages/`. Both are
 * loaded here, under the one domain.
 *
 * THE ORIGINAL MEASUREMENT WAS WRONG, AND THE WAY IT WAS WRONG IS WORTH KEEPING. It probed
 * with `load_plugin_textdomain()` for the free half and concluded, in both orders, "free
 * English, premium translated" — read as the second load taking the domain. The control it
 * did not run is the one that explains it: with the premium file ABSENT, that same probe
 * still returned free English. The free catalogue was never loading at all in that harness,
 * and the premium load was blamed for a failure that was already there.
 *
 * Re-measured on WordPress 7.1, loading both files directly, with a real string from each:
 *
 *   free only                 ->  free translated,  premium English
 *   free first, then premium  ->  BOTH translated
 *   premium first, then free  ->  BOTH translated
 *
 * Merging is what core actually does — `WP_Translation_Controller` keeps a LIST of files
 * per domain and consults them in turn — so neither order loses anything.
 *
 * WHAT THAT BUYS. The premium catalogue ships beside the code it translates, and the
 * free/premium split stays where every other part of this plugin's split already is: the
 * FILESYSTEM. A free build has no `pro/` directory, so `load_premium()` finds nothing and
 * does nothing. There is no build flag to set, no merge step to remember, and no way to
 * accidentally commit a merged `.mo` that would ship premium strings inside the free ZIP —
 * which is the Guideline 5 signal the whole registration seam exists to avoid.
 *
 * The cost this replaced: 823 fully translated premium strings per language shipped to
 * nobody, because the build-time merge was a step the release script never ran.
 *
 * WHICH BUILD CARRIES WHAT. Since WordPress.org's review of 1.1.1 ("Translation files
 * included") the catalogues in `languages/` are declared premium-only in the plugin
 * header: the directory's build ships the `.pot` template and nothing else, and is
 * translated by the language packs WordPress installs into `wp-content/languages/plugins/`.
 * The premium build is not installed from the directory and receives no packs, so it keeps
 * both halves. Nothing in this class differs between the two. {@see load_free()} takes its
 * directory from the registry, which answers with the language-pack folder when a pack is
 * there, and a catalogue that is not on disk is simply not loaded. Measured for a locale
 * nothing ships: without a pack, English and no notice; with one, translated.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * WHY THIS CLASS EXISTS AT ALL, given that the plugin header already declares
 * `Text Domain` and `Domain Path`. Since **WordPress 6.7**, core reads those two headers
 * for every active plugin and registers the path itself — `wp-settings.php`, in the
 * `wp_get_active_and_valid_plugins()` loop, calls
 * `$GLOBALS['wp_textdomain_registry']->set_custom_path( $textdomain, dirname( $plugin ) .
 * $plugin_data['DomainPath'] )`. For a plugin with ONE catalogue that makes a loader
 * redundant on 6.7 and later.
 *
 * This plugin has two, and that is why the class stays. {@see load_premium()} loads a file
 * from `pro/languages/`, which leaves the registry answering "pro/languages/" for the whole
 * domain; {@see register_path()} puts the free folder back and {@see load_free()} loads from
 * it. And the floor is `Requires at least: 6.5`: on 6.5 and 6.6 core registers nothing, so
 * a bundled catalogue is reachable only because this class registers the path and loads the
 * file itself. `I18nTest` recreates those two versions by emptying the registry first.
 *
 * It registers through `WP_Textdomain_Registry::set_custom_path()` rather than the wrapper
 * function WordPress has discouraged since 4.6 — see {@see register_path()} for why that is
 * the same thing and not a way round a warning.
 */
final class I18n {

	/**
	 * The one text domain. Matches the plugin slug, the folder name, and every `__()`
	 * call in the codebase — which `tools/phpcs.xml` pins via `WordPress.WP.I18n`.
	 */
	public const DOMAIN = 'plugixa-exam';

	/**
	 * Hook the loader.
	 *
	 * ON `init`, NOT EARLIER. Since WordPress 6.7 a textdomain loaded before `init`
	 * raises `_load_textdomain_just_in_time was called incorrectly`, because the user's
	 * locale is not settled until then — and on a multilingual site loading early means
	 * loading the wrong language, which is a worse failure than the notice.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'load' ) );

		/*
		 * AND AGAIN ON EVERY LOCALE SWITCH — BOTH HALVES, NOT JUST THE PREMIUM ONE.
		 *
		 * `Notifications\Services\Mailer` renders each result email inside
		 * `switch_to_locale( $recipient_locale )`, and `restore_previous_locale()` fires the
		 * same action, so the original catalogue comes back with it. `WP_Locale_Switcher`
		 * unloads every domain before firing this, so whatever is not reloaded here is gone
		 * for the rest of the request — and `load()` is the only thing that reloads both.
		 */
		add_action( 'change_locale', array( self::class, 'load' ) );
	}

	/**
	 * Tell WordPress's registry where this plugin's catalogues are.
	 *
	 * THE REGISTRY DIRECTLY, where this used to call the function WordPress has discouraged
	 * since 4.6 for plugins hosted on WordPress.org. Registering the folder is ALL that
	 * function has done since 6.7 — it loads nothing, it calls `set_custom_path()` and leaves
	 * the rest to just-in-time loading — and that one side effect is what this plugin needs
	 * from it, on every version: it replaces the registry's answer for the domain, which
	 * {@see load_premium()} would otherwise leave pointing at `pro/languages/`, and clears
	 * the per-locale lookups cached from before a locale switch. Without it the free half
	 * of a premium site goes back to English; `I18nTest` holds that in place.
	 *
	 * On 6.5 and 6.6 the old function also loaded the file. {@see load_free()} does that
	 * here, on every version, so nothing is lost by not asking twice.
	 */
	private static function register_path(): void {
		$registry = $GLOBALS['wp_textdomain_registry'] ?? null;

		if ( $registry instanceof \WP_Textdomain_Registry ) {
			$registry->set_custom_path( self::DOMAIN, PLUGIXA_EXAM_PATH . 'languages' );
		}
	}

	/**
	 * Point WordPress at `languages/`.
	 *
	 * `WP_LANG_DIR/plugins/` still wins when it holds a file, which is what lets a site
	 * owner's own translation — or a newer one from translate.wordpress.org — override the
	 * catalogue we bundle. That precedence is the registry's, not ours.
	 */
	public static function load(): void {
		self::register_path();

		self::load_free();
		self::load_premium();
	}

	/**
	 * Load the free catalogue, rather than leaving it to just-in-time loading.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * WITHOUT THIS, A PREMIUM SITE IN ANY LANGUAGE BUT ENGLISH LOST ITS ENTIRE FREE HALF.
	 *
	 * Measured on the dev site with `WPLANG=fr_FR`: `__( 'Leaderboard' )` returned
	 * "Classement" while `__( 'Attempts' )` returned "Attempts", and the only file
	 * `WP_Translation_Controller` had loaded was `pro/plugixa-exam-fr_FR.mo`. Every free
	 * string in the admin, the templates and the emails was English on a fully translated
	 * site — about seven hundred of them.
	 *
	 * THE CAUSE IS AN ORDERING TRAP IN CORE, AND IT ONLY BITES A TWO-CATALOGUE PLUGIN.
	 *
	 * Since 6.7, `load_plugin_textdomain()` LOADS NOTHING — it registers the directory and
	 * returns true, and the catalogue arrives later, on the first `__()`, through
	 * `_load_textdomain_just_in_time()`. But `load_textdomain()` ends with
	 * `$wp_textdomain_registry->set( $domain, $locale, dirname( $file ) )`
	 * (`wp-includes/l10n.php:857`), so the premium load below CACHED `pro/languages/` as the
	 * registry's answer for this domain — and it also filled `$l10n[ $domain ]`, so
	 * `get_translations_for_domain()` returned early and just-in-time never ran again.
	 *
	 * Two mechanisms had to fail together and both did: the pointer the free half needed was
	 * overwritten, and the trigger that would have followed it was suppressed. The registry
	 * holds ONE path per domain, and the second catalogue took it.
	 *
	 * The fix is to stop depending on lazy loading at all. Nothing is restored or unwound —
	 * this simply loads the free half before the premium half claims the pointer, which is
	 * one mechanism and therefore one thing a mutation can break.
	 *
	 * ⚠️ THE PRECEDENCE IS THE REGISTRY'S, NOT OURS. The path comes from
	 * `WP_Textdomain_Registry::get()`, exactly as `_load_textdomain_just_in_time()` takes it,
	 * so `WP_LANG_DIR/plugins/` still wins where a site owner or translate.wordpress.org has
	 * put a newer file. Hard-coding `languages/` here would quietly disable that.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 */
	public static function load_free(): void {
		$registry = $GLOBALS['wp_textdomain_registry'] ?? null;

		if ( ! $registry instanceof \WP_Textdomain_Registry ) {
			return;
		}

		$locale = self::locale();
		$dir    = $registry->get( self::DOMAIN, $locale );

		if ( ! is_string( $dir ) || '' === $dir ) {
			return;
		}

		load_textdomain( self::DOMAIN, $dir . self::DOMAIN . '-' . $locale . '.mo', $locale );
	}

	/**
	 * Add the premium half of the catalogue, if this build has one.
	 *
	 * `is_readable()` IS THE ENTIRE PREMIUM CHECK, deliberately. It asks the filesystem the
	 * same question the module registry asks — is `pro/` here — rather than consulting a
	 * licence. A translation is not a feature to withhold: a site that has the files has
	 * paid for them, and one that has not cannot reach this branch at all.
	 *
	 * The locale is resolved exactly as `load_plugin_textdomain()` resolves it, filter and
	 * all. A site that filters `plugin_locale` moves both halves together or neither — the
	 * failure mode worth avoiding is a catalogue registered under a locale that `translate()`
	 * never consults, which is what happens when the FILE and the LOOKUP disagree.
	 */
	public static function load_premium(): void {
		$locale = self::locale();

		$catalogue = PLUGIXA_EXAM_PATH . 'pro/languages/' . self::DOMAIN . '-' . $locale . '.mo';

		if ( ! is_readable( $catalogue ) ) {
			return;
		}

		$registry = $GLOBALS['wp_textdomain_registry'] ?? null;
		$free_dir = $registry instanceof \WP_Textdomain_Registry
			? $registry->get( self::DOMAIN, $locale )
			: false;

		load_textdomain( self::DOMAIN, $catalogue, $locale );

		/*
		 * ⚠️ PUT THE REGISTRY BACK. `load_textdomain()` ends with
		 * `$wp_textdomain_registry->set( $domain, $locale, dirname( $file ) )`, so the call
		 * above just made `pro/languages/` the registry's answer for this domain — and that
		 * answer is what `load_free()` reads next time round.
		 *
		 * It survives everything that looks like it would clear it: `set_custom_path()`
		 * resets the per-locale cache with `array_filter()`, which drops FAILED lookups only,
		 * so a successful-but-wrong path stays. The consequence is invisible on the first
		 * load of a locale, when the cache is empty and `load_free()` resolves correctly —
		 * and then bites on the SECOND, which is any request that switches locale and comes
		 * back. That is every result email.
		 *
		 * Restoring the value read a moment ago, rather than hard-coding `languages/`, keeps
		 * the precedence the registry decides: `WP_LANG_DIR/plugins/` still wins where a site
		 * owner or translate.wordpress.org has put a newer file.
		 */
		if ( $registry instanceof \WP_Textdomain_Registry && is_string( $free_dir ) && '' !== $free_dir ) {
			$registry->set( self::DOMAIN, $locale, $free_dir );
		}
	}

	/**
	 * The locale both halves must agree on.
	 *
	 * `plugin_locale` is WordPress's OWN filter, and `load_plugin_textdomain()` applies it to
	 * pick the free half's locale — so anything loading a catalogue for this domain has to see
	 * the same value or the two disagree. The failure worth avoiding is a catalogue registered
	 * under a locale that `translate()` never consults, which is what happens when the FILE and
	 * the LOOKUP disagree.
	 */
	private static function locale(): string {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's hook, applied exactly as load_plugin_textdomain() does.
		return (string) apply_filters( 'plugin_locale', determine_locale(), self::DOMAIN );
	}
}
