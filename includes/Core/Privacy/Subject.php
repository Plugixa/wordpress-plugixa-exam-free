<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Privacy;

use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The person a WordPress privacy request is about.
 *
 * WordPress hands every exporter and eraser an email address and nothing else. This plugin
 * stores people two ways — as a `user_id` when they sat signed in, and as a `guest_email`
 * when they did not — and the same person can be both. Every provider has to match both
 * halves, the same way, or an erasure that cleans one leaves the other on screen.
 *
 * It lives in Core, not in the Ecosystem module, because modules may not name each other
 * and every module that holds personal data needs it.
 */
final class Subject {

	private function __construct(
		public readonly string $email,
		public readonly int $user_id,
	) {}

	/**
	 * Resolve a request's email address.
	 *
	 * Returns null for an address that does not sanitise to anything, so a provider can
	 * report "nothing found" without running a query that would match every empty
	 * `guest_email` column in the table.
	 */
	public static function from_email( string $email ): ?self {
		$email = sanitize_email( $email );

		if ( '' === $email ) {
			return null;
		}

		$user = get_user_by( 'email', $email );

		return new self( $email, $user instanceof WP_User ? (int) $user->ID : 0 );
	}

	/**
	 * A WHERE fragment and its arguments for a table with a user id and an email column.
	 *
	 * Without an account, only the email half is used. `user_id = 0` would otherwise match
	 * every guest row in the table.
	 *
	 * @param string $user_column  Fully qualified user id column, e.g. `t.user_id`.
	 * @param string $email_column Fully qualified email column, e.g. `t.guest_email`.
	 *
	 * @return array{0: string, 1: array<int, int|string>}
	 */
	public function where( string $user_column, string $email_column ): array {
		if ( $this->user_id > 0 ) {
			return array( "({$user_column} = %d OR {$email_column} = %s)", array( $this->user_id, $this->email ) );
		}

		return array( "{$email_column} = %s", array( $this->email ) );
	}
}
