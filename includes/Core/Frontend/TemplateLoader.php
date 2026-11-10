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
 * Theme-overridable frontend templates.
 *
 * A theme drops `plugixa-exam/result.php` into its own folder and it wins over the
 * plugin's. Child theme first, then parent, then the plugin — the order every WordPress
 * developer already expects, so nobody has to read this to guess it.
 */
final class TemplateLoader {

	private const THEME_DIR = 'plugixa-exam';

	/**
	 * Absolute path to the template that should be used.
	 *
	 * Falls back to the plugin's copy without checking that it exists, so a typo surfaces
	 * as a clear "failed to open stream" naming the file rather than as a silently blank
	 * section of the page.
	 *
	 * @param string $name Template file, relative to `templates/frontend/`.
	 */
	public static function locate( string $name ): string {
		// Never let a caller climb out of the template directory. Templates are named by
		// this plugin's own code today, but a shortcode attribute choosing a template is an
		// obvious future feature and this is the file that would make it a path traversal.
		$name = ltrim( str_replace( array( '..', "\0" ), '', $name ), '/' );

		$override = locate_template(
			array(
				self::THEME_DIR . '/' . $name,
			)
		);

		if ( '' !== $override ) {
			return $override;
		}

		/**
		 * Filter the resolved template path.
		 *
		 * @param string $path Absolute path.
		 * @param string $name Template name.
		 */
		return (string) apply_filters(
			'plugixa_exam_template_path',
			PLUGIXA_EXAM_PATH . 'templates/frontend/' . $name,
			$name
		);
	}

	/**
	 * Render a template with a scoped set of variables.
	 *
	 * Variables are extracted into the template's local scope rather than left in a
	 * `$args` array, because a template is read and edited by theme authors and
	 * `$exam->title` is a better thing to hand them than `$args['exam']->title`. The
	 * closure keeps the extraction out of this method's own scope.
	 *
	 * @param string               $name Template name.
	 * @param array<string, mixed> $vars Variables the template may use.
	 */
	public static function render( string $name, array $vars = array() ): string {
		$path = self::locate( $name );

		if ( ! file_exists( $path ) ) {
			return '';
		}

		$render = static function ( string $__path, array $__vars ): string {
			ob_start();

			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- deliberate: theme authors edit these templates and expect bare variable names.
			extract( $__vars, EXTR_SKIP );

			include $__path;

			return (string) ob_get_clean();
		};

		return $render( $path, $vars );
	}
}
