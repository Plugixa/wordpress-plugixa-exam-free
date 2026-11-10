<?php
/**
 * WordPress image captions, as markup the plugin can show.
 *
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns `[caption]…[/caption]` into `<figure>` + `<figcaption>` wherever authored HTML is cleaned.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS. Every editor has WordPress's "Add media" button (since 5 Nov 2026). Give an
 * image a caption in that window and WordPress inserts a SHORTCODE:
 *
 *     [caption id="attachment_7" align="aligncenter" width="300"]<img …> Figure 1[/caption]
 *
 * Nothing in this plugin runs shortcodes — a stem is cleaned by wp_kses() and shown as markup —
 * so a candidate would read the raw brackets. Measured, not assumed. Switching captions off
 * (`disable_captions`) cannot fix it: the window builds its markup in an admin-ajax request
 * that does not know which screen asked, and switching captions off for every such request
 * would take them away from other plugins' editors too.
 *
 * WHY ON `pre_kses`. Authored HTML is cleaned in thirty-odd places — on save, on render, in the
 * runner, the result page, the no-JavaScript paper, translations — always as
 * `wp_kses( $html, plugixa_exam_allowed_html( 'stem' ) )`. `pre_kses` is the one hook all of
 * them pass through, so a caption saved anywhere is right everywhere, including content saved
 * before this class existed. And kses then cleans what this produced, so nothing here widens
 * what an author may store: `figure` and `figcaption` were already allowed.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class Captions {

	public static function register_hooks(): void {
		add_filter( 'pre_kses', array( self::class, 'filter' ), 10, 2 );
	}

	/**
	 * Convert captions, but only in HTML being cleaned for this plugin's authored content.
	 *
	 * @param mixed $content      What is being cleaned.
	 * @param mixed $allowed_html The allow-list it is being cleaned against.
	 *
	 * @return mixed
	 */
	public static function filter( $content, $allowed_html ) {
		// The cheap test first: almost nothing kses sees contains a caption.
		if ( ! is_string( $content ) || ! str_contains( $content, '[caption' ) ) {
			return $content;
		}

		// Somebody else's content (a post, a comment) is none of this plugin's business.
		if ( ! is_array( $allowed_html ) || plugixa_exam_allowed_html( 'stem' ) !== $allowed_html ) {
			return $content;
		}

		return self::convert( $content );
	}

	/**
	 * `[caption …]<img …> Text[/caption]` → `<figure class="wp-caption align…"><img …><figcaption …>Text</figcaption></figure>`.
	 *
	 * The image may be wrapped in a link ("Link to: Media file"); that link is kept. A caption
	 * with no text left after the image becomes a figure with no figcaption.
	 *
	 * @param string $content Authored HTML.
	 */
	public static function convert( string $content ): string {
		return (string) preg_replace_callback(
			'/\[caption([^\]]*)\](.*?)\[\/caption\]/is',
			static function ( array $match ): string {
				$align = preg_match( '/\balign=["\']?(alignleft|alignright|aligncenter|alignnone)/i', $match[1], $found )
					? strtolower( $found[1] )
					: 'alignnone';

				$inner = trim( $match[2] );

				// The picture: an <img>, or an <a> wrapping one.
				if ( ! preg_match( '/^(<a\b[^>]*>\s*<img\b[^>]*>\s*<\/a>|<img\b[^>]*>)(.*)$/is', $inner, $parts ) ) {
					// Not the shape WordPress writes. Drop the brackets, keep what was inside.
					return $inner;
				}

				$caption = trim( $parts[2] );

				return '<figure class="wp-caption ' . $align . '">'
					. $parts[1]
					. ( '' === $caption ? '' : '<figcaption class="wp-caption-text">' . $caption . '</figcaption>' )
					. '</figure>';
			},
			$content
		);
	}
}
