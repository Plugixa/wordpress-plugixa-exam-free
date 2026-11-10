<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Core\Http;

use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Core\Settings;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read and write plugin settings.
 *
 * The whitelist lives in {@see Settings::save()} rather than here, because it is assembled
 * from every module's declared defaults and a controller cannot see that list. This class
 * only decides who may ask.
 */
final class SettingsController extends RestController {

	/**
	 * @param Settings $settings The settings store.
	 */
	public function __construct( private readonly Settings $settings ) {}

	protected function feature(): string {
		return 'configuration';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_settings' ),
					// Read is deliberately wider than write: the runner's colour variables
					// and the admin's date formats come from here, and every screen needs
					// them. Nothing secret is stored in settings — API keys and the LTI
					// keypair are internal keys the whitelist never exposes.
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'configuration', 'view' ) ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( $this, 'may_configure' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_settings( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		// `public_view()`, NEVER `all()`. The option carries internal state and — once the
		// LTI module is installed — an RSA private key. See `Settings::public_view()`.
		return $this->ok( $this->settings->public_view() );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function save_settings( WP_REST_Request $request ): WP_REST_Response {
		$input = $request->get_json_params();

		// The save response echoes the merged settings, so it needs the same filter as the
		// read. A secret is just as disclosed by a PUT response as by a GET one.
		return $this->ok( $this->settings->public_view( $this->settings->save( is_array( $input ) ? $input : array() ) ) );
	}
}
