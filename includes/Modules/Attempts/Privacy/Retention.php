<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Attempts\Privacy;

use PlugixaExam\Core\Database\Schema as Db;
use PlugixaExam\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Anonymising finished attempts once the site's retention period has passed.
 *
 * GDPR's storage-limitation principle, as a setting: `privacy_retention_months`, 0 (off) by
 * default, so an update never starts anonymising anybody's results by itself.
 *
 * THE SAME ANONYMISATION AS AN ERASURE REQUEST — {@see AttemptPrivacy::anonymise()}, with the
 * reason `retention` — so the two can never disagree about what an anonymised attempt is.
 * The reason is what lets a certificate survive: the holder still relies on it being
 * verifiable, which is a purpose of its own that the attempt's age does not end.
 *
 * Only FINISHED attempts, and measured from when they were handed in (or started, for an
 * expired paper that never was). An attempt in progress or paused is somebody's live exam.
 */
final class Retention {

	/** Attempts per run. A long backlog continues in a follow-up run rather than one long request. */
	public const BATCH = 200;

	public function __construct(
		private readonly AttemptPrivacy $privacy,
		private readonly Settings $settings,
	) {}

	/**
	 * Anonymise one batch of attempts older than the retention period.
	 *
	 * @return int How many were anonymised; BATCH means there may be more.
	 */
	public function run(): int {
		global $wpdb;

		$months = $this->settings->int( 'privacy_retention_months', 0 );

		if ( $months < 1 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) );

		/*
		 * "Not yet anonymised" is read from the four identity columns an anonymised attempt
		 * has emptied, so a run never re-processes — or re-announces — what an earlier run
		 * or an erasure request already did.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table; a sweep must read the table as it is.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i
				WHERE status NOT IN ('in_progress', 'paused')
					AND COALESCE(submitted, started) < %s
					AND ( user_id IS NOT NULL OR guest_token IS NOT NULL OR guest_email <> '' OR guest_name <> '' )
				ORDER BY id ASC
				LIMIT %d",
				Db::table( 'attempts' ),
				$cutoff,
				self::BATCH
			),
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$this->privacy->anonymise( $row, '', AttemptPrivacy::REASON_RETENTION );
		}

		return count( $rows );
	}
}
