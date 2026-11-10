<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Certificates\Services;

use PlugixaExam\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate numbers.
 *
 * An author writes a format like `CERT-{Y}-{seq:5}` and every certificate gets a number that
 * looks deliberate rather than like a database id. Tokens:
 *
 *   {Y}       four-digit year          {y}  two-digit year
 *   {m}       two-digit month          {d}  two-digit day
 *   {seq}     a site-wide counter      {seq:5}  the same, zero-padded to 5
 *   {rand}    six random characters    {rand:10}  the same, 10 long
 *   {exam}    the exam id
 *
 * ────────────────────────────────────────────────────────────────────────────────────────
 * THE SEQUENCE IS A SITE OPTION INCREMENTED WITH `update_option`, NOT `MAX(number) + 1`.
 *
 * Deriving the next number from the highest existing one looks simpler and is wrong twice:
 * numbers are formatted strings, so `MAX` on them is alphabetical — `CERT-9` sorts above
 * `CERT-10` — and two simultaneous issues read the same maximum and produce the same number.
 * A counter that is read-and-written as one option update does not have the second problem,
 * and the UNIQUE index catches whatever slips through.
 * ────────────────────────────────────────────────────────────────────────────────────────
 *
 * A FORMAT WITH NO VARYING TOKEN IS COMPLETED WITH ONE. `CERT-{Y}` would collide on the
 * second certificate of the year, and an author who wrote it meant "a yearly prefix", not
 * "one certificate per year". Rather than refuse the save or silently fail to issue, a
 * random suffix is appended — visible, harmless, and self-explaining once seen.
 */
final class Numbers {

	/**
	 * The site-wide counter.
	 *
	 * Autoloaded, because it is read on every issue and issuing happens on the submit
	 * request an examinee is waiting on.
	 */
	private const SEQUENCE_OPTION = 'plugixa_exam_certificate_sequence';

	/**
	 * The last-resort format, for a site whose setting has been emptied.
	 *
	 * ⚠️ NOT THE SITE DEFAULT — {@see $settings} is. This constant used to be the only
	 * fallback, which is what made `certificate_number_format` a setting that did nothing:
	 * the Settings screen offered it, listed every token it accepts, saved it happily, and
	 * `generate()` never read it. Measured before the fix — the setting changed to
	 * `ZZZ-{rand}`, three certificates issued, all three numbered `CERT-2026-…`. It looked
	 * right at a glance only because this string and the shipped default were identical.
	 */
	private const DEFAULT_FORMAT = 'CERT-{Y}-{seq:5}';

	/**
	 * @param Settings $settings Where the site-wide format lives.
	 */
	public function __construct( private readonly Settings $settings ) {}

	/**
	 * Build a number from a format.
	 *
	 * THREE LEVELS, NARROWEST FIRST: the design's own format, then the site-wide setting,
	 * then the constant above. A design that sets nothing is asking for the site's scheme —
	 * which is what an organisation wanting one numbering scheme across all its certificates
	 * expects, and what the setting's own help text has always promised.
	 *
	 * @param string $format  The design's format, or an empty string for the site default.
	 * @param int    $attempt Which retry this is; a non-zero value forces extra entropy so a
	 *                        retry after a collision cannot produce the same string again.
	 */
	public function generate( string $format, int $attempt = 0 ): string {
		$format = trim( $format );

		if ( '' === $format ) {
			$format = trim( $this->settings->str( 'certificate_number_format', '' ) );
		}

		if ( '' === $format ) {
			$format = self::DEFAULT_FORMAT;
		}

		$varies = $this->varies( $format );

		$number = (string) preg_replace_callback(
			'/\{(Y|y|m|d|seq|rand|exam)(?::(\d{1,3}))?\}/',
			fn( array $match ): string => $this->token( $match[1], isset( $match[2] ) ? (int) $match[2] : 0 ),
			$format
		);

		// Trim rather than reject: the column is varchar(100), and an author's long prefix
		// should not stop somebody being awarded what they earned.
		$number = mb_substr( $number, 0, 100 );

		if ( ! $varies || $attempt > 0 ) {
			$suffix = '-' . strtoupper( wp_generate_password( 5, false, false ) );

			$number = mb_substr( $number, 0, 100 - mb_strlen( $suffix ) ) . $suffix;
		}

		return $number;
	}

	/**
	 * Whether a format contains something that changes between two certificates issued in
	 * the same second.
	 *
	 * The date tokens do not count: two people passing the same exam on the same morning
	 * would collide.
	 *
	 * @param string $format The format.
	 */
	private function varies( string $format ): bool {
		return str_contains( $format, '{seq' ) || str_contains( $format, '{rand' );
	}

	/**
	 * One token's replacement.
	 *
	 * @param string $token The token name.
	 * @param int    $size  The `:n` argument, or 0.
	 */
	private function token( string $token, int $size ): string {
		switch ( $token ) {
			case 'Y':
				return gmdate( 'Y' );

			case 'y':
				return gmdate( 'y' );

			case 'm':
				return gmdate( 'm' );

			case 'd':
				return gmdate( 'd' );

			case 'seq':
				$next = $this->next_sequence();

				return $size > 0 ? str_pad( (string) $next, min( $size, 20 ), '0', STR_PAD_LEFT ) : (string) $next;

			case 'rand':
				return strtoupper( wp_generate_password( max( 1, min( $size ?: 6, 32 ) ), false, false ) );

			case 'exam':
				return (string) absint( get_query_var( 'plugixa_exam_id' ) );

			default:
				return '';
		}
	}

	/**
	 * The next value of the site-wide counter.
	 *
	 * `update_option` returning false means the value did not change, which for a counter
	 * means somebody else wrote the same number first — so the caller's uniqueness check and
	 * the UNIQUE index take over from here.
	 */
	private function next_sequence(): int {
		$current = (int) get_option( self::SEQUENCE_OPTION, 0 );
		$next    = $current + 1;

		update_option( self::SEQUENCE_OPTION, $next, true );

		return $next;
	}
}
