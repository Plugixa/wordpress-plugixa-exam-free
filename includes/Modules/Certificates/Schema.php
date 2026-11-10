<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Certificates;

use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate designs, and the certificates issued from them.
 *
 * @see \PlugixaExam\Modules\Certificates\Module for why a premium module may own tables
 *      even though downgrading has to be survivable.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'certificate_templates', 'certificates' );
	}

	/**
	 * @return array<string, string> What dbDelta changed.
	 */
	public static function install(): array {
		$changes = Db::delta( self::templates(), self::certificates() );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * A design: what a certificate looks like before anybody has earned one.
	 *
	 * `design_json` is a pdfme Template, in MILLIMETRES. Millimetres rather than pixels
	 * because the output is a printed page — a design expressed in pixels has to be told a
	 * DPI before it means anything, and every renderer picks a different one.
	 */
	private static function templates(): string {
		$table   = Db::table( 'certificate_templates' );
		$charset = Db::charset();
		$audit   = Repository::audit_columns();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			title varchar(255) NOT NULL default '',
			description text,
			design_json longtext,
			page_size varchar(20) NOT NULL default 'A4',
			orientation varchar(20) NOT NULL default 'landscape',
			background_image varchar(255) default NULL,
			valid_days int(11) NOT NULL default 0,
			number_format varchar(100) NOT NULL default '',
			published tinyint(1) NOT NULL default 1,
			{$audit}
			PRIMARY KEY  (id),
			KEY published (published),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}

	/**
	 * One certificate, awarded to one person for one attempt.
	 *
	 * ────────────────────────────────────────────────────────────────────────────────────
	 * THREE COLUMNS ARE FROZEN AT ISSUE, AND THAT IS THE WHOLE POINT OF THIS TABLE.
	 *
	 * `snapshot_html`, `page_size` and `orientation` record what the certificate looked like
	 * the moment it was earned. Editing a template afterwards — moving the logo, renaming the
	 * course, changing the paper size — must NOT rewrite a document somebody has already
	 * downloaded, printed and filed with an employer. A certificate is a claim about a past
	 * event; re-rendering it from today's design would quietly change the evidence.
	 *
	 * It also means verification stays honest: the page at `/verify/<token>/` shows what was
	 * issued, not what would be issued now.
	 * ────────────────────────────────────────────────────────────────────────────────────
	 *
	 * `attempt_id` IS UNIQUE. One sitting earns one certificate, enforced by the database
	 * rather than by a check-then-insert — two requests finishing the same attempt at once
	 * (a double-clicked submit, a retried webhook) would otherwise both pass the check and
	 * issue two numbered certificates for one exam.
	 *
	 * `verify_token` is what the QR code encodes, and it is SEPARATE from `number`. The
	 * number is human-facing and may be formatted to look like a reference; the token is
	 * random and unguessable, so knowing somebody's certificate number does not let you pull
	 * up their record.
	 */
	private static function certificates(): string {
		$table   = Db::table( 'certificates' );
		$charset = Db::charset();
		$audit   = Repository::audit_columns();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			template_id bigint(20) unsigned NOT NULL default 0,
			exam_id bigint(20) unsigned NOT NULL default 0,
			attempt_id bigint(20) unsigned NOT NULL default 0,
			user_id bigint(20) unsigned default NULL,
			guest_name varchar(255) default NULL,
			guest_email varchar(255) default NULL,
			number varchar(100) NOT NULL default '',
			verify_token varchar(64) default NULL,
			recipient_name varchar(255) NOT NULL default '',
			score decimal(8,2) default NULL,
			max_score decimal(8,2) default NULL,
			percent decimal(5,2) default NULL,
			issued_at datetime default NULL,
			expires_at datetime default NULL,
			revoked tinyint(1) NOT NULL default 0,
			revoked_at datetime default NULL,
			revoked_by bigint(20) unsigned default NULL,
			revoke_reason varchar(255) default NULL,
			parent_cert_id bigint(20) unsigned NOT NULL default 0,
			snapshot_html longtext,
			page_size varchar(20) NOT NULL default 'A4',
			orientation varchar(20) NOT NULL default 'landscape',
			reminders_sent varchar(255) NOT NULL default '',
			{$audit}
			PRIMARY KEY  (id),
			UNIQUE KEY attempt_id (attempt_id),
			UNIQUE KEY number (number),
			UNIQUE KEY verify_token (verify_token),
			KEY exam_id (exam_id),
			KEY user_id (user_id),
			KEY issued_at (issued_at),
			KEY expiry_sweep (revoked,expires_at),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}
}
