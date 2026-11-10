<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Alerts\Http;

use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Modules\Alerts\Repositories\AlertRepository;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alerts REST routes.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════════
 * THE ROUTES TAKE NO ID, AND THAT IS THE ACCESS CONTROL.
 *
 * Everywhere else in this plugin a `permission_callback` asks the matrix whether this caller may
 * do this action to this feature. Here there is no question for it to answer: a person reads
 * their own inbox and marks their own inbox read, the user id comes from the session, and there
 * is no route shape that could name somebody else's row. Being signed in IS the permission.
 *
 * That is a narrower guarantee than a capability check and a stronger one — a capability can be
 * granted to the wrong role, whereas "the id comes from the session" cannot be misconfigured.
 * It also means these routes are deliberately reachable by an Examinee, which is the whole
 * point: the learner feed is the surface this table exists for.
 *
 * `feature()` still answers `attempts`, because the base class requires one and every alert
 * this build writes is about an attempt. Nothing here calls `can()`, so it is documentation
 * rather than a decision — but a route added later that DOES need the matrix must think about
 * whether that is still the right feature.
 * ════════════════════════════════════════════════════════════════════════════════════════════
 */
final class AlertsController extends RestController {

	/**
	 * @param AlertRepository $alerts The inboxes.
	 */
	public function __construct( private readonly AlertRepository $alerts ) {}

	protected function feature(): string {
		return 'attempts';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/alerts',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_items' ),
					// Any signed-in user, and no capability: the handler reads the caller's
					// OWN inbox by `get_current_user_id()` and takes no id from the request,
					// so there is nobody else's data it could be asked for.
					'permission_callback' => array( $this, 'is_signed_in' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/alerts/read',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'mark_read' ),
					// Any signed-in user: it marks the caller's OWN alerts read, by
					// `get_current_user_id()`. The request cannot name another person.
					'permission_callback' => array( $this, 'is_signed_in' ),
				),
			)
		);
	}

	/**
	 * Whether there is somebody to have an inbox.
	 *
	 * A guest sitting an exam under a signed cookie is not a user and has no inbox — see the
	 * note in `Services\Notifier`. Refusing here rather than returning an empty list is
	 * deliberate: an empty 200 would have the browser poll a feed that can never fill.
	 */
	public function is_signed_in(): bool {
		return is_user_logged_in();
	}

	/**
	 * The signed-in person's feed, with the unread count beside it.
	 *
	 * The count is in `meta` rather than derived from the rows, because the feed is capped and
	 * a person with forty unread alerts must see forty on the badge and thirty in the list.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_items( $request ): WP_REST_Response {
		unset( $request );

		$user_id = get_current_user_id();

		return $this->ok(
			array_map( array( $this, 'present' ), $this->alerts->inbox( $user_id ) ),
			array( 'unread' => $this->alerts->unread_count( $user_id ) )
		);
	}

	/**
	 * Mark the signed-in person's alerts as seen.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function mark_read( $request ): WP_REST_Response {
		unset( $request );

		$user_id = get_current_user_id();

		return $this->ok(
			array(
				'marked' => $this->alerts->mark_all_read( $user_id ),
				'unread' => $this->alerts->unread_count( $user_id ),
			)
		);
	}

	/**
	 * One alert as the client renders it.
	 *
	 * THE LINK IS RESOLVED HERE, AT READ TIME, and never stored. The owning module answers —
	 * so the feed links correctly to kinds of thing it has never heard of, and a site that
	 * changes its permalink base does not end up with a table full of dead URLs. An unanswered
	 * context yields an empty string, which the client renders as a row that is not clickable
	 * rather than as a row that 404s.
	 *
	 * @param array<string, mixed> $row Stored row.
	 *
	 * @return array<string, mixed>
	 */
	private function present( array $row ): array {
		$context_type = (string) ( $row['context_type'] ?? '' );
		$context_id   = (int) ( $row['context_id'] ?? 0 );

		/**
		 * Where an alert points.
		 *
		 * `alert_type` is passed as well as the context because ONE KIND OF THING HAS TWO
		 * DESTINATIONS: `needs_marking` and `result_ready` are both about an attempt, and they
		 * send a marker to the grading screen and a candidate to their result page. Resolving
		 * on the context alone would have silently sent one of them to the other's page.
		 *
		 * @param string $link         Empty by default.
		 * @param string $context_type Entity type, e.g. `attempt`.
		 * @param int    $context_id   Entity id.
		 * @param string $alert_type   What kind of alert this is.
		 */
		$link = (string) apply_filters(
			'plugixa_exam_alert_link',
			'',
			$context_type,
			$context_id,
			(string) ( $row['alert_type'] ?? '' )
		);

		return array(
			'id'           => (int) $row['id'],
			'alert_type'   => (string) ( $row['alert_type'] ?? '' ),
			'subject'      => (string) ( $row['subject'] ?? '' ),
			'context_type' => $context_type,
			'context_id'   => $context_id,
			'link'         => $link,
			'read_at'      => $row['read_at'] ?? null,
			'created_at'   => $row['created_at'] ?? null,
		);
	}
}
