<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Frontend;

use PlugixaExam\Core\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where each front-end view lives, for the client that has to find it.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THIS CLASS USED TO BE A NAVIGATION MENU, AND MOST OF IT WENT WITH THE SIDEBAR.
 *
 * It composed the application rail — groups, items, glyphs, the brand, a "Manage" shortcut
 * and a sign-in link — and `parts/nav.php` rendered exactly the list it published, so the
 * markup a reader saw before the bundle booted and the payload the bundle replaced it with
 * could not disagree. That mattered while the plugin owned its own navigation.
 *
 * The exam area is pages the site owner puts in their own menu now, so the plugin no longer
 * ships one, and every one of those members had exactly one caller: a template that no
 * longer exists. What is left is the part that was never about drawing anything — telling
 * the client where each view lives, so it does not have to rebuild URLs it does not own.
 *
 * ⚠️ TWO PUBLIC FILTERS WENT WITH THEM. `plugixa_exam_frontend_nav` shaped the item list and
 * `plugixa_exam_frontend_brand` named the area; both fired only from members deleted here.
 * They are gone rather than kept as dead hooks: a filter that still fires but changes
 * nothing is worse than one that is honestly absent, because a site owner debugging it has
 * no way to tell.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ NOTHING PRIVILEGED MAY BE ADDED HERE. This is inlined into the public HTML of every
 * exam page, including pages a guest can read. It is not, and must not become, a second
 * `Core\App\Config`.
 */
final class ShellData {

	/**
	 * What the bundle is told about this request.
	 *
	 * @param string $modifier The scope modifier of the page being rendered.
	 *
	 * @return array<string, mixed>
	 */
	public static function payload( string $modifier = '' ): array {
		return array(
			'view'   => self::view( $modifier ),
			'routes' => self::routes( self::router() ),
		);
	}

	/**
	 * The URL pattern for each routed view.
	 *
	 * Built from the router's own link builders rather than written out, so a filtered base
	 * and a language prefix are both already in them. The two parameterised ones are derived
	 * by surgery on a known-good URL — `{slug}` and `{id}` are placeholders the client fills.
	 *
	 * ⚠️ `take` AND `verify` ARE DELIBERATELY ABSENT. The runner is a separate document with
	 * no shell, and a certificate check is read by a stranger who has no application around
	 * them; both must be a real navigation, not a view swap.
	 *
	 * @param Router $router Route helper.
	 *
	 * @return array<string, string>
	 */
	private static function routes( Router $router ): array {
		/*
		 * ⚠️ SENTINEL SUBSTITUTION, NOT STRING SURGERY ON THE TAIL — AND THE FIRST DRAFT DID
		 * THE SURGERY.
		 *
		 * It built the exam route as `catalogue_url() . '{slug}/'` and the result route by
		 * chopping the last two characters off `result_url( 0 )`. Both assume the URL ENDS
		 * with the part being replaced, which is true only until something appends a query
		 * string — and `plugixa_exam_frontend_url` is exactly the seam a language plugin
		 * hooks to do that. Measured against a filter adding `?lang=fr`: the result route
		 * came out `…/result/0/?lang={id}/`, having eaten the `fr`, and the exam route came
		 * out `…/exams/?lang=fr{slug}/`. A test asserting only that the language survived
		 * caught the first and would have shipped the second.
		 *
		 * Passing a placeholder through the builder directly does not work either — it
		 * `rawurlencode`s `{slug}` into `%7Bslug%7D`. So a value that is URL-safe, cannot
		 * occur naturally, and survives encoding goes in, and comes back out.
		 */
		$slug = 'pxeSlugSentinel';
		$id   = 987654321;

		return array(
			'catalogue'    => $router->catalogue_url(),
			'exam'         => str_replace( $slug, '{slug}', $router->exam_url( $slug ) ),
			'result'       => str_replace( (string) $id, '{id}', $router->result_url( $id ) ),
			'portal'       => $router->portal_url(),
			'results'      => $router->results_url(),
			'certificates' => $router->certificates_url(),
		);
	}

	/**
	 * The route name behind a scope modifier.
	 *
	 * ⚠️ ONE MODIFIER IS NOT ITS OWN ROUTE. `take.php` sets `pxe--runner`, and
	 * `parts/header.php` still reads that literal string to set the runner's body class, so
	 * it cannot be renamed to match the route — the mapping has to live somewhere, and here
	 * is where the two vocabularies already meet. Every other modifier is `pxe--` and its
	 * route name.
	 *
	 * @param string $modifier Scope modifier, e.g. `pxe--result`.
	 */
	private static function view( string $modifier ): string {
		if ( 'pxe--runner' === $modifier ) {
			return 'take';
		}

		return str_starts_with( $modifier, 'pxe--' ) ? substr( $modifier, 5 ) : $modifier;
	}

	/**
	 * The router, the same way `nav.php` has always found it.
	 *
	 * Through `ViewData` rather than directly, because that is the object every template
	 * already holds and it owns the accessor.
	 */
	private static function router(): Router {
		return Plugin::instance()->container()->get( ViewData::class )->router();
	}
}
