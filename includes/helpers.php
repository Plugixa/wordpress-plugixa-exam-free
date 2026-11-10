<?php
/**
 * Global helper functions.
 *
 * @package PlugixaExam
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'plugixa_exam_allowed_html' ) ) {
	/**
	 * Allowed HTML for a given context.
	 *
	 * CALL THIS INLINE, AS `echo wp_kses( $html, plugixa_exam_allowed_html( 'stem' ) )`.
	 *
	 * Never wrap it in a `plugixa_exam_kses( $html )` convenience function, however
	 * tempting. The WordPress.org escaping sniff matches on the *literal* call — it cannot
	 * see through a wrapper — so every wrapped call is reported as unescaped output, and
	 * the reviewer reading the report has no way to tell the false positives from the real
	 * ones. The verbosity is the point.
	 *
	 * @param string $context Which content is being rendered.
	 *
	 * @return array<string, array<string, bool|array<string, bool>>>
	 */
	function plugixa_exam_allowed_html( string $context = 'default' ) {
		$inline = array(
			'a'      => array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			),
			'strong' => array(),
			'b'      => array(),
			'em'     => array(),
			'i'      => array(),
			'u'      => array(),
			'sub'    => array(),
			'sup'    => array(),
			'br'     => array(),
			'span'   => array(
				'class' => true,
				'style' => true,
			),
			'code'   => array( 'class' => true ),
		);

		$block = array(
			'p'          => array( 'class' => true, 'style' => true ),
			'div'        => array( 'class' => true, 'style' => true ),
			'ul'         => array( 'class' => true ),
			'ol'         => array( 'class' => true, 'start' => true ),
			'li'         => array( 'class' => true ),
			'blockquote' => array( 'class' => true, 'cite' => true ),
			'pre'        => array( 'class' => true ),
			'h1'         => array( 'class' => true ),
			'h2'         => array( 'class' => true ),
			'h3'         => array( 'class' => true ),
			'h4'         => array( 'class' => true ),
			'h5'         => array( 'class' => true ),
			'h6'         => array( 'class' => true ),
			'hr'         => array(),
			'table'      => array( 'class' => true ),
			'thead'      => array(),
			'tbody'      => array(),
			'tr'         => array(),
			'th'         => array( 'colspan' => true, 'rowspan' => true, 'scope' => true ),
			'td'         => array( 'colspan' => true, 'rowspan' => true ),
			'img'        => array(
				'src'      => true,
				'srcset'   => true,
				'sizes'    => true,
				'alt'      => true,
				'width'    => true,
				'height'   => true,
				'class'    => true,
				'loading'  => true,
				'decoding' => true,
			),
			'figure'     => array( 'class' => true ),
			'figcaption' => array( 'class' => true ),
		);

		switch ( $context ) {
			/*
			 * A question stem, an answer explanation, a pass/fail message — authored by an
			 * exam administrator in the admin editor.
			 *
			 * Deliberately NARROWER than `wp_kses_post()`. What core permits and this does
			 * not is embedded media and form controls — `video`, `audio`, `object`,
			 * `button`, `textarea` — measured against `$allowedposttags`, not assumed, and
			 * pinned by `SanitisationTest`. An exam question is read by an examinee under
			 * time pressure and often under invigilation; embedded media in a stem is at
			 * best a distraction and at worst a channel to the open web during a
			 * closed-book assessment, and a form control collides with the runner's own
			 * inputs. Video that genuinely belongs in a question goes through the media
			 * library, where it is an <img> poster and a link.
			 *
			 * NOT the iframe, however often that gets said. `$allowedposttags` has never
			 * carried one either, so "unlike wp_kses_post, no iframe" describes a
			 * difference that does not exist — three comments in this codebase claimed it
			 * before a mutation test went looking.
			 */
			case 'stem':
				$allowed = array_merge( $inline, $block );
				break;

			// Certificate templates and email bodies, which are laid out with tables.
			case 'email':
				$allowed = array_merge(
					$inline,
					$block,
					array(
						'table' => array( 'class' => true, 'width' => true, 'cellpadding' => true, 'cellspacing' => true, 'border' => true, 'style' => true ),
						'td'    => array( 'colspan' => true, 'rowspan' => true, 'align' => true, 'valign' => true, 'width' => true, 'style' => true ),
						'th'    => array( 'colspan' => true, 'rowspan' => true, 'align' => true, 'valign' => true, 'width' => true, 'style' => true, 'scope' => true ),
					)
				);
				break;

			/*
			 * A form control rendered by a module rather than by a template.
			 *
			 * EXISTS FOR THE NO-JAVASCRIPT RUNNER, and for nothing else. A module's question
			 * type has to draw its own inputs into `take.php` — a cloze passage needs one
			 * box per gap, matching needs a select per row — and the template cannot know
			 * their shape. The markup still goes through `wp_kses` on the way out, so a
			 * module cannot inject anything a form does not need.
			 *
			 * NO `on*` ATTRIBUTES ARE LISTED, and `wp_kses` strips them regardless. This is
			 * the no-JS path: if it needed an event handler it would not be the no-JS path.
			 *
			 * ⚠️ `img` AND THE TABLE TAGS WERE MISSING, WHILE THE MODULES SAID THEY WERE HERE.
			 * Picture choice and image matching put each picture in an `<img>`, and hotspot
			 * lays its square grid out as a `<table>`. Every one of those was stripped from
			 * the no-JavaScript paper — questions answered by looking at a picture, printed
			 * with no picture — and nothing failed, because stripping is silent.
			 */
			case 'form':
				$control = array( 'class' => true, 'id' => true, 'name' => true, 'disabled' => true, 'required' => true );

				$allowed = array_merge(
					$inline,
					array(
						'label'    => array( 'class' => true, 'for' => true ),
						'fieldset' => array( 'class' => true ),
						'legend'   => array( 'class' => true ),
						'ol'       => array( 'class' => true ),
						'ul'       => array( 'class' => true ),
						'li'       => array( 'class' => true, 'value' => true ),
						'div'      => array( 'class' => true ),
						'p'        => array( 'class' => true ),
						'input'    => array_merge(
							$control,
							array(
								'type'         => true,
								'value'        => true,
								'checked'      => true,
								'min'          => true,
								'max'          => true,
								'step'         => true,
								'inputmode'    => true,
								'autocomplete' => true,
								'placeholder'  => true,
								'maxlength'    => true,
								'aria-label'   => true,
							)
						),
						'select'   => array_merge( $control, array( 'aria-label' => true ) ),
						'option'   => array( 'value' => true, 'selected' => true ),
						'textarea' => array_merge( $control, array( 'rows' => true, 'aria-label' => true ) ),
						'img'      => array(
							'class'   => true,
							'src'     => true,
							'alt'     => true,
							'loading' => true,
							'width'   => true,
							'height'  => true,
						),
						'table'    => array( 'class' => true ),
						'tbody'    => array( 'class' => true ),
						'tr'       => array( 'class' => true ),
						'td'       => array( 'class' => true ),
					)
				);
				break;

			// Anything printed into a template: labels, short strings, learner-visible text.
			case 'default':
			default:
				$allowed = $inline;
				break;
		}

		/**
		 * Filter the allowed HTML for a context.
		 *
		 * @param array  $allowed Allowed tags and attributes.
		 * @param string $context The context.
		 */
		return (array) apply_filters( 'plugixa_exam_allowed_html', $allowed, $context );
	}
}

