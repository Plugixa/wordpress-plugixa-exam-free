<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Notifications;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Core\Settings;
use PlugixaExam\Modules\Attempts\Repositories\AttemptRepository;
use PlugixaExam\Modules\Notifications\Http\NotificationsController;
use PlugixaExam\Modules\Notifications\Privacy\NotificationPrivacy;
use PlugixaExam\Modules\Notifications\Repositories\QueueRepository;
use PlugixaExam\Modules\Notifications\Repositories\TemplateRepository;
use PlugixaExam\Modules\Notifications\Services\AttemptNotifier;
use PlugixaExam\Modules\Notifications\Services\Mailer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email — the templates, and the queue that sends them.
 *
 * Switchable off. With the app disabled nothing is queued and nothing is sent, but the
 * templates and the outbox stay in the database: switching it back on resumes with the same
 * wording an administrator wrote, and any message that was still pending goes out.
 */
final class Module implements ModuleInterface {

	/** Cron event that empties the outbox. */
	public const FLUSH_HOOK = 'plugixa_exam_flush_notifications';

	public function id(): string {
		return 'notifications';
	}

	public function app(): array {
		return array(
			'category' => 'integrations',
			'setting'  => 'app_notifications',
			'default'  => true,
			'deps'     => array(),
		);
	}

	public function migrate(): void {
		Schema::install();

		/*
		 * Seeded on every migration, not only on first install. A premium module added later
		 * contributes its templates through the filter, and they must appear without the
		 * site being reinstalled. `seed()` never overwrites an existing row — see its
		 * docblock for why that matters.
		 */
		\PlugixaExam\Core\Plugin::instance()->container()->get( TemplateRepository::class )->seed();
	}

	public function capabilities(): array {
		return array();
	}

	public function register_services( Container $container ): void {
		unset( $container );
	}

	public function register_routes( Container $container ): void {
		$container->get( NotificationsController::class )->register_routes();
	}

	public function register_hooks( Container $container ): void {
		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );
		add_filter( 'plugixa_exam_settings_defaults', array( $this, 'settings_defaults' ) );

		/*
		 * The outbox is a mailbox: it holds who was written to, and what was said to them.
		 * Registered with no settings check of any kind, for the same reason the Attempts
		 * one is — switching notifications off must not also switch off the site's ability
		 * to answer for the mail it has already sent.
		 */
		$container->get( NotificationPrivacy::class )->register_hooks();

		$notifier = new AttemptNotifier(
			$container->get( Mailer::class ),
			$container->get( AttemptRepository::class ),
			$container->get( Settings::class )
		);

		add_action( 'plugixa_exam_attempt_submitted', array( $notifier, 'on_submitted' ), 10, 3 );
		add_action( 'plugixa_exam_attempt_graded', array( $notifier, 'on_graded' ), 10, 2 );

		$mailer = $container->get( Mailer::class );

