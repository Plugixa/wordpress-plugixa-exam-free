<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reproducible shuffling.
 *
 * WHY NOT `shuffle()`: an examinee who reloads the page must see the options in the SAME
 * order. With an unseeded shuffle they do not — the answers jump around under them, which
 * reads as a broken exam and, worse, invites the suspicion that the paper changed. Storing
 * the shuffled order per attempt would work but writes a row per question; seeding from
 * data the attempt already carries costs nothing and cannot drift.
 *
 * The seed is the attempt's own random seed combined with the question id, so two questions
 * in one attempt shuffle differently and the same question shuffles differently in two
 * attempts.
 *
 * Grading is unaffected either way: responses store canonical option ids, never positions.
 */
final class Shuffle {

	/**
	 * Deterministically reorder a list.
	 *
	 * Fisher–Yates driven by a seeded `mt_rand`, then the global generator is reseeded from
	 * entropy. That last step is not optional: `mt_srand()` sets PHP's process-wide
	 * generator, so leaving it seeded would make every later `mt_rand()` in the request —
	 * including any other plugin's — predictable from the attempt id.
	 *
	 * @param array<int, mixed> $items The list.
	 * @param string            $seed  The attempt's seed.
	 * @param int               $salt  Usually the question id.
	 *
	 * @return array<int, mixed>
	 */
	public static function order( array $items, string $seed, int $salt = 0 ): array {
		$values = array_values( $items );
		$count  = count( $values );

		if ( $count < 2 ) {
			return $values;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- seeding is the entire point; this must be reproducible, not random.
		mt_srand( crc32( $seed . ':' . $salt ) );

		for ( $i = $count - 1; $i > 0; $i-- ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- see above; wp_rand() cannot be seeded.
			$j = mt_rand( 0, $i );

			[ $values[ $i ], $values[ $j ] ] = array( $values[ $j ], $values[ $i ] );
		}

		// Restore unpredictability for everything else in this request.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_seeding_mt_srand -- restoring entropy after a deliberate seeding.
		mt_srand();

		return $values;
	}

	/**
	 * A fresh seed for an attempt.
	 *
	 * `wp_generate_password` rather than `mt_rand`, because this value decides the order of
	 * a paper and a guessable seed is a guessable paper.
	 */
	public static function new_seed(): string {
		return wp_generate_password( 16, false, false );
	}
}
