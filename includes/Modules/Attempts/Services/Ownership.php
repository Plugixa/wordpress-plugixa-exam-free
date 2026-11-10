<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Services;

use PlugixaExam\Core\Security\GuestToken;
use PlugixaExam\Core\Security\Permissions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whose attempt it is, and who else may look at it.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * TWO QUESTIONS, AND THEY USED TO BE ONE.
 *
 * The runner asked "does the caller own this attempt?" in three places — its REST routes,
 * the form a learner without JavaScript posts, and the premium upload form — and two of the
 * three answered yes to anybody holding `attempts: view`. That clause was there so a marker
 * could open a learner's result. It also sat in front of autosave, pause, resume and
 * submit, so a member of staff who may only LOOK at attempts could write answers into a
 * candidate's live paper and hand it in for them: one request, in either door. WordPress.org's
 * review of 1.1.1 said so in a sentence.
 *
 * So the two things that were confused are now two methods, and every caller picks one:
 *
 *   {@see is_candidate()}  the PAPER. Whoever is sitting it, and nobody else — not a marker,
 *                          not an administrator. Sitting somebody's exam is not a kind of
 *                          viewing, and staff have their own screens for what they may do
 *                          to an attempt, each behind its own permission.
 *   {@see may_read()}      the RECORD. The candidate, or staff who may view attempts: a
 *                          result, and a file handed in with it. Alerts and marking emails
 *                          link staff to exactly those.
 *
 * One class rather than a rule repeated, because the repetition is how they drifted: the
 * third copy had already been written candidate-only, correctly, and nobody carried that
 * back to the other two.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class Ownership {

	/**
	 * Whether the caller is the person sitting this attempt.
	 *
	 * A signed-in user is the candidate of an attempt whose `user_id` is theirs. A guest is
	 * the candidate of one whose token matches their signed cookie — see {@see GuestToken}
	 * for why holding it is being that guest.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 */
	public function is_candidate( array $attempt ): bool {
		$user_id = get_current_user_id();

		if ( 0 !== $user_id && (int) ( $attempt['user_id'] ?? 0 ) === $user_id ) {
			return true;
		}

		$token = GuestToken::current();

		// Timing-safe: this comparison decides whether somebody may read and write another
		// person's paper.
		return null !== $token
			&& ! empty( $attempt['guest_token'] )
			&& hash_equals( (string) $attempt['guest_token'], $token );
	}

	/**
	 * Whether the caller may read what came of this attempt.
	 *
	 * Reading only. Nothing that answers to this may change the attempt, and the candidate
	 * is asked first so the ordinary path never pays for a capability lookup.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 */
	public function may_read( array $attempt ): bool {
		return $this->is_candidate( $attempt ) || Permissions::user_can( 'attempts', 'view' );
	}
}
