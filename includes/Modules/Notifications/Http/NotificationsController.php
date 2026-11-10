<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Notifications\Http;

use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Core\Security\Permissions;
use PlugixaExam\Modules\Notifications\Repositories\QueueRepository;
use PlugixaExam\Modules\Notifications\Repositories\TemplateRepository;
use PlugixaExam\Modules\Notifications\Services\Mailer;
use PlugixaExam\Modules\Notifications\Services\Tokens;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Templates and outbox routes.
 *
 * Every route here is gated on `configuration.manage_notifications`. Editing what the site
 * says to its examinees, and reading an outbox that contains their names and addresses, is
 * configuration rather than content — a Grader has no business in either.
 */
final class NotificationsController extends RestController {

	/**
	 * @param TemplateRepository $templates The editable templates.
	 * @param QueueRepository    $queue     The outbox.
	 * @param Mailer             $mailer    For the test send and the manual flush.
	 */
	public function __construct(
		private readonly TemplateRepository $templates,
		private readonly QueueRepository $queue,
		private readonly Mailer $mailer
	) {}

	protected function feature(): string {
		return 'configuration';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/notifications/templates',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_templates' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'configuration', 'manage_notifications' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/templates/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_template' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'configuration', 'manage_notifications' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/templates/(?P<id>[\d]+)/test',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'send_test' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'configuration', 'manage_notifications' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/queue',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_queue' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'configuration', 'manage_notifications' ) ),
					'args'                => array_merge(
						$this->get_collection_params(),
						array( 'status' => array( 'type' => 'string', 'enum' => array( 'pending', 'sent', 'failed' ) ) )
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/queue/(?P<id>[\d]+)/retry',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'retry' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'configuration', 'manage_notifications' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/notifications/flush',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'flush' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'configuration', 'manage_notifications' ) ),
				),
			)
		);
	}

	/**
	 * Every template, with the tokens that work in it.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_templates( $request ): WP_REST_Response|WP_Error {
		unset( $request );

		return $this->ok(
			array(
				'templates' => $this->templates->query()->order_by( 't.id', 'asc' )->paginate( 100 )->get(),
				'counts'    => $this->queue->counts(),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function update_template( $request ): WP_REST_Response|WP_Error {
		$id  = (int) $request['id'];
		$row = $this->templates->find( $id );

		if ( null === $row ) {
			return $this->not_found();
		}

		$body = (array) $request->get_json_params();

		/*
		 * THE FORMAT DECIDES THE SANITISER, and the payload may not omit it into the wrong one.
		 *
		 * A body sanitised as plain text and then sent as `text/html` is a body whose markup
		 * was stripped; a body sanitised as HTML and sent as `text/plain` is angle brackets in
		 * somebody's inbox. So the two are read together, and an absent `content_type` keeps
		 * whatever the row already says rather than defaulting — a partial PUT that omits it
		 * must not silently convert an HTML template back to text and take its markup with it
		 * on the way through.
		 */
		$content_type = 'html' === (string) ( $body['content_type'] ?? $row['content_type'] ?? 'text' )
			? 'html'
			: 'text';

		$data = array(
			'subject'      => sanitize_text_field( (string) ( $body['subject'] ?? $row['subject'] ) ),
			/*
			 * A plain-text body is sanitised as text; an HTML one goes through `wp_kses` with
			 * the `email` allow-list, which exists for exactly this and for certificate
			 * templates — it is the `stem` list plus the table attributes an email client
			 * needs, because mail is still laid out with tables in 2026.
			 */
			'body'         => 'html' === $content_type
				? wp_kses( (string) ( $body['body'] ?? $row['body'] ), plugixa_exam_allowed_html( 'email' ) )
				: sanitize_textarea_field( (string) ( $body['body'] ?? $row['body'] ) ),
			'content_type' => $content_type,
			'enabled'      => empty( $body['enabled'] ) ? 0 : 1,
		);

		// Empty means "use the site's" and must survive as NULL — writing '' would make the
		// mailer build a `From:` header with no address in it.
		foreach ( array( 'from_name', 'from_email' ) as $field ) {
			if ( ! array_key_exists( $field, $body ) ) {
				continue;
			}

			$value = trim( (string) $body[ $field ] );

			if ( 'from_email' === $field && '' !== $value && ! is_email( $value ) ) {
				return $this->fail(
					'plugixa_exam_bad_email',
					__( 'That is not a valid email address.', 'plugixa-exam' ),
					400
				);
			}

			$data[ $field ] = '' === $value ? null : sanitize_text_field( $value );
		}

		$this->templates->update( $id, $data );

		return $this->ok( $this->templates->find( $id ) );
	}

	/**
	 * Queue a sample of one template to the current user.
	 *
	 * ────────────────────────────────────────────────────────────────────────────────────
	 * SENDS TO THE SIGNED-IN USER, NOT TO AN ADDRESS IN THE REQUEST.
	 *
	 * Accepting a `to` parameter would make this an open relay for anybody holding the
	 * capability: arbitrary subject, arbitrary body, arbitrary recipient, sent from the
	 * site's own domain and reputation. The only address it will use is the one WordPress
	 * already has for the person clicking the button.
	 * ────────────────────────────────────────────────────────────────────────────────────
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function send_test( $request ): WP_REST_Response|WP_Error {
		$template = $this->templates->find( (int) $request['id'] );

		if ( null === $template ) {
			return $this->not_found();
		}

		$user = wp_get_current_user();

		if ( ! $user instanceof \WP_User || '' === (string) $user->user_email ) {
			return $this->fail(
				'plugixa_exam_no_address',
				__( 'Your account has no email address to send a test to.', 'plugixa-exam' ),
				400
			);
		}

		$queued = $this->queue->insert(
			array(
				'event_key'       => (string) $template['event_key'],
				'recipient_email' => (string) $user->user_email,
				'recipient_name'  => (string) $user->display_name,
				'subject'         => Tokens::apply( (string) $template['subject'], self::sample() ),
				'body'            => Tokens::apply( (string) $template['body'], self::sample() ),
				'from_name'       => $template['from_name'],
				'from_email'      => $template['from_email'],
				'status'          => 'pending',
				'context_type'    => 'test',
			)
		);

		// Sent immediately rather than left for cron: somebody pressing "send a test" is
		// waiting to see whether mail works at all, and "it will arrive within five minutes"
		// does not answer that question.
		$this->mailer->flush( 5 );

		return $this->ok( array( 'queued' => $queued > 0, 'to' => $user->user_email ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_queue( $request ): WP_REST_Response|WP_Error {
		return $this->paged_list(
			$request,
			$this->queue,
			array( 't.recipient_email', 't.subject' ),
			static function ( \PlugixaExam\Core\Database\QueryBuilder $query, WP_REST_Request $req ): void {
				$status = sanitize_key( (string) ( $req->get_param( 'status' ) ?? '' ) );

				if ( '' !== $status ) {
					$query->where( 't.status = %s', array( $status ) );
				}
			}
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function retry( $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->queue->find( $id ) ) {
			return $this->not_found();
		}

		$this->queue->retry( $id );
		$this->mailer->flush( 5 );

		return $this->ok( $this->queue->find( $id ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function flush( $request ): WP_REST_Response|WP_Error {
		unset( $request );

		return $this->ok( $this->mailer->flush( 50 ) );
	}

	/**
	 * Plausible values for a test message.
	 *
	 * `released` is true, because the point of a test is to show an administrator what a
	 * fully-populated message looks like. Nothing real is disclosed — every value here is
	 * invented.
	 *
	 * @return array<string, string>
	 */
	private static function sample(): array {
		return array(
			'site_name'  => (string) get_bloginfo( 'name' ),
			'site_url'   => (string) home_url( '/' ),
			'name'       => __( 'Sam Example', 'plugixa-exam' ),
			'exam_title' => __( 'Sample Exam', 'plugixa-exam' ),
			'attempt_id' => '1',
			'submitted'  => (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'started'    => (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			'result_url' => (string) home_url( '/' ),
			'score'      => '17',
			'max_score'  => '20',
			'percent'    => '85',
			'pass_mark'  => '50',
			'outcome'    => __( 'Passed', 'plugixa-exam' ),
		);
	}
}
