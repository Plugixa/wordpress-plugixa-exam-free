<?php
/**
 * Keep other plugins' site-wide REST checks off this plugin's own routes.
 *
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Lifts another plugin's `rest_request_before_callbacks` check for the length of one of
 * THIS plugin's REST requests, and puts it straight back.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS: TUTOR LMS JUDGES EVERY PLUGIN'S `id` AS IF IT WERE A TUTOR COURSE.
 *
 * Since 4.0.1, `TUTOR\RestAPI::check_permission()` runs on `rest_request_before_callbacks`
 * for EVERY REST route on the site, reads `$request['id']`, and — when that number happens
 * to be the post ID of a Tutor course — refuses a write by anybody who cannot edit that
 * course, and any request at all while the course is unpublished. Tutor's own docblock
 * marks it "@todo will remove and prevent by capability where needed".
 *
 * This plugin's runner routes carry the ATTEMPT id as `id`. Measured on a Tutor 4.0.9 site:
 * a learner whose attempt number equalled a published course's ID could read the paper but
 * could not save or submit it (403 `rest_forbidden`), and one whose attempt matched a DRAFT
 * course could not even read it. Attempts one number either side were fine. On a live site
 * that is a scattering of learners, one per course ID inside the attempt range, whose exam
 * simply stops saving — and nothing on the screen says why.
 *
 * WHY LIFTING IT IS SAFE. On this plugin's routes `id` is never a Tutor post, so the check
 * is answering a question nobody asked; every route here has its own permission callback,
 * which is what actually decides. The check stays in force for Tutor's routes and every
 * other plugin's, and is restored before the next request — including a nested one, since
 * `rest_do_request()` inside a callback runs after the restore point below.
 *
 * Found by identity, not removed wholesale: only a callback whose class and method match an
 * entry in {@see self::FOREIGN} is touched, at whatever priority it was registered.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class ForeignRestChecks {

	/** Every route this plugin registers starts with this. */
	private const ROUTE_PREFIX = '/plugixa-exam/v1';

	/**
	 * Checks to lift, as `array( class, method )`. Class names compare case-insensitively,
	 * as PHP itself does.
	 */
	private const FOREIGN = array(
		array( 'TUTOR\\RestAPI', 'check_permission' ),
	);

	/**
	 * What was lifted for the request in progress, to be put back.
	 *
	 * @var array<int, array{callback: callable, priority: int, accepted_args: int}>
	 */
	private static array $held = array();

	public static function register_hooks(): void {
		// First and last on the same hook: nothing registered in between can see the
		// lifted check missing, and nothing after the request can either.
		add_filter( 'rest_request_before_callbacks', array( self::class, 'lift' ), PHP_INT_MIN, 3 );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'restore' ), PHP_INT_MAX, 1 );
	}

	/**
	 * Take the foreign checks off the hook when the request is one of ours.
	 *
	 * @param mixed            $response Passed through untouched.
	 * @param mixed            $handler  The matched handler (unused).
	 * @param \WP_REST_Request $request  The request.
	 *
	 * @return mixed
	 */
	public static function lift( $response, $handler, $request ) {
		unset( $handler );

		if ( ! $request instanceof \WP_REST_Request || ! self::is_ours( $request->get_route() ) ) {
			return $response;
		}

		global $wp_filter;

		$hook = $wp_filter['rest_request_before_callbacks'] ?? null;

		if ( ! $hook instanceof \WP_Hook ) {
			return $response;
		}

		foreach ( $hook->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $entry ) {
				if ( self::is_foreign( $entry['function'] ) ) {
					self::$held[] = array(
						'callback'      => $entry['function'],
						'priority'      => (int) $priority,
						'accepted_args' => (int) $entry['accepted_args'],
					);
				}
			}
		}

		foreach ( self::$held as $held ) {
			remove_filter( 'rest_request_before_callbacks', $held['callback'], $held['priority'] );
		}

		return $response;
	}

	/**
	 * Put back whatever was lifted.
	 *
	 * @param mixed $response Passed through untouched.
	 *
	 * @return mixed
	 */
	public static function restore( $response ) {
		foreach ( self::$held as $held ) {
			add_filter( 'rest_request_before_callbacks', $held['callback'], $held['priority'], $held['accepted_args'] );
		}

		self::$held = array();

		return $response;
	}

	/**
	 * Whether a route belongs to this plugin — anchored, so `/plugixa-exam/v10/…` or a
	 * look-alike namespace is not.
	 *
	 * @param string $route The matched route.
	 */
	private static function is_ours( string $route ): bool {
		return self::ROUTE_PREFIX === $route || str_starts_with( $route, self::ROUTE_PREFIX . '/' );
	}

	/**
	 * Whether a registered callback is one of the checks to lift.
	 *
	 * @param mixed $callback A hook callback.
	 */
	private static function is_foreign( $callback ): bool {
		if ( ! is_array( $callback ) || ! isset( $callback[0], $callback[1] ) || ! is_object( $callback[0] ) ) {
			return false;
		}

		foreach ( self::FOREIGN as list( $class, $method ) ) {
			if ( 0 === strcasecmp( get_class( $callback[0] ), $class ) && $method === $callback[1] ) {
				return true;
			}
		}

		return false;
	}
}
