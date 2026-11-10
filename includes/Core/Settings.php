<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every plugin setting, in one autoloaded option.
 *
 * A DELIBERATE DEPARTURE FROM THE SOURCE, recorded here because the specification
 * describes a `settings` table and this is not it.
 *
 * NextExams stores ~70 key/value rows in `#__nextexams_settings`, read through a helper on
 * demand. That shape is normal in Joomla and wrong in WordPress: `get_option()` on an
 * autoloaded option costs no query at all — WordPress loads every autoloaded option in the
 * single `alloptions` query it already makes, and the object cache holds it for the rest of
 * the request. A settings table costs one query per request that reads a setting, which is
 * every admin request, every REST request and every runner autosave. The runner reads
 * settings on a debounce timer; multiplying that by every examinee in a room is exactly the
 * load this plugin is supposed to survive.
 *
 * What the table gave that this does not: per-row `modified_by`, and an atomic
 * compare-and-set on a single key. The first is unused upstream. The second matters for
 * exactly one thing — the auto-close sweep's once-a-minute throttle, which uses a settings
 * row as a lock — and that is served properly by its own mechanism in the Attempts module
 * rather than by giving all seventy settings a slower home.
 *
 * Modules contribute their own keys through `plugixa_exam_settings_defaults`, and their own
 * validation through `plugixa_exam_sanitize_settings`. A key no filter claims is dropped on
 * save, which is what makes the whitelist a whitelist.
 */
final class Settings {

	public const OPTION = 'plugixa_exam_settings';

	/**
	 * The settings that hold a colour.
	 *
	 * Every one of them is written into a stylesheet on the front end, so every one of them
	 * is a hex colour or it is not stored. See {@see save()}.
	 */
	public const COLOURS = array(
		'color_primary',
		'color_primary_contrast',
		'color_accent',
		'color_bg',
		'color_text',
		'color_correct',
		'color_incorrect',
	);

	/**
	 * In-request cache of the merged settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $cache = null;

	/**
	 * Every setting, defaults merged under stored values.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( self::OPTION, array() );

		$this->cache = array_merge( $this->defaults(), is_array( $stored ) ? $stored : array() );

		return $this->cache;
	}

	/**
	 * @param string $key     Setting key.
	 * @param mixed  $default_value Returned when the key is absent entirely.
	 */
	public function get( string $key, mixed $default_value = null ): mixed {
		return $this->all()[ $key ] ?? $default_value;
	}

	/**
	 * A setting as a boolean.
	 *
	 * Stored values arrive as `'1'`/`'0'` strings from the REST layer, and `'0'` is truthy
	 * under a bare cast — the single most common way a disabled feature stays enabled.
	 *
	 * @param string $key           Setting key.
	 * @param bool   $default_value Returned when the key is absent.
	 */
	public function bool( string $key, bool $default_value = false ): bool {
		$value = $this->get( $key );

		if ( null === $value ) {
			return $default_value;
		}

		return filter_var( $value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE ) ?? $default_value;
	}

	/**
	 * A setting as an integer.
	 *
	 * @param string $key           Setting key.
	 * @param int    $default_value Returned when the key is absent.
	 */
	public function int( string $key, int $default_value = 0 ): int {
		$value = $this->get( $key );

		return null === $value ? $default_value : (int) $value;
	}

	/**
	 * A setting as a string.
	 *
	 * @param string $key           Setting key.
	 * @param string $default_value Returned when the key is absent.
	 */
	public function str( string $key, string $default_value = '' ): string {
		$value = $this->get( $key );

		return null === $value || is_array( $value ) ? $default_value : (string) $value;
	}

