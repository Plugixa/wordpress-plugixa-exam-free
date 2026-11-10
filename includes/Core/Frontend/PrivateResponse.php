<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Frontend;

use PlugixaExam\Core\Security\GuestToken;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks a response as belonging to one visitor, so no page cache stores it.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * ONE PLACE, BECAUSE "PRIVATE" USED TO MEAN WHATEVER EACH CALLER REMEMBERED.
 *
 * Eight views called `nocache_headers()` themselves, and three that render one person's
 * data — the runner (`take.php`), a result (`result.php`) and an exam's landing page once
 * the visitor has history there — called nothing at all. A cache that ignores a cookie it
 * does not recognise, which is WP Super Cache's default, would store the runner at
 * `/exams/<slug>/take/` and hand one guest's paper, answers included, to the next person
 * who opened that URL.
 *
 * Headers alone do not stop that. `nocache_headers()` is what a proxy or a CDN reads, but
 * the caching PLUGINS decide in PHP, and the signal they share is the `DONOTCACHEPAGE`
 * constant — WP Super Cache, W3 Total Cache, WP Rocket and LiteSpeed Cache all honour it,
 * and WooCommerce sets it on the cart and checkout for exactly this reason. So this sends
 * both. It is safe to call late, from inside a shortcode: the constant is read when the
 * page's output buffer is flushed, and headers go out with the first flushed byte.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ MARK ONLY WHAT IS PRIVATE. The other half of the rule is that a public page stays
 * cacheable — a first-time visitor's catalogue and landing page are byte for byte the same
 * for everyone, and marking them would switch caching off for the audience it exists for.
 * {@see self::visitor_is_known()} is the test for pages that are public until somebody has a
 * history there.
 */
final class PrivateResponse {

	/**
	 * How many times this request was marked. Read by the test suite.
	 */
	private static int $marks = 0;

	/**
	 * Keep this response out of every page cache.
	 */
	public static function mark(): void {
		++self::$marks;

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page-caching plugins' shared constant; it only works under this exact name.
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
	}

	/**
	 * Whether this visitor could see something on a page that another visitor would not.
	 *
	 * Signed in, or holding a guest identity from an attempt they started. A visitor with
	 * neither has no attempts, no marks and no resume link anywhere — every access verdict
	 * treats a missing guest token as an empty history — so what they are shown is what
	 * everyone with neither is shown.
	 *
	 * READS the guest cookie, never sets one. Setting it during a render is what made every
	 * public exam page uncacheable for every first-time visitor.
	 */
	public static function visitor_is_known(): bool {
		return is_user_logged_in() || null !== GuestToken::current();
	}

	/**
	 * Whether this page is the answer to a form — reached by a redirect carrying `pxe_error`.
	 *
	 * Not per-visitor, but still not to be cached: when a form's nonce no longer verifies, the
	 * visitor is sent back here to press the button again, and a cached copy of THIS page would
	 * hand them the same stale form and the same redirect, round and round. It is rare enough
	 * that serving it fresh costs nothing.
	 */
	public static function answers_a_form(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only whether a display marker is present; nothing is read from it or changed.
		return isset( $_GET['pxe_error'] );
	}

	/**
	 * How many times this request was marked.
	 *
	 * @internal For the test suite.
	 */
	public static function marks(): int {
		return self::$marks;
	}

	/**
	 * Forget previous marks.
	 *
	 * @internal For the test suite. The constant cannot be undefined, and does not need to be:
	 *           nothing caches a command-line process.
	 */
	public static function reset(): void {
		self::$marks = 0;
	}
}
