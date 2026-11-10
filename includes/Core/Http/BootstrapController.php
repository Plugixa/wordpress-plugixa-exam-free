<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Http;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\AppRegistry;
use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Core\Security\Permissions;
use PlugixaExam\Core\Settings;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One request that seeds the SPA's whole cache.
 *
 * The admin app needs settings, the enabled-app map, the permission matrix and each
 * module's opening dataset before it can render anything useful. Fetched separately that is
 * a waterfall of six or seven round trips, each paying WordPress's full bootstrap cost —
 * which on a site with a few heavy plugins is most of a second *each*, and is why the
 * plugin this pattern comes from measured its admin as slow and found the cause was the
 * number of requests rather than any query.
 *
 * Modules contribute their slice through `plugixa_exam_bootstrap_payload` rather than being
 * enumerated here, so Core does not need to know what a module wants at startup.
 */
final class BootstrapController extends RestController {

	/**
	 * @param Container $container The service container.
	 */
	public function __construct( private readonly Container $container ) {}

	protected function feature(): string {
		return 'exams';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/bootstrap',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_bootstrap' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'exams', 'view' ) ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_bootstrap( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$apps = $this->container->get( AppRegistry::class );

		$payload = array(
			/*
			 * `public_view()`, NEVER `all()` — and this route is the reason that method
			 * exists in the first place having not been enough.
			 *
			 * The rule was written for `GET /settings` and applied to `GET /settings` and to
			 * the save response beside it. This route asks the same store the same question
			 * and was missed, so it went on answering with the LTI RSA PRIVATE KEY, the demo
			 * accounts' shared password and every internal marker — to anybody holding
			 * `exams: view`, which is the lowest role the plugin defines.
			 *
			 * A fix applied where a bug was found rather than everywhere its mechanism
			 * exists is half a fix. There are three callers of `Settings::all()` outside the
			 * class itself; the other two build an allow-list and are safe.
			 */
			'settings'    => $this->container->get( Settings::class )->public_view(),
			'permissions' => Permissions::matrix_for(),
			'apps'        => array(
				'registry' => $apps->registry(),
				'enabled'  => $apps->enabled_map(),
			),
			'modules'     => array_keys( $this->container->get( \PlugixaExam\Core\Modules\ModuleRegistry::class )->all() ),
		);

		/**
		 * Contribute a module's opening dataset to the SPA's first request.
		 *
		 * A listener that throws or is slow delays the whole admin, so keep each slice to
		 * what the first screen genuinely needs — a list's first page, not every row.
		 *
		 * @param array<string, mixed> $payload  Accumulated payload.
		 * @param Container            $container The service container.
		 */
		$payload = (array) apply_filters( 'plugixa_exam_bootstrap_payload', $payload, $this->container );

		return $this->ok( $payload );
	}
}
