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
 * Deciding, before the page is built, whether it embeds any of this plugin.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE AWKWARD PART IS THE TIMING, AND IT CANNOT BE FIXED — ONLY ANSWERED HONESTLY.
 *
 * `wp_enqueue_scripts` runs BEFORE `the_content`, so nothing has rendered when the plugin
 * has to decide whether to load a stylesheet and 300 KB of application. The only thing
 * available at that point is the raw content of the posts this request is about, so that is
 * what gets scanned.
 *
 * ⚠️ IT USED TO SCAN `get_post()` AND NOTHING ELSE, WHICH IS RIGHT ON A PAGE AND WRONG IN A
 * LOOP. On an archive, `get_post()` at enqueue time is whichever post the global pointer
 * happens to be resting on — so a blog index whose THIRD post embeds a catalogue answered
 * "no" and rendered it unstyled, while one whose first post did answered "yes" for all ten.
 * The behaviour depended on position in a list, which is not a rule anybody could have
 * guessed from the symptom.
 *
 * Scanning `$wp_query->posts` covers both: it is the single post on a singular request and
 * every post on an archive.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * WHAT IS STILL INVISIBLE, and is a documented limitation rather than an oversight: a
 * shortcode in a widget, a block template part, or a page builder's own storage. WordPress
 * offers no way to ask "will anything on this page contain X" before the page is built.
 * Those cases opt in by returning true from `plugixa_exam_needs_frontend_assets`, which is
 * exactly why it is a filter.
 *
 * Enqueuing everywhere instead would be the lazy fix and the wrong one — it is a stylesheet
 * and an application on every page of a site that may have no exams on it at all.
 */
final class Embeds {

	/**
	 * The posts whose content this request is going to render.
	 *
	 * @return \WP_Post[]
	 */
	public static function posts(): array {
		global $wp_query;

		/*
		 * ⚠️ `posts` IS NULL BEFORE THE QUERY HAS RUN, NOT AN EMPTY ARRAY, and `array() !==
		 * null` is perfectly true — so a guard that only compared against `array()` let null
		 * straight through to `array_filter()` and fataled. Reached whenever this is asked
		 * before the main query, which is most of the time on a request that is not a page.
		 */
		if ( $wp_query instanceof \WP_Query && is_array( $wp_query->posts ) && array() !== $wp_query->posts ) {
			return array_values(
				array_filter(
					$wp_query->posts,
					static fn ( $post ): bool => $post instanceof \WP_Post
				)
			);
		}

		$post = get_post();

		return $post instanceof \WP_Post ? array( $post ) : array();
	}

	/**
	 * Whether any of them carries one of these shortcodes.
	 *
	 * @param string[] $tags Shortcode tags to look for.
	 */
	public static function has_any( array $tags ): bool {
		foreach ( self::posts() as $post ) {
			foreach ( $tags as $tag ) {
				if ( has_shortcode( $post->post_content, (string) $tag ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Whether any of them carries a shortcode that mounts one of the application's views.
	 *
	 * ⚠️ THE VIEW HAS TO BE ONE THE BUNDLE CAN ACTUALLY DRAW. A tag whose view has no
	 * component yet is a tag that would download the whole application so that `main.tsx`
	 * can look it up, find nothing and return — see {@see AppAssets::APP_VIEWS}.
	 *
	 * @param array<string, string> $tags Tag => the view it mounts.
	 */
	public static function boots_app( array $tags ): bool {
		foreach ( $tags as $tag => $view ) {
			if (
				in_array( $view, AppAssets::APP_VIEWS, true )
				&& self::has_any( array( $tag ) )
			) {
				return true;
			}
		}

		return false;
	}
}