if ( ! function_exists( 'plugixa_exam_timestamp' ) ) {
	/**
	 * A stored UTC datetime as a Unix timestamp, or 0 if there isn't one.
	 *
	 * ⚠️ THE TRAP THIS EXISTS TO CLOSE: `strtotime( '' . ' UTC' )` DOES NOT RETURN FALSE.
	 *
	 * `strtotime( ' UTC' )` is a valid parse — a bare timezone means "now, there" — so it
	 * returns the CURRENT TIME. The idiom `(int) strtotime( (string) $row['expires_at'] . ' UTC' )`
	 * therefore turns a NULL column into "expires right now" rather than into 0, and every
	 * comparison downstream is then wrong in a way that looks plausible.
	 *
	 * That shipped once: a monitor board showed "0:00 left" for every untimed exam, which
	 * tells an invigilator that everybody has run out of time.
	 *
	 * @param mixed $datetime A `Y-m-d H:i:s` string in UTC, or null.
	 *
	 * @return int Unix timestamp, or 0.
	 */
	function plugixa_exam_timestamp( mixed $datetime ): int {
		if ( ! is_string( $datetime ) ) {
			return 0;
		}

		$datetime = trim( $datetime );

		// A zero date is MySQL's way of saying "nothing", and parses to a real 1970 timestamp.
		if ( '' === $datetime || str_starts_with( $datetime, '0000-00-00' ) ) {
			return 0;
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		return false === $timestamp ? 0 : (int) $timestamp;
	}
}

if ( ! function_exists( 'plugixa_exam_monitor_clock' ) ) {
	/**
	 * Seconds as a clock, e.g. `12:04` or `1:02:33`.
	 *
	 * The hour is dropped when there is none, because `00:12:04` on a twelve-minute exam
	 * reads as a stopwatch rather than as time left. Anything negative shows as zero: a
	 * countdown that goes negative in front of an invigilator looks like a fault, and the
	 * server has already decided the attempt is over.
	 *
	 * @param int $seconds Seconds.
	 */
	function plugixa_exam_monitor_clock( int $seconds ): string {
		$seconds = max( 0, $seconds );

		$hours   = intdiv( $seconds, HOUR_IN_SECONDS );
		$minutes = intdiv( $seconds % HOUR_IN_SECONDS, MINUTE_IN_SECONDS );
		$rest    = $seconds % MINUTE_IN_SECONDS;

		return $hours > 0
			? sprintf( '%d:%02d:%02d', $hours, $minutes, $rest )
			: sprintf( '%d:%02d', $minutes, $rest );
	}
}

if ( ! function_exists( 'plugixa_exam_is_valid_regex' ) ) {
	/**
	 * Whether a delimited pattern compiles.
	 *
	 * WHY THIS EXISTS RATHER THAN `@preg_match(...)`.
	 *
	 * An author can mark a short answer's accepted values as regular expressions, so a
	 * malformed one is stored user input. Left unchecked, `preg_match` emits a warning and
	 * returns `false` on every attempt — every answer marked wrong, silently, with the cause
	 * visible only in the PHP error log.
	 *
	 * The obvious fix is `@preg_match`, and it is wrong twice over: a strict error handler
	 * sees straight through the `@` (PHPUnit installs one, which is how this was caught), and
	 * suppression hides genuine problems alongside the expected one. Probing with a handler
	 * that is installed and removed around exactly one call is explicit about what is being
	 * ignored and for how long.
	 *
	 * @param string $pattern A delimited pattern, e.g. `/^[0-9]+$/i`.
	 */
	function plugixa_exam_is_valid_regex( string $pattern ) {
		// Length cap first, and cheaply. Catastrophic backtracking on an author-supplied
		// pattern is a denial of service against the grader, and "trusted role" is not the
		// same as "never makes a mistake with a nested quantifier".
		if ( '' === $pattern || strlen( $pattern ) > 512 ) {
			return false;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- scoped to the single probe below and restored three lines down. `@` is not an option: it lowers error_reporting but does not stop a custom handler (PHPUnit's, or a logging plugin's) turning the warning into a failure.
		set_error_handler( static fn (): bool => true );

		$valid = false !== preg_match( $pattern, '' );

		restore_error_handler();

		return $valid;
	}
}

if ( ! function_exists( 'plugixa_exam_get_template' ) ) {
	/**
	 * Locate a frontend template, honouring theme overrides.
	 *
	 * @param string $name Template file, relative to `templates/frontend/`.
	 */
	function plugixa_exam_get_template( string $name ): string {
		return \PlugixaExam\Core\Frontend\TemplateLoader::locate( $name );
	}
}

if ( ! function_exists( 'plugixa_exam_format_number' ) ) {
	/**
	 * A score or a percentage, written the way this site writes numbers.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * THE THREE SETTINGS THIS READS WERE WRITE-ONLY FOR THE PLUGIN'S WHOLE LIFE.
	 *
	 * `decimal_places`, `decimal_separator` and `thousands_separator` are declared, offered
	 * on the Settings screen under "Number formatting", validated and saved — and until this
	 * function existed nothing read them. The only file that mentioned them was the screen
	 * that edits them. A site owner setting a comma decimal separator, as most of Europe
	 * would, saw a full stop everywhere.
	 *
	 * THE SECOND HALF WAS WORSE THAN THE DEAD SETTING. Every call site had hard-coded its
	 * own precision, and they disagreed, so ONE result reached a candidate three ways:
	 *
	 *   result page      round( $percent, 1 )              72.5%
	 *   certificate      number_format_i18n( $percent, 2 ) 72.50%
	 *   result email     round( $percent, 2 )              72.5
	 *
	 * Every number a person reads now comes through here, so they agree by construction
	 * rather than by everyone remembering the same figure.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * ⚠️ NOT `number_format_i18n()`, WHICH IS THE OBVIOUS CHOICE AND THE WRONG ONE. That
	 * function formats to WordPress's locale, which is a different question from how this
	 * site has been told to write its marks. A site in `en_US` whose owner has set a comma
	 * separator means the comma; `number_format_i18n()` would give them a point and no way
	 * to say otherwise.
	 *
	 * A PASS MARK IS DELIBERATELY NOT PUT THROUGH HERE. It is a whole-number threshold
	 * somebody typed, and "pass mark 60.00%" reads as a precision the setting does not
	 * have — the exam does not become passable at 59.995.
	 *
	 * @param float    $value  The number.
	 * @param int|null $places Override the site's decimal places; null uses the setting.
	 */
	function plugixa_exam_format_number( float $value, ?int $places = null ): string {
		// NAN and INF survive a float cast and would reach `number_format()`, which emits a
		// warning and returns nonsense on a page somebody is reading their result on.
		if ( ! is_finite( $value ) ) {
			$value = 0.0;
		}

		$settings = null;

		try {
			$settings = PlugixaExam\Core\Plugin::instance()->container()->get( PlugixaExam\Core\Settings::class );
		} catch ( Throwable $e ) {
			$settings = null;
		}

		/*
		 * FAILS OPEN TO THE SHIPPED DEFAULTS. This runs while rendering a result page, and a
		 * site whose settings are briefly unreadable should print the mark somebody earned
		 * rather than a fatal.
		 */
		$places    = null === $places ? ( null === $settings ? 2 : $settings->int( 'decimal_places', 2 ) ) : $places;
		$decimal   = null === $settings ? '.' : $settings->str( 'decimal_separator', '.' );
		$thousands = null === $settings ? ',' : $settings->str( 'thousands_separator', ',' );

		// A separator is one character by definition, and `number_format()` happily accepts
		// a whole sentence — which would end up inside every mark on the site.
		$decimal   = '' === $decimal ? '.' : mb_substr( $decimal, 0, 1 );
		$thousands = mb_substr( $thousands, 0, 1 );

		return number_format( $value, max( 0, min( 6, $places ) ), $decimal, $thousands );
	}
}

if ( ! function_exists( 'plugixa_exam_translate_seeded' ) ) {
	/**
	 * Translate a string that was SEEDED into a row, using the English as its own key.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * WHY SEEDED TEXT CANNOT SIMPLY BE WRAPPED IN `__()` WHERE IT IS WRITTEN.
	 *
	 * Role names, email templates and the default grade scale are created by
	 * `Activator::migrate()`, which runs on `plugins_loaded`. Calling `__()` there is wrong
	 * twice over. WordPress refuses to load a textdomain before `init` — the user's locale
	 * is not settled until then — so the call returns English anyway AND raises
	 * `_load_textdomain_just_in_time was called incorrectly`. And if it ever did succeed it
	 * would be worse: the text would be frozen in whatever language the site happened to be
	 * in on the day it migrated, and every reader afterwards would get that language
	 * regardless of their own.
	 *
	 * So the seed writes ENGLISH, and the reader translates. The English is the lookup key,
	 * which is exactly how WordPress core handles role names in `translate_user_role()`.
	 *
	 * THE CONSEQUENCE THAT MAKES THIS SAFE: a row the site owner has edited no longer
	 * matches any key, so it passes through untouched. Renaming "Grader" to "Marker", or
	 * rewriting an email, survives — which a blanket re-translation on read would not.
	 *
	 * The catalogue is a literal map of English => `__( same English )`, so `make-pot` still
	 * extracts every string, and no variable is ever handed to `__()` — which
	 * `WordPress.WP.I18n` forbids and a WordPress.org reviewer reads twice.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string                $stored     The value as it is in the database.
	 * @param array<string, string> $catalogue  English => translated.
	 */
	function plugixa_exam_translate_seeded( string $stored, array $catalogue ): string {
		return $catalogue[ $stored ] ?? $stored;
	}
}
