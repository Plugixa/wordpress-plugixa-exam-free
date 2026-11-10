<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\App;

use PlugixaExam\Core\Modules\AppRegistry;
use PlugixaExam\Core\Plugin;
use PlugixaExam\Core\Security\Permissions;
use PlugixaExam\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the browser knows before the first request.
 *
 * INJECTED AS AN INLINE SCRIPT, NOT `wp_localize_script()`.
 *
 * The bundle is enqueued with `wp_enqueue_script_module()`, and `wp_localize_script` cannot
 * attach data to a script module — it targets the classic script registry, which the module
 * handle is not in. So a src-less handle is registered purely to carry an inline `before`
 * script. This looks like a workaround and is in fact the supported route.
 *
 * The REST nonce is here rather than fetched, because the app's very first request needs
 * it, and a bootstrap request that has to be preceded by a nonce request has not saved
 * anybody anything.
 */
final class Config {

	private const HANDLE = 'plugixa-exam-config';

	/**
	 * Put `window.PlugixaExamConfig` on the page.
	 *
	 * @param string $context Where the app is mounting: `admin` or `frontend`.
	 */
	public static function inject( string $context = 'admin' ): void {
		wp_register_script( self::HANDLE, '', array(), PLUGIXA_EXAM_VERSION, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script(
			self::HANDLE,
			'window.PlugixaExamConfig = ' . wp_json_encode( self::payload( $context ) ) . ';',
			'before'
		);
	}

	/**
	 * The site's timezone, as something a JavaScript date library will accept.
	 *
	 * `wp_timezone_string()` returns an IANA name when the site has chosen a city, and a
	 * `±HH:MM` offset when it is on the legacy "UTC+n" setting. Both are valid Temporal
	 * time-zone identifiers, so both pass straight through — except a zero offset, which
	 * becomes `UTC`: the same instant, written in the form every library recognises and
	 * every reader understands.
	 */
	public static function timezone(): string {
		$zone = wp_timezone_string();

		return in_array( $zone, array( '+00:00', '-00:00', '' ), true ) ? 'UTC' : $zone;
	}

	/**
	 * @param string $context Where the app is mounting.
	 *
	 * @return array<string, mixed>
	 */
	private static function payload( string $context ): array {
		$container = Plugin::instance()->container();
		$apps      = $container->get( AppRegistry::class );

		$config = array(
			'context'     => $context,
			'restUrl'     => esc_url_raw( rest_url( 'plugixa-exam/v1/' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'adminUrl'    => esc_url_raw( admin_url() ),
			'pluginUrl'   => esc_url_raw( PLUGIXA_EXAM_URL ),
			'version'     => PLUGIXA_EXAM_VERSION,
			'rootId'      => Bundle::ROOT_ID,
			'siteName'    => get_bloginfo( 'name' ),
			'locale'      => get_user_locale(),

			/*
			 * WHICH WAY THE PAGE RUNS, decided by WordPress rather than by the app. A locale
			 * is not enough on its own: `is_rtl()` reads the LOADED TRANSLATION's own
			 * `Text Direction` header, so a site running an RTL language through a
			 * translation that declares itself left-to-right is answered correctly, and a
			 * plugin does not have to keep its own list of which languages are which.
			 */
			'isRtl'       => is_rtl(),

			/*
			 * THE SITE'S TIMEZONE, NOT THE BROWSER'S, AND THE DIFFERENCE IS A REAL BUG.
			 *
			 * Every datetime this plugin stores is UTC, and every front-end template renders
			 * it through `wp_date()`, which uses the site's timezone. A JavaScript component
			 * left to its own devices uses the machine's instead — so an administrator whose
			 * laptop is an hour off the site sees an exam opening at 09:00 that the examinee
			 * is told opens at 08:00. Nothing errors; the two screens simply disagree, and
			 * the one people plan around is the wrong one.
			 */
			'timezone'    => self::timezone(),
			'currentUser' => self::current_user(),

			/*
			 * The header's furniture: "View site", the account menu's "Log out" and the pill that
			 * says a site is not the live one. `wp_logout_url()` carries a nonce, which is fine
			 * here — WordPress prints the same link in its own admin bar on this very page.
			 *
			 * ⚠️ `wp_logout_url()` IS HTML, NOT A URL. It goes through `wp_nonce_url()`, which
			 * `esc_html()`s its result, so the separator comes back as `&amp;` — and
			 * `esc_url_raw()` leaves that alone. Handed to React as an `href` property (not
			 * parsed as HTML) the entity is never decoded: the query string reads
			 * `action=logout&amp;_wpnonce=…`, the nonce arrives as a parameter named
			 * `amp;_wpnonce`, and WordPress answers with its "Do you really want to log out?"
			 * page. Measured with wp-cli, 24 Sep 2026.
			 */
			'homeUrl'     => esc_url_raw( home_url( '/' ) ),
			'logoutUrl'   => esc_url_raw( str_replace( '&amp;', '&', wp_logout_url() ) ),
			'env'         => wp_get_environment_type(),

			/*
			 * NO `examBaseUrl` ANY MORE. It was the catalogue's URL, and the Exams list appended
			 * `<slug>/` to it — an address that only exists on pretty permalinks with no page
			 * mapped. Each list row now carries its own `view_url`, built by the Router.
			 */

			/*
			 * The permission matrix travels with the page so the shell can decide what to
			 * draw without waiting for /bootstrap. It is the same matrix the API enforces
			 * — see Permissions — so the UI cannot be stricter or looser than the server.
			 * It is a rendering hint, never the enforcement: every route checks again.
			 */
			'permissions' => Permissions::matrix_for(),

			/*
			 * The enabled-app map, from the same registry the server-side request guard
			 * reads. Client and server cannot come to different conclusions about whether
			 * Certificates is on.
			 */
			'apps'        => $apps->enabled_map(),

			'settings'    => self::public_settings( $container->get( Settings::class ) ),
			'i18n'        => Strings::all(),
		);

		/**
		 * Filter the configuration handed to the browser.
		 *
		 * Modules add what their screens need at first paint. Anything secret must not be
		 * added here — this object is readable by anyone who can view the page source.
		 *
		 * @param array<string, mixed> $config  The configuration.
		 * @param string               $context Where the app is mounting.
		 */
		return (array) apply_filters( 'plugixa_exam_app_config', $config, $context );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function current_user(): array {
		$user = wp_get_current_user();

		return array(
			'id'     => (int) $user->ID,
			'name'   => $user->display_name,
			'email'  => $user->user_email,
			'avatar' => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
		);
	}

	/**
	 * Settings the browser is allowed to see.
	 *
	 * An allow-list by prefix rather than the whole array. Settings hold the LTI keypair
	 * and the sweep's internal markers, and "send everything and remember to remove the
	 * secret later" is how a keypair ends up in page source.
	 *
	 * @param Settings $settings The settings store.
	 *
	 * @return array<string, mixed>
	 */
	private static function public_settings( Settings $settings ): array {
		$public = array();

		/*
		 * THE SEAM THAT SAYS "ADD A SETTING THE BROWSER MAY SEE" IS NOT HANDED THE ONES IT
		 * MAY NOT.
		 *
		 * This used to read — and to pass to the filter below — the whole store, including
		 * the LTI private key. Every listener in this plugin names its keys explicitly and
		 * was therefore fine, but an add-on copying a range of keys, or one written by
		 * somebody who assumed the second argument was already safe, would leak a key
		 * material through a filter whose entire purpose is to widen what the page carries.
		 * Narrowing the input costs nothing: nothing public was ever internal.
		 */
		$all = $settings->public_view();

		foreach ( $all as $key => $value ) {
			if ( str_starts_with( (string) $key, 'color_' )
				|| in_array( $key, array( 'decimal_places', 'decimal_separator', 'thousands_separator', 'date_format', 'time_format' ), true )
			) {
				$public[ $key ] = $value;
			}
		}

		/**
		 * Add a setting to the set the browser receives at page load.
		 *
		 * @param array<string, mixed> $public The public subset.
		 * @param array<string, mixed> $all    Every setting a browser is allowed to see —
		 *                                     NOT every setting. See above.
		 */
		return (array) apply_filters( 'plugixa_exam_public_settings', $public, $all );
	}
}
