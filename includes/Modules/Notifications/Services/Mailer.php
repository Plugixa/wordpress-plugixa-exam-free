<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Notifications\Services;

use PlugixaExam\Core\Settings;
use PlugixaExam\Modules\Notifications\Repositories\QueueRepository;
use PlugixaExam\Modules\Notifications\Repositories\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an event into a queued message, and the queue into sent mail.
 */
final class Mailer {

	/**
	 * @param TemplateRepository $templates The editable templates.
	 * @param QueueRepository    $queue     The outbox.
	 * @param Settings           $settings  For the global on/off and the from address.
	 */
	public function __construct(
		private readonly TemplateRepository $templates,
		private readonly QueueRepository $queue,
		private readonly Settings $settings
	) {}

	/**
	 * Queue one message.
	 *
	 * Returns the queue row id, or 0 when nothing was queued — which is the ordinary outcome
	 * for a disabled template or an event with no address to send to, not an error.
	 *
	 * @param string               $event_key Which template.
	 * @param string               $email     Recipient address.
	 * @param string               $name      Recipient name.
	 * @param array<string, mixed> $tokens    Token values.
	 * @param string               $context   Entity type this concerns.
	 * @param int                  $context_id Entity id.
	 * @param string[]             $blanked   Tokens deliberately emptied, whose lines go too.
	 */
	/**
	 * Run `$body` with the locale of whoever `$email` belongs to.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * A NOTIFICATION BELONGS TO ITS READER, NOT TO WHOEVER CAUSED IT.
	 *
	 * Without this, the locale in force when a message is composed is whatever the request
	 * happened to be in: the candidate who submitted, the marker who released a result, or
	 * the SITE DEFAULT when WP-Cron drains the auto-close sweep. None of those is reliably
	 * the person the mail is addressed to, and on a multilingual site all three are wrong
	 * for somebody.
	 *
	 * ⚠️ WHAT THIS REACHES, because it is easy to overstate. Everything computed INSIDE the
	 * callback: `wp_date()` for `{submitted}`, `__( 'Passed' )` for `{outcome}`,
	 * `number_format_i18n()`. It does NOT reach the subject and body TEXT, which come from
	 * a database row and are in whatever single language that row holds — per-locale email
	 * content is a translation of a ROW, which no gettext catalogue can express, and which
	 * belongs to the Multilingual module.
	 *
	 * A GUEST HAS NO PREFERENCE, and for them the site default is not a fallback but the
	 * correct answer — an examinee who sat an open exam by link has told us nothing about
	 * what language they read.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string   $email Recipient address.
	 * @param callable $body  What to compose.
	 */
	public static function in_recipient_locale( string $email, callable $body ): mixed {
		$recipient = get_user_by( 'email', sanitize_email( $email ) );
		$locale    = $recipient instanceof \WP_User ? get_user_locale( $recipient ) : '';

		// `switch_to_locale()` is global state; the `finally` is what keeps a failed render
		// from leaving every later request in somebody else's language.
		$switched = '' !== $locale && $locale !== determine_locale() && switch_to_locale( $locale );

		try {
			return $body();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	public function queue(
		string $event_key,
		string $email,
		string $name,
		array $tokens,
		string $context = '',
		int $context_id = 0,
		array $blanked = array()
	): int {
		if ( ! $this->settings->bool( 'notifications_enabled', true ) ) {
			return 0;
		}

		$email = sanitize_email( $email );

		// Nothing to send to. A guest who sat an open exam without leaving an address is the
		// normal case, not a failure worth logging.
		if ( '' === $email || ! is_email( $email ) ) {
			return 0;
		}

		$template = $this->templates->find_by_event( $event_key );

		if ( null === $template || empty( $template['enabled'] ) ) {
			return 0;
		}

		// The format travels WITH the message, not looked up at send time. A template edited
		// between queueing and the next cron run must not change the header on a body that was
		// already rendered — that is how a plain-text message goes out claiming to be HTML.
		$html = 'html' === (string) ( $template['content_type'] ?? 'text' );

		/*
		 * Rendered NOW, and in the READER's language. See `in_recipient_locale()` for what
		 * that reaches and — more importantly — what it does not.
		 *
		 * This covers `Tokens::apply()`, which is the substitution pass. The token VALUES
		 * are built by the caller, before this method is reached, so a caller that wants
		 * its dates and its `{outcome}` in the reader's language has to wrap its own
		 * `Tokens::for_attempt()` call too — which `AttemptNotifier` does, and which is
		 * where the real work happens.
		 */
		list( $subject, $body ) = self::in_recipient_locale(
			$email,
			static fn (): array => array(
				Tokens::apply( (string) $template['subject'], $tokens ),
				Tokens::apply( (string) $template['body'], $tokens, $blanked, $html ),
			)
		);

		return $this->queue->insert(
			array(
				'event_key'       => $event_key,
				'recipient_email' => $email,
				'recipient_name'  => sanitize_text_field( $name ),
				'content_type'    => $html ? 'html' : 'text',
				// Rendered NOW, not at send time. The template may be edited, or the exam
				// deleted, between queueing and the next cron run — and a message must say
				// what it said when the thing happened.
				//
				// The SUBJECT is not line-filtered: a subject is one line, so dropping it would
				// leave the message with none at all — worse than one that names a score the
				// body then declines to give.
				//
				// The subject is rendered as PLAIN TEXT even for an HTML message, because a
				// subject line is not markup — escaping it would put `&amp;` in somebody's
				// inbox header.
				'subject'         => $subject,
				'body'            => $body,
				'from_name'       => $template['from_name'],
				'from_email'      => $template['from_email'],
				'status'          => 'pending',
				'context_type'    => sanitize_key( $context ),
				'context_id'      => $context_id,
			)
		);
	}

	/**
	 * Send what is due.
	 *
	 * @param int $limit How many to attempt in one pass.
	 *
	 * @return array{sent: int, failed: int}
	 */
	public function flush( int $limit = 20 ): array {
		$sent   = 0;
		$failed = 0;

		foreach ( $this->queue->due( $limit ) as $message ) {
			if ( $this->send( $message ) ) {
				++$sent;

				continue;
			}

			++$failed;
		}

		return array( 'sent' => $sent, 'failed' => $failed );
	}

	/**
	 * Attempt one message.
	 *
	 * @param array<string, mixed> $message Queue row.
	 */
	private function send( array $message ): bool {
		$id = (int) $message['id'];

		$from_email = (string) ( $message['from_email'] ?? '' );
		$from_name  = (string) ( $message['from_name'] ?? '' );

		// Follows the ROW, not a constant. A queue row written before this column existed reads
		// as `text`, which is what it is.
		$headers = array(
			'html' === (string) ( $message['content_type'] ?? 'text' )
				? 'Content-Type: text/html; charset=UTF-8'
				: 'Content-Type: text/plain; charset=UTF-8',
		);

		if ( '' !== $from_email && is_email( $from_email ) ) {
			$headers[] = sprintf(
				'From: %s <%s>',
				'' === $from_name ? (string) get_bloginfo( 'name' ) : $from_name,
				$from_email
			);
		}

		/*
		 * `wp_mail` reports a boolean and raises `wp_mail_failed` with the detail. Capturing
		 * the action is the only way to record WHY a message failed — without it the queue
		 * shows five identical failures and no way to tell a bad address from a dead SMTP
		 * host. Unhooked immediately so this listener never sees another module's mail.
		 */
		$error = '';

		$capture = static function ( $wp_error ) use ( &$error ): void {
			$error = $wp_error instanceof \WP_Error ? $wp_error->get_error_message() : '';
		};

		add_action( 'wp_mail_failed', $capture );

		$ok = wp_mail(
			(string) $message['recipient_email'],
			(string) $message['subject'],
			(string) $message['body'],
			$headers
		);

		remove_action( 'wp_mail_failed', $capture );

		if ( $ok ) {
			$this->queue->mark_sent( $id );

			return true;
		}

		$this->queue->mark_failed( $id, '' === $error ? __( 'The site could not send the message.', 'plugixa-exam' ) : $error );

		return false;
	}
}
