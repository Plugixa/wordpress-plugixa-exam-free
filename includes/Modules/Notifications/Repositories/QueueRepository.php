<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Notifications\Repositories;

use PlugixaExam\Core\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The outbox.
 */
final class QueueRepository extends Repository {

	/**
	 * How many times a message is retried before it is given up on.
	 *
	 * Five, with the backoff below, spans roughly two hours — long enough to ride out a mail
	 * host restart, short enough that a permanently bad address stops consuming a slot in
	 * every flush for ever.
	 */
	public const MAX_ATTEMPTS = 5;

	public function table(): string {
		// The BARE name. `Repository::full_table()` is what applies the prefix — returning a
		// prefixed name here yields `wp_plugixa_exam_wp_plugixa_exam_…`.
		return 'notification_queue';
	}

	public function entity_type(): string {
		return 'notification';
	}

	/**
	 * @return string[]
	 */
	public function fillable(): array {
		return array(
			'event_key', 'recipient_email', 'recipient_name', 'subject', 'body', 'content_type',
			'from_name', 'from_email', 'status', 'attempts', 'next_attempt_at',
			'sent_at', 'last_error', 'context_type', 'context_id',
		);
	}

	/**
	 * @return string[]
	 */
	public function sortable(): array {
		return array( 'id', 'status', 'created_at', 'sent_at' );
	}

	/**
	 * Messages due to be attempted.
	 *
	 * Reads `idx_flush (status, next_attempt_at)`. A row whose `next_attempt_at` is NULL has
	 * never been tried and is due immediately.
	 *
	 * @param int $limit How many to take.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function due( int $limit = 20 ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i
				 WHERE status = %s
				   AND attempts < %d
				   AND ( next_attempt_at IS NULL OR next_attempt_at <= %s )
				 ORDER BY id ASC
				 LIMIT %d',
				$this->full_table(),
				'pending',
				self::MAX_ATTEMPTS,
				current_time( 'mysql', true ),
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map( array( $this, 'cast' ), $rows );
	}

	/**
	 * Record a successful send.
	 *
	 * @param int $id Queue row id.
	 */
	public function mark_sent( int $id ): void {
		$this->update(
			$id,
			array(
				'status'     => 'sent',
				'sent_at'    => current_time( 'mysql', true ),
				'attempts'   => $this->attempts_of( $id ) + 1,
				'last_error' => '',
			)
		);
	}

	/**
	 * Record a failure, and decide whether to try again.
	 *
	 * @param int    $id    Queue row id.
	 * @param string $error What went wrong.
	 */
	public function mark_failed( int $id, string $error ): void {
		$attempts = $this->attempts_of( $id ) + 1;
		$spent    = $attempts >= self::MAX_ATTEMPTS;

		$this->update(
			$id,
			array(
				// `failed` is terminal. Leaving it `pending` would have the flush pick it up
				// for ever, and a single bad address would eventually fill every batch.
				'status'          => $spent ? 'failed' : 'pending',
				'attempts'        => $attempts,
				'next_attempt_at' => $spent ? null : self::backoff( $attempts ),
				'last_error'      => mb_substr( $error, 0, 500 ),
			)
		);
	}

	/**
	 * Put a failed message back in the queue.
	 *
	 * @param int $id Queue row id.
	 */
	public function retry( int $id ): void {
		$this->update(
			$id,
			array(
				'status'          => 'pending',
				'attempts'        => 0,
				'next_attempt_at' => null,
				'last_error'      => '',
			)
		);
	}

	/**
	 * How many messages sit in each state.
	 *
	 * @return array<string, int>
	 */
	public function counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT status, COUNT(*) total FROM %i WHERE deleted_at IS NULL GROUP BY status', $this->full_table() ),
			ARRAY_A
		);

		$counts = array( 'pending' => 0, 'sent' => 0, 'failed' => 0 );

		foreach ( $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Delete messages that were sent long enough ago to be of no further interest.
	 *
	 * @param int $days How long to keep them.
	 */
	public function prune( int $days = 30 ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; prepared.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE status = %s AND sent_at IS NOT NULL AND sent_at < %s',
				$this->full_table(),
				'sent',
				gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Delete messages that FAILED longer ago than a site keeps them.
	 *
	 * `prune()` only ever removed sent mail, so a message that could not be delivered — its
	 * recipient's address, name and the full body with their score — stayed for good. This
	 * is the retention setting `privacy_failed_days`, off by default; a failed message is a
	 * diagnostic for whoever runs the site, and only they know how long they need it.
	 *
	 * @param int $days How long to keep them.
	 */
	public function prune_failed( int $days ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; prepared.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE status = %s AND created_at < %s',
				$this->full_table(),
				'failed',
				gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * @param int $id Queue row id.
	 */
	private function attempts_of( int $id ): int {
		$row = $this->find( $id );

		return null === $row ? 0 : (int) $row['attempts'];
	}

	/**
	 * When to try again.
	 *
	 * Exponential, so a mail host having a bad minute is retried quickly and one that is
	 * genuinely down is not hammered: 1, 4, 9, 16 minutes.
	 *
	 * @param int $attempts How many tries have happened.
	 */
	private static function backoff( int $attempts ): string {
		return gmdate( 'Y-m-d H:i:s', time() + ( $attempts ** 2 ) * MINUTE_IN_SECONDS );
	}
}