	/**
	 * Write one setting.
	 *
	 * Bypasses the sanitize filter chain on purpose: this is the internal writer used by
	 * the App Manager and by migrations, where the value is already known-good and the
	 * caller is not a request. Everything reachable from a request goes through
	 * {@see save()}.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 * @param string $type  Retained for readability at call sites; values are stored as given.
	 */
	public function set( string $key, mixed $value, string $type = 'string' ): void {
		unset( $type );

		$all         = $this->all();
		$all[ $key ] = $value;

		$this->persist( $all );
	}

	/**
	 * Apply a batch of user-supplied settings.
	 *
	 * Unknown keys are dropped rather than stored. A settings endpoint that accepts
	 * whatever it is handed is a way to write arbitrary rows into an autoloaded option,
	 * which is both a storage problem and, once anything reads a key by name, a logic one.
	 *
	 * @param array<string, mixed> $input Raw input.
	 *
	 * @return array<string, mixed> The settings as they now stand.
	 */
	public function save( array $input ): array {
		$current  = $this->all();
		$defaults = $this->defaults();

		$clean = array();

		foreach ( $input as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}

			$clean[ $key ] = $value;
		}

		/**
		 * Sanitize and validate the incoming settings.
		 *
		 * Each module hooks this for the keys it declared in
		 * `plugixa_exam_settings_defaults`. A module that adds a key and no sanitizer gets
		 * the generic treatment below, which is safe but blunt.
		 *
		 * @param array<string, mixed> $clean   The whitelisted input.
		 * @param array<string, mixed> $current The settings before this save.
		 */
		$clean = (array) apply_filters( 'plugixa_exam_sanitize_settings', $clean, $current );

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * A COLOUR IS A HEX COLOUR, OR IT IS NOT SAVED.
		 *
		 * These seven are printed into a stylesheet on every front-end page, and the generic
		 * pass below does not make a value safe to put there: `sanitize_text_field` strips
		 * tags, and a stylesheet is not broken out of with a tag. `red;}body{display:none`
		 * went through it untouched, was stored, and came out as a rule of its own — found
		 * by WordPress.org's review of 1.1.1 and reproduced with exactly that string.
		 *
		 * Hex and nothing else because hex is all the settings screen has ever written: its
		 * field is a colour picker beside a box that accepts `#rgb` or `#rrggbb` and puts
		 * back the last good value otherwise. A named or `rgb()` colour could only arrive
		 * from a request made by hand, and the strictest function that fits what the screen
		 * sends is the one to use.
		 *
		 * A value that is not one is DROPPED from this save rather than blanked, so the
		 * colour that was there stays. The empty string is kept: it means "no override".
		 *
		 * BEFORE the generic pass, on what was actually sent. After it, a value that was
		 * nothing but markup has been stripped to the empty string — which is a legitimate
		 * colour setting, so a request made of tags would have cleared the colour it was
		 * refused permission to change.
		 * ════════════════════════════════════════════════════════════════════════════════
		 */
		foreach ( self::COLOURS as $key ) {
			if ( ! array_key_exists( $key, $clean ) ) {
				continue;
			}

			$colour = is_string( $clean[ $key ] ) ? sanitize_hex_color( trim( $clean[ $key ] ) ) : null;

			if ( null === $colour ) {
				unset( $clean[ $key ] );

				continue;
			}

			$clean[ $key ] = $colour;
		}

		foreach ( $clean as $key => $value ) {
			if ( is_scalar( $value ) && ! is_bool( $value ) ) {
				$clean[ $key ] = sanitize_text_field( (string) $value );

				/*
				 * A NUMBER STAYS A NUMBER. The line above turns every scalar into a string,
				 * so "decimal places: 1" was stored and sent back as "1" while a site that
				 * had never saved it read the default, the integer 2. Two types for one
				 * setting, depending on whether anybody had pressed Save. The default says
				 * which it is; something that is not a number at all is left as it was sent,
				 * for the module that owns the setting to judge.
				 */
				$default = $defaults[ $key ];

				if ( is_numeric( $clean[ $key ] ) && ( is_int( $default ) || is_float( $default ) ) ) {
					// A whole number where the default is one; a decimal is never cut short.
					$clean[ $key ] = is_int( $default ) && (string) (int) $clean[ $key ] === $clean[ $key ]
						? (int) $clean[ $key ]
						: (float) $clean[ $key ];
				}
			}
		}

