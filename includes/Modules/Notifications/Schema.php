<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Notifications;

use PlugixaExam\Core\Database\Repository;
use PlugixaExam\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email templates, and the queue that sends them.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'email_templates', 'notification_queue' );
	}

	/**
	 * @return array<string, string> What dbDelta changed.
	 */
	public static function install(): array {
		$changes = Db::delta( self::email_templates(), self::notification_queue() );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * One row per kind of email the site can send.
	 *
	 * `event_key` is UNIQUE and is what code looks a template up by, so a template is edited
	 * rather than replaced — an administrator who rewrites "your result is ready" keeps that
	 * wording when the plugin updates.
	 *
	 * `from_name` and `from_email` are nullable and mean "use the site's". Storing the site
	 * default in the row would freeze it: changing the site's address later would leave every
	 * template still sending as the old one.
	 *
	 * `content_type` IS PER ROW, AND IT DEFAULTS TO `text` ON PURPOSE. The body used to be plain
	 * text everywhere, so every template that already exists on every installed site was written
	 * as plain text — and a global switch to HTML would reflow all of them at once, turning
	 * somebody's carefully spaced message into one run-on paragraph. Defaulting the column means
	 * an upgrade changes nothing an existing site sends; a template becomes HTML when an
	 * administrator switches that one template, and the queue row copies the format so a message
	 * already waiting to go out is sent the way it was written.
	 */
	private static function email_templates(): string {
		$table   = Db::table( 'email_templates' );
		$charset = Db::charset();
		$audit   = Repository::audit_columns();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			event_key varchar(64) NOT NULL default '',
			title varchar(190) NOT NULL default '',
			subject varchar(255) NOT NULL default '',
			body longtext,
			content_type varchar(20) NOT NULL default 'text',
			from_name varchar(190) default NULL,
			from_email varchar(190) default NULL,
			recipient varchar(32) NOT NULL default 'examinee',
			enabled tinyint(1) NOT NULL default 1,
			{$audit}
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY enabled (enabled),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}

	/**
	 * The outbox.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * MAIL IS QUEUED, NEVER SENT INLINE, AND THAT IS A CORRECTNESS DECISION.
	 *
	 * `wp_mail()` blocks on the transport. An SMTP host that has gone slow turns submitting
	 * an exam into a thirty-second request, and one that is refusing connections turns it
	 * into a fatal — after the attempt has been finalised but before the examinee sees their
	 * result. Writing a row and returning is bounded work; the cron flush absorbs the
	 * failure, retries it, and gives up after `MAX_ATTEMPTS` instead of retrying for ever.
	 *
	 * `idx_flush (status, next_attempt_at)` is what the flush query reads. Without it the
	 * flush scans every notification ever sent, which is the largest table on a busy site.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 */
	private static function notification_queue(): string {
		$table   = Db::table( 'notification_queue' );
		$charset = Db::charset();
		$audit   = Repository::audit_columns();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			event_key varchar(64) NOT NULL default '',
			recipient_email varchar(190) NOT NULL default '',
			recipient_name varchar(190) NOT NULL default '',
			subject varchar(255) NOT NULL default '',
			body longtext,
			content_type varchar(20) NOT NULL default 'text',
			from_name varchar(190) default NULL,
			from_email varchar(190) default NULL,
			status varchar(20) NOT NULL default 'pending',
			attempts int(11) NOT NULL default 0,
			next_attempt_at datetime default NULL,
			sent_at datetime default NULL,
			last_error text,
			context_type varchar(32) NOT NULL default '',
			context_id bigint(20) unsigned NOT NULL default 0,
			{$audit}
			PRIMARY KEY  (id),
			KEY idx_flush (status,next_attempt_at),
			KEY event_key (event_key),
			KEY context (context_type,context_id),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}
}
