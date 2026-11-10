<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Frontend;

use PlugixaExam\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end styles and the runner bundle.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE MIRROR IMAGE OF THE ADMIN PROBLEM, AND IT NEEDS THE OPPOSITE SOLUTION.
 *
 * In wp-admin the enemy is a known list of core stylesheets, so `Core\Admin\Assets` REMOVES
 * them. Out here the enemy is "every theme and every other plugin", which is not a list
 * anybody can write down — so nothing is removed. The exam UI is scoped instead: everything
 * lives inside `.pxe`, every value comes from a `--pxe-*` custom property, and no rule
 * reaches outside that container.
 *
 * ⚠️ THIS FILE USED TO SAY "NO MUI, AND NO TAILWIND, ON THIS SIDE", AND HAD BEEN WRONG FOR
 * MONTHS. MUI arrived on the runner and the portal in the 21 Aug pass and is now on every
 * routed view; Tailwind was considered and deliberately rejected, so half the sentence was
 * stale and half was a decision recorded in the wrong place. Both are recorded properly now:
 * the runner and the portal ship `@mui/material`, and nothing in this plugin ships Tailwind
 * — a namespaced Tailwind under `.pxe` has to run with preflight off, which means inheriting
 * the host theme's resets, which is the thing the scoping exists to escape.
 *
 * The BUDGET argument the old sentence was really making still stands, and is why the
 * bundle is split per view: an examinee may be on a phone in a room with thirty other people
 * on the same wifi, and the paper has to be usable before the certification body's deadline.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class AppAssets {

	public const HANDLE = 'plugixa-exam-frontend';

	/**
	 * The custom property each colour setting is published as.
	 *
	 * Every setting named here is one of {@see \PlugixaExam\Core\Settings::COLOURS}, which
	 * is what makes it a hex colour on the way in; `palette()` checks again on the way out.
	 */
	public const PALETTE = array(
		'--pxe-primary'          => 'color_primary',
		'--pxe-primary-contrast' => 'color_primary_contrast',
		'--pxe-accent'           => 'color_accent',
		'--pxe-bg'               => 'color_bg',
		'--pxe-text'             => 'color_text',
		'--pxe-correct'          => 'color_correct',
		'--pxe-incorrect'        => 'color_incorrect',
	);

	/**
	 * @param Router   $router   For deciding which view is being rendered.
	 * @param Settings $settings For the colour palette.
	 */
	public function __construct(
		private readonly Router $router,
		private readonly Settings $settings
	) {}

	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Put the stylesheet on any exam page, and the runner bundle only where it is needed.
	 */
	public function enqueue(): void {
		$view = $this->router->view();

		/**
		 * Whether this request needs the exam front-end assets.
		 *
		 * A shortcode on an arbitrary page has no rewrite view, so it hooks this to opt in.
		 *
		 * @param bool   $needed Whether to enqueue.
		 * @param string $view   The resolved rewrite view, or an empty string.
		 */
		$needed = (bool) apply_filters( 'plugixa_exam_needs_frontend_assets', '' !== $view, $view );

		if ( ! $needed ) {
			return;
		}

		$this->enqueue_styles();

		/**
		 * Which bundle this request boots, if any.
		 *
		 * ⚠️ A SHORTCODE HAS NO VIEW, AND THAT USED TO END THE MATTER. The lookup below is
		 * keyed on the rewrite view, so an embedded view got the stylesheet and no
		 * JavaScript at all — the filter above opted a page into the CSS and there was
		 * nothing to opt into the bundle. Which was correct for exactly as long as an embed
		 * meant a static list of cards, and stopped being correct the moment a site owner
		 * could put the application itself on a page of their own.
		 *
		 * @param string $entry The entry name, or '' for none.
		 * @param string $view  The resolved rewrite view, or an empty string.
		 */
		$entry = (string) apply_filters( 'plugixa_exam_frontend_entry', self::entry( $view ), $view );

		if ( '' !== $entry ) {
			/*
			 * `pxe--embed` when there is no view: the shell payload keys off the modifier,
			 * and a page carrying two embedded views has no single view to name. Each mount
			 * carries its own in `data-pxe-view`, which is the only answer that can be right
			 * on such a page.
			 */
			$this->enqueue_bundle( $entry, '' !== $view ? self::modifier( $view ) : 'pxe--embed' );
		}
	}

	/**
	 * The entry a routed view boots.
	 *
	 * @param string $view The resolved rewrite view, or an empty string.
	 */
	private static function entry( string $view ): string {
		if ( isset( self::ENTRIES[ $view ] ) ) {
			return self::ENTRIES[ $view ];
		}

		return in_array( $view, self::APP_VIEWS, true ) ? 'app' : '';
	}

	/**
	 * The views `src/app/main.tsx` has a component for.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THIS LIST AND THE `VIEWS` MAP IN `app/main.tsx` ARE THE SAME LIST TWICE, AND A TEST
	 * READS THE TYPESCRIPT TO PROVE IT.
	 *
	 * They cannot be one list — PHP decides whether to enqueue 300 KB of React before a
	 * single byte of the bundle has loaded, so it has to know the answer without asking.
	 * But two lists that must agree and are never compared is how a page ends up
	 * downloading the whole application so that `main.tsx` can look up a view it does not
	 * have and return.
	 *
	 * Naming a view here BEFORE its component exists is the expensive direction, and it is
	 * the one that looks harmless: everything still works, and two pages that carry no
	 * JavaScript today start paying for all of it.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @var string[]
	 */
	public const APP_VIEWS = array( 'result' );

	/**
	 * Which bundle each routed view boots, if any.
	 *
	 * ⚠️ THREE ENTRIES, NOT ONE, AND NOT ONE PER VIEW EITHER.
	 *
	 * `runner` stands alone because a paper in progress must not carry a byte it does not
	 * need — and because its premium half (`@pro`) is nine question-type controls, the
	 * practice check and a 260 KB maths renderer, none of which a result page has any use
	 * for. Rollup would put all of it in a shared chunk the moment the two entries imported
	 * the same premium door.
	 *
	 * `app` covers the routed views, which share the theme, the number formatter and the
	 * shell's tokens; the views themselves are `React.lazy()` inside it, so a result page
	 * fetches the result chunk and not the catalogue's.
	 *
	 * `portal` is still its own entry, for now. It is already React, already MUI, and has a
	 * working premium seam (`@pro-portal`) with a registry and tests behind it. Folding it
	 * in is a migration, not a rename, and does not belong in the same change as a new view.
	 *
	 * ⚠️ A VIEW IS ADDED HERE WHEN ITS COMPONENT EXISTS, NOT WHEN ITS TURN COMES. Listing
	 * `catalogue` and `exam` before `src/app/views/` had them would have shipped the shared
	 * 300 KB React chunk to two pages that carry no JavaScript at all today, so that
	 * `main.tsx` could look up an entry it does not have and return. `verify` and `monitor`
	 * may never appear: a certificate check read by a stranger with a QR code, and an
	 * invigilation board that polls its own markup, have nothing React would add.
	 */
	private const ENTRIES = array(
		'take'   => 'runner',
		'portal' => 'portal',
	);

	/**
	 * The scope modifier a view renders under.
	 *
	 * ⚠️ `take` IS THE ONE THAT IS NOT ITS OWN NAME. `take.php` has always set
	 * `pxe--runner`, and `parts/header.php` still reads that exact string to set the
	 * runner's body class, so it cannot simply be renamed to match the route. It no longer
	 * decides whether the theme's chrome appears — {@see Chrome} answers that from the
	 * THEME, not the view — but the literal is still load-bearing. Every other view is
	 * `pxe--` and the view name.
	 *
	 * @param string $view The resolved rewrite view.
	 */
	public static function modifier( string $view ): string {
		return 'take' === $view ? 'pxe--runner' : 'pxe--' . $view;
	}

	/**
	 * The scoped stylesheet, plus the palette as custom properties.
	 */
	private function enqueue_styles(): void {
		$tree = \PlugixaExam\Core\App\Bundle::tree( 'frontend/build/' );
		$css  = $tree['path'] . 'css/frontend.css';

		if ( file_exists( $css ) ) {
			wp_enqueue_style(
				self::HANDLE,
				$tree['url'] . 'css/frontend.css',
				array(),
				(string) filemtime( $css )
			);
		} else {
			// The stylesheet is built from source; registering a src-less handle keeps the
			// inline variables below working on a checkout that has not run the build yet.
			wp_register_style( self::HANDLE, false, array(), PLUGIXA_EXAM_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			wp_enqueue_style( self::HANDLE );
		}

		wp_add_inline_style( self::HANDLE, $this->palette() );
	}

	/**
	 * The colour settings, as CSS custom properties on the scope root.
	 *
	 * Custom properties rather than compiled values so that an AJAX-loaded fragment restyles
	 * itself without the server having to inline a stylesheet block into every response.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * EVERY VALUE IS A HEX COLOUR BY THE TIME IT IS WRITTEN, CHECKED HERE AND NOT ASSUMED.
	 *
	 * This used to say `esc_attr( $value )`, under a comment explaining that a stray `}`
	 * would otherwise let a value close the rule and write its own. The comment was right
	 * about the danger and wrong about the cure: `esc_attr` is for an HTML attribute, it
	 * encodes `<`, `>`, `&` and quotes, and none of `;`, `{` or `}` is any of those.
	 * `red;}body{display:none` came out exactly as it went in, as a second rule. In a
	 * stylesheet the things to keep out are the punctuation of CSS, and no HTML escaper
	 * knows about them.
	 *
	 * So the value is not escaped, it is VALIDATED: `sanitize_hex_color()` returns `#rgb`
	 * or `#rrggbb` or nothing, and a string that is only a hash and hex digits has no
	 * punctuation in it to misuse. {@see \PlugixaExam\Core\Settings::save()} refuses
	 * anything else on the way in; this asks again on the way out, because a value written
	 * by an older build, or straight into the option, never went through that door.
	 *
	 * A setting that fails is skipped, and the stylesheet's own default for that property
	 * applies.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 */
	private function palette(): string {
		$declarations = '';

		foreach ( self::PALETTE as $property => $setting ) {
			$colour = sanitize_hex_color( $this->settings->str( $setting, '' ) );

			if ( null === $colour || '' === $colour ) {
				continue;
			}

			$declarations .= $property . ':' . $colour . ';';
		}

		return '' === $declarations ? '' : '.pxe{' . $declarations . '}';
	}

	/**
	 * Enqueue one of the front-end bundles.
	 *
	 * @param string $entry    Vite entry name.
	 * @param string $modifier Scope modifier of the page, for the shell payload.
	 */
	private function enqueue_bundle( string $entry, string $modifier = '' ): void {
		$tree     = \PlugixaExam\Core\App\Bundle::tree( 'frontend/build/' );
		$manifest = $tree['path'] . '.vite/manifest.json';

		if ( ! file_exists( $manifest ) ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a build artefact from the plugin's own directory.
		$decoded = json_decode( (string) file_get_contents( $manifest ), true );
		$chunk   = is_array( $decoded ) ? ( $decoded[ 'src/' . $entry . '/main.tsx' ] ?? null ) : null;

		if ( ! is_array( $chunk ) || empty( $chunk['file'] ) ) {
			return;
		}

		/*
		 * No `?ver=` — the filename hash IS the cache-buster. A code-split chunk imports the
		 * entry back by relative path with no query string, so a version query makes the
		 * browser load the module twice: two Reacts, and React #321 on the first hook call.
		 * See `Core\App\Bundle` for the long version.
		 */
		wp_enqueue_script_module( self::HANDLE . '-' . $entry, $tree['url'] . $chunk['file'], array(), null );

		/*
		 * The strings the bundle will need, on the page beside it.
		 *
		 * Here rather than in the templates because this is the one place that knows a
		 * React bundle is actually being served — `take.php` renders the same markup with
		 * or without it, and a template that printed the map itself would put it on pages
		 * where nothing reads it.
		 *
		 * Strings only. See `Core\App\FrontendStrings` for why this is not
		 * `Config::inject( 'frontend' )`, which would publish the site's permission matrix
		 * on a page a guest can open.
		 */
		\PlugixaExam\Core\App\FrontendStrings::inject( $modifier );
	}
}
