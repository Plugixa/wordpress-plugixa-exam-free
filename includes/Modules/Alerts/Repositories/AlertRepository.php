<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Alerts\Repositories;

use PlugixaExam\Core\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Data access for one person's alerts.
 *
 * Every method here is scoped to a `user_id` the CALLER supplies from the session, never from a
 * request. There is deliberately no "find by id" that a route could hand an arbitrary number to:
 * the only questions this table answers are "what is in my inbox" and "how much of it is new",
 * and neither of them needs to name a row.
 */
final class AlertRepository extends Repository {

	/**
	 * How many rows the bell will show at once.
	 *
	 * A feed, not an archive. Somebody with four hundred unread alerts wants the recent ones and
	 * a badge, and paging a dropdown is a feature nobody has asked for.
	 */
	private const FEED_LIMIT = 30;

	public function table(): string {
		return 'alerts';
	}

	public function entity_type(): string {
		return 'alert';
	}

	/**
	 * THE OVERRIDE THAT IS NOT OPTIONAL.
	 *
	 * The base returns true, so `query()` would filter on a `deleted_at` this table does not
	 * have — and a SQL error in that position does not throw, IT RETURNS AN EMPTY RESULT. The
	 * symptom is an inbox that is permanently empty while every "is this refused" test passes,
	 * which has already cost this codebase an afternoon once (see the Cohorts join tables).
	 */
	public function soft_deletes(): bool {
		return false;
	}

	/**
	 * @return string[]
	 */
	public function fillable(): array {
		return array( 'user_id', 'alert_type', 'subject', 'context_type', 'context_id', 'read_at' );
	}

	/**
	 * @return string[]
	 */
	public function sortable(): array {
		return array( 'id', 'created_at', 'read_at' );
	}

	/**
	 * One person's most recent alerts, newest first.
	 *
	 * @param int $user_id Whose inbox.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function inbox( int $user_id ): array {
		if ( 0 === $user_id ) {
			return array();
		}

		return array_map(
			array( $this, 'cast' ),
			$this->query()
				->where( 't.user_id = %d', array( $user_id ) )
				->order_by( 't.created_at', 'DESC' )
				->order_by( 't.id', 'DESC' )
				->paginate( self::FEED_LIMIT )
				->get()
		);
	}

	/**
	 * How many of a person's alerts they have not seen.
	 *
	 * @param int $user_id Whose inbox.
	 */
	public function unread_count( int $user_id ): int {
		if ( 0 === $user_id ) {
			return 0;
		}

		return $this->query()
			->where( 't.user_id = %d', array( $user_id ) )
			->where( 't.read_at IS NULL' )
			->count();
	}

	/**
	 * Mark everything in one person's inbox as seen.
	 *
	 * Scoped to unread rows so a second click does not rewrite a timestamp somebody already
	 * has, and returns how many it touched so a caller can tell "nothing to do" from "done".
	 *
	 * @param int $user_id Whose inbox.
	 */
	public function mark_all_read( int $user_id ): int {
		global $wpdb;

		if ( 0 === $user_id ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A bulk update on one user's rows; there is no cache layer over this table.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET read_at = %s WHERE user_id = %d AND read_at IS NULL',
				$this->full_table(),
				current_time( 'mysql', true ),
				$user_id
			)
		);

		return is_int( $updated ) ? $updated : 0;
	}

	/**
	 * Whether somebody has already been told this about this thing, read or not.
	 *
	 * @param int    $user_id      Who.
	 * @param string $alert_type   What kind of alert.
	 * @param string $context_type Entity type.
	 * @param int    $context_id   Entity id.
	 */
	public function exists( int $user_id, string $alert_type, string $context_type, int $context_id ): bool {
		return null !== $this->query()
			->where( 't.user_id = %d', array( $user_id ) )
			->where( 't.alert_type = %s', array( $alert_type ) )
			->where( 't.context_type = %s', array( $context_type ) )
			->where( 't.context_id = %d', array( $context_id ) )
			->paginate( 1 )
			->first();
	}

	/**
	 * Delete alerts about a thing that no longer exists.
	 *
	 * Called from the generic purge. Without it an alert outlives its subject and links to a
	 * page that 404s — the free version of the join this table deliberately does not do.
	 *
	 * @param string $context_type Entity type.
	 * @param int    $context_id   Entity id.
	 */
	public function forget( string $context_type, int $context_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup on entity purge; no cache layer.
		$wpdb->delete(
			$this->full_table(),
			array(
				'context_type' => $context_type,
				'context_id'   => $context_id,
			),
			array( '%s', '%d' )
		);
	}

	/**
	 * Remove read alerts older than the given number of days.
	 *
	 * UNREAD ROWS ARE NEVER PRUNED, the same rule the email outbox uses for failures: an alert
	 * nobody has seen is the one piece of evidence that something happened, and deleting it on
	 * a timer means the person who was away for a month comes back to an empty feed.
	 *
	 * @param int $days How long a read alert is kept.
	 */
	public function prune( int $days = 90 ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Scheduled cleanup; no cache layer.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE read_at IS NOT NULL AND read_at < %s',
				$this->full_table(),
				gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) )
			)
		);
	}
}
