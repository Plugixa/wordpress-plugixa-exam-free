<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Admin;

use PlugixaExam\Core\App\Bundle;
use PlugixaExam\Core\App\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts the application on the wp-admin exam screen, and takes wp-admin off it.
 *
 * WHAT IS HERE IS EVERYTHING THAT IS ABOUT WP-ADMIN, and only that. Loading the bundle and
 * injecting its configuration live in `Core\App\Bundle` and `Core\App\Config`, because
 * those are facts about the app and will be needed again the moment it mounts on a
 * front-end page.
 *
 * The style work below must NOT be reused anywhere else: every entry is an answer to a
 * specific *wp-admin* stylesheet. On a page where those stylesheets are not loaded, these
 * rules have nothing to fight — and a rule with nothing to fight is not harmless here,
 * because anything this file says, MUI loses.
 */
final class Assets {

	/**
	 * Marks the `<body>` of the exam screen, so a rule can reach MUI's portals.
	 */
	private const BODY_CLASS = 'plugixa-exam-screen';

	/**
	 * Excludes the classic editor's own controls from a rule meant for MUI's.
	 *
	 * See {@see reset_admin_chrome()} for why the focus-ring reset must not reach them.
	 */
	private const NOT_IN_EDITOR = ':not(.wp-editor-wrap *)';

	/**
	 * wp-admin stylesheets this screen drops.
	 *
	 * These style screens this page does not render: there is no list table here, no post
	 * editor, no media library, no widget or menu editor. They are dead weight — and
	 * `forms` is worse than dead weight, it is actively wrong. See
	 * {@see strip_unused_admin_styles()}.
	 *
	 * @var string[]
	 */
	private const DROP_STYLES = array(
		'forms',
		'dashboard',
		'list-tables',
		'edit',
		'revisions',
		'media',
		'themes',
		'about',
		'nav-menus',
		'widgets',
		'wp-pointer',
		'media-views',
	);

	/**
	 * The hook suffix `add_menu_page` returned, shared from {@see Admin}.
	 *
	 * Static because `Admin` and `Assets` are separate objects registered independently,
	 * and the alternative — passing one into the other — makes the constructor order
	 * load-bearing for no gain.
	 */
	private static string $own_hook = '';

	/**
	 * Whether the current request is the exam screen. Set by {@see enqueue()}.
	 */
	private bool $on_exam_screen = false;