		add_action(
			self::FLUSH_HOOK,
			static function () use ( $mailer, $container ): void {
				$mailer->flush( 20 );

				// Keep the outbox from growing without bound. Only successfully sent
				// messages go; a failure is evidence and stays until somebody looks at it.
				$container->get( QueueRepository::class )->prune( 30 );
			}
		);

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * THE FILTER GOES FIRST. IT USED TO GO LAST, AND THE EVENT WAS NEVER SCHEDULED.
		 *
		 * `wp_schedule_event()` validates its recurrence against `wp_get_schedules()`, which
		 * is `cron_schedules` applied. Registered after the call, `plugixa_exam_five_minutes`
		 * did not exist yet at the moment WordPress checked, so every attempt returned
		 * `invalid_schedule` — and because the guard above only asks whether the event is
		 * scheduled, it failed again on the next request, and the next, for ever.
		 *
		 * The outbox was therefore drained only by the three manual calls in
		 * `NotificationsController` — which is to say, only when an administrator happened to
		 * open the notifications screen. On a site nobody watches, "your result is ready" sat
		 * in the queue indefinitely. Every test passed: the suite calls `Mailer::flush()`
		 * directly, and an E2E run that opens the admin screen flushes it on the way past.
		 *
		 * Found by reading `wp cron event list` on the test site and noticing that
		 * `plugixa_exam_webhooks_flush` was there and this one was not — Webhooks uses the
		 * same five-minute schedule and works only because it registers AFTER this module,
		 * by which time this filter exists. Ordering by luck is what made one work and the
		 * other not.
		 * ════════════════════════════════════════════════════════════════════════════════
		 */
		add_filter( 'cron_schedules', array( $this, 'cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- five minutes, for outbound mail only.

		/*
		 * Five minutes, not hourly. This is the delay between finishing an exam and
		 * being told the result is ready, and an hour of silence reads as the site
		 * having lost the submission. The queue is indexed for exactly this query, so a
		 * pass that finds nothing costs one indexed lookup.
		 */
		\PlugixaExam\Core\Cron::ensure( self::FLUSH_HOOK, 'plugixa_exam_five_minutes', MINUTE_IN_SECONDS );
	}

	/**
	 * The five-minute interval the flush runs on.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing schedules.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function cron_schedule( array $schedules ): array {
		$schedules['plugixa_exam_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (Plugixa Exam)', 'plugixa-exam' ),
		);

		return $schedules;
	}

	/**
	 * @param array<string, mixed> $defaults Accumulated defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_defaults( array $defaults ): array {
		return array_merge(
			$defaults,
			array(
				'app_notifications'         => true,
				'notifications_enabled'     => true,
				// Empty means the site's own admin address, read at send time rather than
				// copied here — see `TemplateRepository::email_templates()`.
				'notifications_admin_email' => '',
			)
		);
	}

	/**
	 * @param array<string, string> $strings Accumulated strings.
	 *
	 * @return array<string, string>
	 */
	public function strings( array $strings ): array {
		return array_merge(
			$strings,
			array(
				'notifications'         => __( 'Email', 'plugixa-exam' ),
				'notificationsDesc' => __( 'Email templates, and the queue that sends them.', 'plugixa-exam' ),
				'notificationsIntro'    => __( 'The messages this site sends, and anything waiting to go out.', 'plugixa-exam' ),
				'emailTemplates'        => __( 'Templates', 'plugixa-exam' ),
				'emailQueue'            => __( 'Outbox', 'plugixa-exam' ),
				'emailSubject'          => __( 'Subject', 'plugixa-exam' ),
				'emailBody'             => __( 'Message', 'plugixa-exam' ),
				'emailFromName'         => __( 'From name', 'plugixa-exam' ),
				'emailFromAddress'      => __( 'From address', 'plugixa-exam' ),
				'emailFromHelp'         => __( 'Leave empty to send as the site.', 'plugixa-exam' ),
				'emailEnabled'          => __( 'Send this message', 'plugixa-exam' ),
				'emailRecipient'        => __( 'Sent to', 'plugixa-exam' ),
				'recipientExaminee'     => __( 'The examinee', 'plugixa-exam' ),
				'recipientAdmin'        => __( 'Staff', 'plugixa-exam' ),
				'sendTest'              => __( 'Send a test', 'plugixa-exam' ),
				'testSent'              => __( 'Test message queued', 'plugixa-exam' ),
				'availableTokens'       => __( 'Available tokens', 'plugixa-exam' ),
				'scoreTokensHeld'       => __( 'Score tokens are left blank until the exam releases its results, so a message sent before then cannot give the mark away.', 'plugixa-exam' ),
				'queuePending'          => __( 'Waiting', 'plugixa-exam' ),
				'queueSent'             => __( 'Sent', 'plugixa-exam' ),
				'queueFailed'           => __( 'Failed', 'plugixa-exam' ),
				'queueRetry'            => __( 'Try again', 'plugixa-exam' ),
				'queueEmpty'            => __( 'Nothing is waiting to be sent.', 'plugixa-exam' ),
				'queueLastError'        => __( 'Last error', 'plugixa-exam' ),
				'flushNow'              => __( 'Send now', 'plugixa-exam' ),
				'notificationsDisabled' => __( 'Email is switched off, so nothing is being queued or sent.', 'plugixa-exam' ),
				'emailFormat'     => __( 'Format', 'plugixa-exam' ),
				'emailFormatHelp' => __( 'HTML lets you use headings, links and tables. Plain text arrives the same everywhere.', 'plugixa-exam' ),
				'emailFormatHtml' => __( 'HTML', 'plugixa-exam' ),
				'emailFormatText' => __( 'Plain text', 'plugixa-exam' ),
				// `{sent}` and `{failed}` rather than `%d, %d`: the client substituted them
				// with two sequential `.replace()` calls, so the numbers were positional and
				// a translation that reordered them would have reported the wrong figures.
				'flushed'         => __( 'Sent {sent}, {failed} failed', 'plugixa-exam' ),
				'noTemplates'     => __( 'No templates yet.', 'plugixa-exam' ),
				'off'             => __( 'Off', 'plugixa-exam' ),
				'tries'           => __( 'Tries', 'plugixa-exam' ),
			)
		);
	}
}
