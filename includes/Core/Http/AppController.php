<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Http;

use PlugixaExam\Core\Modules\AppRegistry;
use PlugixaExam\Core\Modules\AppUsage;
use PlugixaExam\Core\Security\Capabilities;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The App Manager: switch functional subsystems on and off.
 *
 * The set of ids this will accept is DERIVED from {@see AppRegistry::toggleable()} rather
 * than written out, so a module added later cannot become un-toggleable by omission — or,
 * worse, toggleable when it is core. That was the failure mode in the system this is ported
 * from: its whitelist and its registry were two hand-maintained lists that had to agree.
 */
final class AppController extends RestController {

	/**
	 * @param AppRegistry $apps The derived app registry.
	 */
	public function __construct( private readonly AppRegistry $apps ) {}

	protected function feature(): string {
		return 'configuration';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/apps',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_apps' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'configuration', 'view' ) ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_apps' ),
					'permission_callback' => array( $this, 'may_configure' ),
					'args'                => array(
						'apps' => array(
							'type'     => 'object',
							'required' => true,
						),
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_apps( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$registry = $this->apps->registry();

		return $this->ok(
			array(
				'categories' => AppRegistry::CATEGORIES,
				'registry'   => $registry,
				'enabled'    => $this->apps->enabled_map(),
				/*
				 * What each format-owning app is holding up, so the screen can warn before
				 * a switch-off rather than leaving somebody to find out a term later that
				 * every ordering question now scores zero. Empty for every other app.
				 */
				'in_use'     => ( new AppUsage() )->for_apps( $registry ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function save_apps( WP_REST_Request $request ): WP_REST_Response {
		$input      = (array) $request->get_param( 'apps' );
		$toggleable = $this->apps->toggleable();
		$rejected   = array();

		foreach ( $input as $id => $enabled ) {
			$id = sanitize_key( (string) $id );

			if ( ! in_array( $id, $toggleable, true ) ) {
				$rejected[] = $id;
				continue;
			}

			$this->apps->set( $id, (bool) filter_var( $enabled, FILTER_VALIDATE_BOOL ) );
		}

		return $this->ok(
			array(
				'enabled'  => $this->apps->enabled_map(),
				// Reported rather than swallowed. A client that asked to disable something
				// core should learn that it did not happen, instead of rendering a toggle
				// that springs back on the next load with no explanation.
				'rejected' => $rejected,
			)
		);
	}
}
