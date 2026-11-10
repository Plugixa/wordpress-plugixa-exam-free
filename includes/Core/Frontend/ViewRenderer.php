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
 * One view, emitted the same way from a route and from a shortcode.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE MOUNT CONTRACT USED TO BE TWO DOCUMENT-UNIQUE IDS, AND THAT HELD ONLY WHILE A ROUTE
 * OWNED THE PAGE.
 *
 * `parts/view-open.php` printed `id="pxe-view"` and `parts/view-close.php` printed
 * `id="pxe-view-state"`, and `app/main.tsx` read both with `getElementById`. Correct for
 * `/my-results/`, where the plugin renders the whole page and there is exactly one of each.
 *
 * The moment a site owner puts two of these on a page of their own — a dashboard with the
 * catalogue above their results, which is the ordinary reason to use a shortcode — the
 * second mount is handed the FIRST one's state. `getElementById` returns the first match in
 * the document and reports no error whatever: the browser is not confused, the markup is
 * valid HTML5's idea of a duplicate id, and the page renders somebody's catalogue twice.
 *
 * So a mount is now found by ATTRIBUTE and names its own state tag:
 *
 *     <div class="pxe-view" data-pxe-view="results" data-pxe-state="pxe-view-state-2" …>
 *     …followed by its JSON state: a script element of type `application/json` with
 *     id="pxe-view-state-2", printed through `wp_print_inline_script_tag()`.
 *
 * The id on the script is still an id because that is what `getElementById` wants and a
 * state tag genuinely is unique — it is simply numbered now. The counter is per REQUEST,
 * not per page in any stored sense, which is all it has to be: the numbers only have to
 * agree between the two halves of one render.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * WHY THIS IS A CLASS AND NOT MORE TEMPLATE. The markup still lives in the two parts, so a
 * theme overriding them still wins. What lives here is the NUMBERING and the ORDER — open,
 * fallback, state — which a shortcode and a routed template must agree on exactly, and
 * which neither should be able to get subtly wrong on its own.
 */
final class ViewRenderer {

	/**
	 * How many mounts this request has emitted.
	 *
	 * Static, and deliberately not reset: a shortcode rendered inside `the_content` and a
	 * routed template rendered by `template_include` are the same request, and two mounts
	 * that both called themselves number one would collide exactly as the ids did.
	 */
	private static int $instances = 0;

	/** Claim the next mount number. */
	public static function next(): int {
		++self::$instances;

		return self::$instances;
	}

	/**
	 * The number most recently claimed.
	 *
	 * `parts/view-close.php` falls back to this when nothing passed it one — the close
	 * always immediately follows its own open in document order, so the last number claimed
	 * is the one it belongs to. It exists for a theme's copy of the part taken before the
	 * numbering did, which would otherwise emit a state tag no mount names.
	 */
	public static function current(): int {
		return max( 1, self::$instances );
	}

	/**
	 * The DOM id of the state tag belonging to a mount.
	 *
	 * @param int $instance The mount number.
	 */
	public static function state_id( int $instance ): string {
		return 'pxe-view-state-' . max( 1, $instance );
	}

	/**
	 * The opening markup for a mount.
	 *
	 * @param string $view     The route name — `catalogue`, `exam`, `results`, …
	 * @param int    $instance The mount number, from {@see self::next()}.
	 */
	public static function open( string $view, int $instance ): string {
		return TemplateLoader::render(
			'parts/view-open.php',
			array(
				'pxe_view'     => $view,
				'pxe_instance' => $instance,
			)
		);
	}

	/**
	 * The closing markup, and the state the view renders from.
	 *
	 * @param array<string, mixed> $state    Everything the view draws.
	 * @param int                  $instance The mount number this closes.
	 */
	public static function close( array $state, int $instance ): string {
		return TemplateLoader::render(
			'parts/view-close.php',
			array(
				'pxe_state'    => $state,
				'pxe_instance' => $instance,
			)
		);
	}

	/**
	 * A whole view as a string: mount, the server's own copy, state.
	 *
	 * ⚠️ THE FALLBACK IS RENDERED FROM `$state`, NOT ALONGSIDE IT. Every caller passes the
	 * array it just built and the variables that array was projected into; a caller that
	 * loaded the data twice — once for the markup, once for the JSON — is how a review
	 * screen ends up disagreeing with itself about whether an answer was right.
	 *
	 * @param string               $view  The route name.
	 * @param array<string, mixed> $state Everything the view draws.
	 * @param string               $part  Template part rendering the server's own copy.
	 * @param array<string, mixed> $vars  Variables that part reads.
	 */
	public static function render( string $view, array $state, string $part, array $vars = array() ): string {
		return self::around( $view, $state, TemplateLoader::render( $part, $vars ) );
	}

	/**
	 * The same, for a view whose server copy is several parts rather than one.
	 *
	 * The dashboard renders three — assignments, results, certificates — and a caller that
	 * concatenated them and then built the mount by hand would be the fourth place in this
	 * plugin to write that contract. `result.php` was the third, and it drifted.
	 *
	 * @param string               $view   The route name.
	 * @param array<string, mixed> $state  Everything the view draws.
	 * @param string               $markup The server's own copy, already rendered.
	 */
	public static function around( string $view, array $state, string $markup ): string {

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * A VIEW THE BUNDLE CANNOT DRAW GETS NO MOUNT AND NO STATE, AND THAT IS THE POINT.
		 *
		 * The mount is an instruction to React and the state tag is the data it reads. On a
		 * view with no component, both are bytes nothing will ever look at — and the state
		 * is not a token amount: a learner with fifty results would be sent all fifty twice,
		 * once as the markup they can read and once as JSON for a component that does not
		 * exist.
		 *
		 * Asking `APP_VIEWS` here rather than at every call site means a view lights up
		 * everywhere the moment its component lands, and `AppBundleTest` already forces that
		 * list to agree with `main.tsx`. Until then the server's own markup IS the view,
		 * which is what it has always been with scripting off.
		 * ════════════════════════════════════════════════════════════════════════════════
		 */
		if ( ! in_array( $view, AppAssets::APP_VIEWS, true ) ) {
			return $markup;
		}

		$instance = self::next();

		return self::open( $view, $instance ) . $markup . self::close( $state, $instance );
	}
}
