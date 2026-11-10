<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Calendar\Http;

use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Modules\Calendar\Services\CalendarSources;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The schedule, as one request.
 *
 * ONE ROUTE, NOT ONE PER SOURCE. The calendar draws a month at a time and needs every
 * source's events for that month together; asking per source would mean four requests to
 * paint one view, arriving out of order, with the sidebar's colours filling in one at a
 * time. The sources are a server-side concern and the client should not have to know how
 * many there are.
 *
 * GATED ON `exams.view`. The calendar is an exam schedule — attempts appear on it as
 * context, not as its subject — so the permission to see the exam list is the right bar for
 * the screen as a whole. Each source then applies its own rule on top: the Attempts source
 * contributes nothing to somebody who may not view attempts, so a role with `exams.view`
 * alone sees the windows and not who sat them. That is why the permission check is in two
 * places and neither is redundant.
 */
final class CalendarController extends RestController {

	/**
	 * @param CalendarSources $sources The source registry.
	 */
	public function __construct( private readonly CalendarSources $sources ) {}

	protected function feature(): string {
		return 'exams';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/calendar',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_calendar' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'exams', 'view' ) ),
					'args'                => array(
						'from'    => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'to'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'sources' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Sources and events for a window.
	 *
	 * Both in one payload because the client needs them together — an event whose source is
	 * unknown cannot be coloured or filtered, and a source list without events is a legend
	 * for nothing.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_calendar( WP_REST_Request $request ): WP_REST_Response {
		list( $from, $to ) = $this->sources->window(
			(string) ( $request->get_param( 'from' ) ?? '' ),
			(string) ( $request->get_param( 'to' ) ?? '' )
		);

		$only = $request->get_param( 'sources' );
		$only = is_array( $only ) ? array_map( 'strval', $only ) : array();

		return $this->ok(
			array(
				'sources' => $this->sources->sources(),
				'events'  => $this->sources->events( $from, $to, $only ),
			),
			array(
				'from' => $from,
				'to'   => $to,
			)
		);
	}
}
