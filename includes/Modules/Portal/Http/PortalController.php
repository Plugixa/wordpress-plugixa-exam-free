<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Portal\Http;

use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Modules\Portal\Services\Portal;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The learner's own dashboard.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THIS ROUTE SERVES THE CALLER THEMSELVES. THERE IS NO USER PARAMETER.
 *
 * The subject is always `get_current_user_id()`, never an id from the request. A portal
 * route taking a user id is a portal route somebody will eventually call with somebody
 * else's, and no permission check on this feature could be right — a learner is allowed to
 * read their own results and nobody else's, which is not a capability, it is an identity.
 *
 * So the permission callback is "are you signed in", and the identity comes from the
 * session rather than the payload. Any module extending the payload through
 * `plugixa_exam_portal_overview` inherits that guarantee, because it is handed the same
 * user id rather than choosing one.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * `feature()` returns `attempts` only because `RestController` requires one for its `can()`
 * helper. Nothing here uses it: this route is not gated on a plugin permission, because it
 * is not an administrative action.
 */
final class PortalController extends RestController {

	/**
	 * @param Portal $portal The learner's overview.
	 */
	public function __construct( private readonly Portal $portal ) {}

	protected function feature(): string {
		return 'attempts';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/portal',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_overview' ),
					// Any signed-in user, and no capability: a learner is not staff. The
					// handler answers about the caller alone — their own exams, results and
					// certificates, by `get_current_user_id()` — and takes no id.
					'permission_callback' => static fn (): bool => get_current_user_id() > 0,
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_overview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );

		return $this->ok( $this->portal->overview( get_current_user_id() ) );
	}
}
