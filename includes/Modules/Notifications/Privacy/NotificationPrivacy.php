<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Notifications\Privacy;

use PlugixaExam\Core\Database\Schema as Db;
use PlugixaExam\Modules\Notifications\Repositories\QueueRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queued and sent email in the privacy tools.
 *
 * EASY TO FORGET, WHICH IS WHY IT IS HERE. The attempts table is the obvious place personal
 * data lives, and a privacy implementation that covers only the obvious place is the normal
 * outcome. The notification queue holds a name, an address, and the full rendered body of
 * every message the site sent that person — including their score. It is a mailbox, and it
 * has to be treated as one.
 *
 * Unlike an attempt, a queued email has no statistical value at all: nothing is computed
 * from it, and the outbox screen is an operational log, not a record. So this eraser
 * DELETES, where the attempts one anonymises. The two policies differ because the data
 * differs, not because either was decided casually.
 */
final class NotificationPrivacy {

	/**
	 * Messages handled per page.
	 */
	private const PER_PAGE = 50;

	/**
	 * @param QueueRepository $queue Notification queue repository.
	 */
	public function __construct( private readonly QueueRepository $queue ) {}

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_personal_data_providers', array( $this, 'provider' ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $providers Accumulated providers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function provider( array $providers ): array {
		$providers[] = array(
			'id'     => 'notifications',
			'label'  => __( 'Exam email', 'plugixa-exam' ),
			'export' => array( $this, 'export' ),
			'erase'  => array( $this, 'erase' ),
		);

		return $providers;
	}

	/**
	 * Every message this plugin addressed to one person.
	 *
	 * @param string $email Email address the request was made for.
	 * @param int    $page  1-based page.
	 *
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$rows = $this->find( $email, max( 1, $page ) );

		$data = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'plugixa-exam-notifications',
				'group_label' => __( 'Exam email', 'plugixa-exam' ),
				'item_id'     => 'plugixa-exam-notification-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Sent to', 'plugixa-exam' ),
						'value' => trim( (string) ( $row['recipient_name'] ?? '' ) . ' <' . (string) ( $row['recipient_email'] ?? '' ) . '>' ),
					),
					array(
						'name'  => __( 'Subject', 'plugixa-exam' ),
						'value' => (string) ( $row['subject'] ?? '' ),
					),
					array(
						'name'  => __( 'Sent', 'plugixa-exam' ),
						'value' => (string) ( $row['sent_at'] ?? '' ),
					),
					array(
						'name'  => __( 'Status', 'plugixa-exam' ),
						'value' => (string) ( $row['status'] ?? '' ),
					),
					array(
						'name'  => __( 'Message', 'plugixa-exam' ),
						// The body is HTML the plugin composed. It goes into an export file
						// WordPress renders as HTML, so it is passed through unchanged and
						// escaped there rather than mangled here.
						'value' => (string) ( $row['body'] ?? '' ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * Delete every message addressed to this person.
	 *
	 * @param string $email Email address the request was made for.
	 * @param int    $page  1-based page. Unused — the set shrinks as it is erased.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		global $wpdb;

		unset( $page );

		$email = sanitize_email( $email );

		if ( '' === $email ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		/*
		 * One statement rather than a row-at-a-time loop: there is no per-row decision to
		 * make, the table has no children, and a mailing that fanned out to one address
		 * hundreds of times should not cost hundreds of queries.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; $wpdb->delete prepares.
		$removed = (int) $wpdb->delete( Db::table( 'notification_queue' ), array( 'recipient_email' => $email ) );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Messages addressed to an email address.
	 *
	 * Matched on `recipient_email` alone, not through the user account: the queue records
	 * where a message actually went, and that is the fact being asked about.
	 *
	 * @param string $email Email address.
	 * @param int    $page  1-based page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function find( string $email, int $page ): array {
		$email = sanitize_email( $email );

		if ( '' === $email ) {
			return array();
		}

		return $this->queue->query()
			->where( 't.recipient_email = %s', array( $email ) )
			->order_by( 't.id', 'ASC' )
			->paginate( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE )
			->get();
	}
}