	/**
	 * @param string $hook_suffix What `add_menu_page` returned.
	 */
	public static function own_hook( string $hook_suffix ): void {
		self::$own_hook = $hook_suffix;
	}

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'print_styles_array', array( $this, 'strip_unused_admin_styles' ) );
		add_filter( 'admin_body_class', array( $this, 'mark_body' ) );
	}

	/**
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue( string $hook_suffix ): void {
		// Exact match, not `str_contains`. Freemius registers pricing, account and contact
		// pages whose hook suffixes also contain the plugin slug; mounting a megabyte of
		// React onto the pricing page is slow and visibly wrong.
		if ( '' === self::$own_hook || $hook_suffix !== self::$own_hook ) {
			return;
		}

		$this->on_exam_screen = true;

		Bundle::enqueue();
		Config::inject( 'admin' );
		$this->reset_admin_chrome();

		/*
		 * WORDPRESS'S OWN EDITOR, RATHER THAN ONE BUNDLED INTO THE APP.
		 *
		 * A question stem, an explanation and an exam's intro are all stored as HTML — written
		 * through `wp_kses( …, plugixa_exam_allowed_html( 'stem' ) )` and rendered as markup by the
		 * runner — so the author needs an editor rather than a textarea full of angle brackets.
		 *
		 * This is the same call as `wp_mail` over an SMTP client, and for the same reasons. A React
		 * editor (TipTap, Lexical) costs two to four hundred kilobytes on a bundle already over a
		 * megabyte, while this costs about 1.3 kB because TinyMCE is on every admin page already.
		 * The author knows it — it is what they write posts in. And its output is the subset the
		 * kses allow-list accepts on the way back in, which a third-party editor would have to be
		 * taught; every gap between the two is markup silently stripped on save.
		 *
		 * Media buttons are off at the call site in `shared/components/RichTextField.tsx`, because
		 * `media` and `media-views` are both in DROP_STYLES and the modal would open unstyled.
		 */
		wp_enqueue_editor(
			array(
				'tinymce'   => true,
				'quicktags' => true,
			)
		);

		/**
		 * Fires on the exam screen, once the app bundle is enqueued.
		 *
		 * @param string $hook_suffix The admin page being rendered.
		 */
		do_action( 'plugixa_exam_enqueue_assets', $hook_suffix );
	}

	/**
	 * Put a class on the exam screen's `<body>`.
	 *
	 * Not decoration. MUI renders every dialog, menu, select popup and tooltip through a
	 * Portal into `document.body` — OUTSIDE `#plugixa-exam-root`. A rule scoped to the
	 * app's root therefore reaches the page and misses every dialog on it, which is exactly
	 * how a stray focus ring survives on the first field of every create form while the
	 * page behind it looks fine.
	 *
	 * `admin_body_class` is printed after `admin_enqueue_scripts` has run, so the flag
	 * {@see enqueue()} sets is already true by the time this is asked.
	 *
	 * @param string $classes Existing body classes.
	 */
	public function mark_body( string $classes ): string {
		return $this->on_exam_screen ? trim( $classes . ' ' . self::BODY_CLASS ) : $classes;
	}

	/**
	 * Take wp-admin's unused stylesheets off this one screen.
	 *
	 * WHY THIS IS A REMOVAL AND NOT A RESET. Do not "fix" it back into one.
	 *
	 * WordPress styles bare form elements — `input[type=text]`, `select`, `textarea` — at
	 * specificity (0,1,1), and their `:focus` state at (0,2,1). Every MUI rule is a single
	 * Emotion class, (0,1,0). WordPress therefore wins on every field in the app, by
	 * construction:
	 *
	 *     WORDPRESS  input[type="text"]  (0,1,1)  padding: 0 12px      <-- applied
	 *     emotion    .css-aae3xl         (0,1,0)  padding: 4px 0 5px   <-- lost
	 *
	 * and likewise on min-height, border, border-radius, background-color, color and margin.
	 *
	 * A reset cannot win this. Any selector specific enough to beat (0,1,1) is ALSO more
	 * specific than MUI's (0,1,0) — so a rule that neutralises WordPress's padding clobbers
	 * MUI's padding in the same breath, and the field ends up with neither. There is no
	 * specificity that sits between the two. The conflict has to be removed, not out-shouted.
	 *
	 * And it has to be removed HERE. `wp-admin` is a meta handle that DEPENDS on `forms`,
	 * so `wp_dequeue_style('forms')` silently does nothing — dependency resolution puts it
	 * straight back. `print_styles_array` fires inside `WP_Styles::all_deps()`, AFTER
	 * resolution, on the final list of handles about to be printed. A handle removed here
	 * is removed even though something depends on it, and no core object is mutated to do it.
	 *
	 * @param string[] $handles Handles WordPress is about to print.
	 *
	 * @return string[]
	 */
	public function strip_unused_admin_styles( array $handles ): array {
		if ( ! $this->on_exam_screen ) {
			return $handles;
		}

		return array_values( array_diff( $handles, self::DROP_STYLES ) );
	}

	/**
	 * Strip the padding wp-admin puts around page content, and fix the two things that
	 * survive the strip.
	 *
	 * THIS IS DELIBERATELY SMALL, AND IT MUST STAY THAT WAY. Every selector below is either
	 * an ID or a body class, and every MUI rule is a single Emotion class, (0,1,0). Anything
	 * this file says, MUI loses.
	 *
	 * So the test for adding a line here is not "does this look right" — it is: **what
	 * wp-admin rule is this beating, and can that rule be removed instead?** Removing it is
	 * nearly always correct.
	 */
	private function reset_admin_chrome(): void {
		$body = 'body.' . self::BODY_CLASS;

		wp_register_style( 'plugixa-exam-admin', false, array(), PLUGIXA_EXAM_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_style( 'plugixa-exam-admin' );
		wp_add_inline_style(
			'plugixa-exam-admin',
			// Admin notices are deliberately left alone: suppressing them would hide
			// genuine WordPress and other-plugin warnings from the user.
			'#wpcontent{padding-left:0}'
			. '#wpbody-content{padding-bottom:0}'
			. '#wpfooter{display:none}'
			. '#' . Bundle::ROOT_ID . '{margin:0}'
			// THE FOCUS RING. This one is not in `forms` and cannot be stripped with it.
			//
			// The admin COLOUR SCHEME (wp-admin/css/colors/*/colors.css) carries its own
			// copy of the rule, in the scheme's own blue:
			//
			//     input[type=text]:focus, …, textarea:focus {
			//         box-shadow: 0 0 0 1.5px var(--wp-admin-theme-color);
			//     }                                              (0,2,1)
			//
			// and that stylesheet paints the admin menu and bar, so it stays. MUI shows
			// focus by thickening the fieldset it draws BEHIND the input and puts no
			// box-shadow on the input itself — so WordPress's ring is not overriding
			// anything, it is simply drawn on top, and the field ends up with both.
			//
			// Scoped to the BODY, not to the app root: every dialog, menu and select popup
			// is portaled into document.body, outside it. A root-scoped rule fixes the page
			// and misses every form on it. (0,2,2) beats the colour scheme's (0,2,1).
			//
			// box-shadow only. WordPress also sets `border-color` here, but MUI's input has
			// no border to colour, and resetting one we cannot see would mean overriding
			// something MUI might want later.
			//
			// EXCEPT INSIDE THE CLASSIC EDITOR, which is on this screen since the rich text
			// fields landed. `.wp-editor-wrap` holds the quicktags toolbar — whose buttons are
			// `input[type=button]` — and the Text-mode textarea, and those are wp-admin's own
			// controls wearing wp-admin's own styling. Taking their focus ring away leaves the
			// toolbar with no visible focus at all, which is the one thing this rule exists to
			// prevent elsewhere. `:not()` takes the specificity of its argument, so the selector
			// is (0,3,2) and still beats the colour scheme's (0,2,1).
			//
			// TinyMCE's visual mode needs no exception: it renders into an IFRAME, a separate
			// document that an inline style on this page cannot reach. That is also why the
			// heading rule below is left alone — the h2 an author creates with the format
			// dropdown lives in that iframe, not in this document.
			. $body . ' input' . self::NOT_IN_EDITOR . ':focus,'
			. $body . ' select' . self::NOT_IN_EDITOR . ':focus,'
			. $body . ' textarea' . self::NOT_IN_EDITOR . ':focus'
			. '{box-shadow:none}'
			// `common.css` is still loaded — the admin menu and the notices need it — and it
			// hard-codes a near-black colour on h1–h6. MUI's Typography only *inherits*
			// colour, so a bare element rule beats it and every heading sits near-black,
			// which is invisible on a dark theme.
			//
			// Body-scoped for the same reason as above: a DialogTitle is an <h2>, and it is
			// portaled. Colour only — weight, size and margin belong to the theme.
			. $body . ' h1,' . $body . ' h2,' . $body . ' h3,'
			. $body . ' h4,' . $body . ' h5,' . $body . ' h6{color:inherit}'
		);
	}
}
