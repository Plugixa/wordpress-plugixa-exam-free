<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identity for someone taking an exam without a WordPress account.
 *
 * THIS CLASS EXISTS TO REPLACE A PHP SESSION, and that is the whole design brief.
 *
 * NextExams carries guest identity and access-code clearance in `$_SESSION`, through a
 * dedicated system plugin. In WordPress that is not an option:
 *
 *  - `session_start()` sends `Cache-Control: no-store`, so every page that touches it
 *    becomes uncacheable. On a site with page caching — which is most sites, and all the
 *    fast ones — a single guest attempt poisons the cache for the exam catalogue.
 *  - It is a documented WordPress.org review rejection for exactly that reason.
 *  - PHP's default session handler writes files on the web server, which breaks the moment
 *    the site is load-balanced. An exam that loses its session halfway through is worse
 *    than an exam that never started.
 *
 * So the token lives in a signed cookie, and everything the token unlocks lives server-side
 * in a transient keyed by it. The cookie is a bearer credential and nothing more: it
 * carries no claims, so forging one gets you an identity with no attempts and no
 * clearances. `NoFrontendSessionTest` asserts the plugin never calls `session_start()`.
 */
final class GuestToken {

	private const COOKIE = 'plugixa_exam_guest';

	/**
	 * How long a guest identity survives.
	 *
	 * Long enough to outlast any single exam — including one paused overnight — and short
	 * enough that a shared library computer does not hand the next person the last
	 * person's attempt.
	 */
	private const LIFETIME = 2 * DAY_IN_SECONDS;

	/**
	 * The current visitor's guest token, if they have a valid one.
	 *
	 * Reads and verifies on every call rather than memoising, because the cookie can be
	 * set mid-request by {@see issue()} and a stale memo would make the freshly-issued
	 * token invisible to the code that issued it.
	 */
	public static function current(): ?string {
		// A bearer credential, not a form submission: the signature checked below is what
		// verifies it, and a nonce has nothing to say about a cookie.
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';

		if ( '' === $raw ) {
			return null;
		}

		return self::verify( $raw );
	}

	/**
	 * Mint a new guest token and send it to the browser.
	 *
	 * Returns the token so the caller can write it onto the attempt row in the same
	 * request — `current()` would not see it until the browser sends the cookie back.
	 */
	public static function issue(): string {
		$token = wp_generate_password( 32, false, false );

		self::send( $token );

		return $token;
	}

	/**
	 * The visitor's token, minting one if they have none.
	 */
	public static function ensure(): string {
		return self::current() ?? self::issue();
	}

	/**
	 * Take on a SPECIFIC guest identity, signing it the same way a minted one is signed.
	 *
	 * ────────────────────────────────────────────────────────────────────────────────────
	 * FOR AN IDENTITY THAT COMES FROM SOMEWHERE ELSE AND HAS TO BE STABLE.
	 *
	 * An LTI launch is the case this exists for. The same learner arriving from the same
	 * course in the same LMS must land on the same attempts every time, and the only thing
	 * that identifies them is a `sub` claim in a signed token — not a cookie this site has
	 * ever set. Minting a random token per launch would give somebody a brand-new identity,
	 * and therefore a brand-new first attempt, every time they clicked the link.
	 *
	 * THE TOKEN IT IS GIVEN MUST NOT BE GUESSABLE, and that is the caller's responsibility
	 * rather than something this can check. The LTI module derives one by HMAC over the
	 * platform, deployment and subject using this site's own salt, so knowing somebody's
	 * LMS user id does not let anybody adopt their attempts.
	 * ────────────────────────────────────────────────────────────────────────────────────
	 *
	 * @param string $token The identity to adopt.
	 *
	 * @return string The same token, for symmetry with {@see issue()}.
	 */
	public static function adopt( string $token ): string {
		self::send( $token );

		return $token;
	}

	/**
	 * Forget this visitor.
	 */
	public static function clear(): void {
		$token = self::current();

		if ( null !== $token ) {
			delete_transient( self::gate_key( $token ) );
		}

		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, '', self::cookie_options( time() - DAY_IN_SECONDS ) );
		}

		unset( $_COOKIE[ self::COOKIE ] );
	}

	/**
	 * Record that this visitor has satisfied an exam's access code.
	 *
	 * Server-side, keyed by the token, so that clearing it is possible and forging the
	 * cookie gains nothing: an attacker who mints their own token holds a token with an
	 * empty clearance set.
	 *
	 * @param int $exam_id Exam the visitor has cleared.
	 */
	public static function grant_gate( int $exam_id ): void {
		$token = self::ensure();
		$key   = self::gate_key( $token );

		$cleared = (array) get_transient( $key );
		$cleared = array_values( array_unique( array_merge( $cleared, array( $exam_id ) ) ) );

		set_transient( $key, $cleared, self::LIFETIME );
	}

	/**
	 * Whether this visitor has satisfied an exam's access code.
	 *
	 * @param int $exam_id Exam id.
	 */
	public static function has_gate( int $exam_id ): bool {
		$token = self::current();

		if ( null === $token ) {
			return false;
		}

		return in_array( $exam_id, (array) get_transient( self::gate_key( $token ) ), true );
	}

	/**
	 * Sign a token and set the cookie.
	 *
	 * @param string $token The raw token.
	 */
	private static function send( string $token ): void {
		$value = $token . '.' . self::sign( $token );

		if ( ! headers_sent() ) {
			setcookie( self::COOKIE, $value, self::cookie_options( time() + self::LIFETIME ) );
		}

		// So `current()` sees it within the request that issued it.
		$_COOKIE[ self::COOKIE ] = $value;
	}

	/**
	 * Split a cookie value and check its signature.
	 *
	 * @param string $raw Cookie value.
	 *
	 * @return string|null The token, or null if the value has been tampered with.
	 */
	private static function verify( string $raw ): ?string {
		$parts = explode( '.', $raw, 2 );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		[ $token, $signature ] = $parts;

		if ( '' === $token || ! hash_equals( self::sign( $token ), $signature ) ) {
			return null;
		}

		return $token;
	}

	/**
	 * HMAC over the token, keyed by the site's auth salt.
	 *
	 * `hash_equals` is used to compare, not `===`: a timing-safe comparison costs nothing
	 * and the alternative leaks the signature one byte at a time to a patient attacker.
	 *
	 * @param string $token The raw token.
	 */
	private static function sign( string $token ): string {
		return hash_hmac( 'sha256', 'plugixa_exam_guest|' . $token, wp_salt( 'auth' ) );
	}

	/**
	 * Cookie flags.
	 *
	 * HttpOnly because no script needs to read this — it is sent automatically and the
	 * runner never inspects it. SameSite=Lax rather than Strict because an exam link
	 * arriving from an LMS or an email is a top-level cross-site navigation, and Strict
	 * would drop the cookie on exactly the journey the learner is most likely to take.
	 *
	 * @param int $expires Expiry timestamp.
	 *
	 * @return array<string, mixed>
	 */
	private static function cookie_options( int $expires ): array {
		return array(
			'expires'  => $expires,
			'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
	}

	/**
	 * Transient key holding a token's access-code clearances.
	 *
	 * Hashed so the token itself is not stored in `wp_options` under a readable name —
	 * a transient key is not a secret store, and a database dump should not hand out live
	 * guest credentials.
	 *
	 * @param string $token The raw token.
	 */
	private static function gate_key( string $token ): string {
		return 'pxe_gate_' . substr( hash( 'sha256', $token ), 0, 32 );
	}
}