		$merged = array_merge( $current, $clean );

		$this->persist( $merged );

		return $merged;
	}

	/**
	 * The settings as an API response may show them.
	 *
	 * ────────────────────────────────────────────────────────────────────────────────────
	 * SOME ROWS IN THIS OPTION ARE NOT SETTINGS. THEY ARE STATE, OR THEY ARE SECRETS.
	 *
	 * `permission_version` is a cache-busting counter. `attempt_sweep_last_run` is a lock
	 * the sweep writes. The LTI module keeps an RSA PRIVATE KEY here — the one that signs
	 * this site's identity to every platform it is registered with, and which would let
	 * whoever holds it write grades into somebody's gradebook.
	 *
	 * `all()` returns the lot, because internal code needs the lot. Anything answering a
	 * REQUEST goes through here instead. The spec has always said these are "never exposed
	 * to the settings API"; this is the method that makes that true rather than aspirational.
	 * ────────────────────────────────────────────────────────────────────────────────────
	 *
	 * @param array<string, mixed>|null $all The settings to filter, or null to read them.
	 *
	 * @return array<string, mixed>
	 */
	public function public_view( ?array $all = null ): array {
		$all ??= $this->all();

		/**
		 * Setting keys that must never reach a browser.
		 *
		 * A module storing key material or internal state adds its keys here. Declared
		 * rather than inferred: a naming convention would be one rename away from leaking,
		 * and the cost of forgetting is a private key in a JSON response.
		 *
		 * @param string[] $internal Accumulated internal keys.
		 */
		$internal = (array) apply_filters(
			'plugixa_exam_internal_settings',
			array( 'permission_version', 'attempt_sweep_last_run' )
		);

		foreach ( $internal as $key ) {
			unset( $all[ (string) $key ] );
		}

		return $all;
	}

	/**
	 * Drop the in-request cache. For tests and for code that writes the option directly.
	 */
	public function flush(): void {
		$this->cache = null;
	}

	/**
	 * The default value of every known setting.
	 *
	 * Core declares only what Core owns. Every module adds its own, which is what makes
	 * this the whitelist {@see save()} filters against — a module that is not installed
	 * cannot have its settings written.
	 *
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		$defaults = array(
			// Number formatting.
			'decimal_places'      => 2,
			'decimal_separator'   => '.',
			'thousands_separator' => ',',

			// Date and time. Empty means "use the WordPress site setting", which is the
			// right default in WordPress and has no analogue in the Joomla original.
			'date_format'         => '',
			'time_format'         => '',


			// Colour scheme, consumed by the admin MUI theme and the frontend CSS
			// variables so both render the same exam.
			'color_primary'       => '#1976d2',
			'color_primary_contrast' => '#ffffff',
			'color_accent'        => '#9c27b0',
			'color_bg'            => '#ffffff',
			'color_text'          => '#1a1a1a',
			'color_correct'       => '#2e7d32',
			'color_incorrect'     => '#c62828',

			// Bumped whenever a role or assignment changes, to invalidate the resolved
			// permission cache without having to find every place one was memoised.
			'permission_version'  => 1,
		);

		/**
		 * Declare a module's settings and their defaults.
		 *
		 * @param array<string, mixed> $defaults Accumulated defaults.
		 */
		return (array) apply_filters( 'plugixa_exam_settings_defaults', $defaults );
	}

	/**
	 * Write the option and refresh the in-request cache.
	 *
	 * @param array<string, mixed> $all Complete settings.
	 */
	private function persist( array $all ): void {
		update_option( self::OPTION, $all, true );

		$this->cache = $all;
	}
}
