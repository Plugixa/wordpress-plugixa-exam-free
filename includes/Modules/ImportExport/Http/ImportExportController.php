<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\ImportExport\Http;

use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Modules\ImportExport\Services\Exporter;
use PlugixaExam\Modules\ImportExport\Services\Importer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import and export routes.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * IMPORT NEEDS `questions.create`; EXPORT NEEDS `reports.export`, NOT `questions.view`.
 *
 * Reading one question on screen and downloading the entire bank as a file are different
 * acts. A Grader can legitimately see a question while marking; handing them a single file
 * containing every question and every answer key is how a bank leaks. `reports.export`
 * exists for exactly this distinction and is not granted to the seeded Grader role.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class ImportExportController extends RestController {

	/**
	 * @param Importer $importer The reader.
	 * @param Exporter $exporter The writer.
	 */
	public function __construct(
		private readonly Importer $importer,
		private readonly Exporter $exporter
	) {}

	protected function feature(): string {
		return 'questions';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/import/formats',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_formats' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'create' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/import/inspect',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'inspect' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'create' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/import/run',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'run' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'questions', 'create' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/export',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'export' ),
					// Not `questions.view`. See the class docblock.
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'reports', 'export' ) ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function get_formats( $request ): WP_REST_Response|WP_Error {
		unset( $request );

		return $this->ok(
			array(
				'import'   => $this->importer->formats(),
				'export'   => Exporter::formats(),
				'maxBytes' => Importer::MAX_BYTES,
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function inspect( $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();

		$result = $this->importer->inspect(
			self::content( $body ),
			sanitize_file_name( (string) ( $body['filename'] ?? '' ) ),
			sanitize_key( (string) ( $body['format'] ?? '' ) )
		);

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function run( $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();

		$result = $this->importer->run(
			self::content( $body ),
			sanitize_file_name( (string) ( $body['filename'] ?? '' ) ),
			sanitize_key( (string) ( $body['format'] ?? '' ) ),
			absint( $body['subject_id'] ?? 0 ),
			! isset( $body['published'] ) || ! empty( $body['published'] )
		);

		return $result instanceof WP_Error ? $result : $this->ok( $result );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function export( $request ): WP_REST_Response|WP_Error {
		$body = (array) $request->get_json_params();

		$format = sanitize_key( (string) ( $body['format'] ?? 'csv' ) );

		if ( ! array_key_exists( $format, Exporter::formats() ) ) {
			return $this->fail( 'plugixa_exam_unknown_format', __( 'That export format is not available.', 'plugixa-exam' ), 400 );
		}

		$file = $this->exporter->export(
			$format,
			array_map( 'absint', (array) ( $body['ids'] ?? array() ) ),
			absint( $body['subject_id'] ?? 0 )
		);

		/*
		 * Returned as JSON rather than streamed as a download.
		 *
		 * A REST download would have to be a GET so the browser could navigate to it, which
		 * puts the whole request in the address bar and in the server log. Handing the body
		 * back and letting the page build a Blob keeps it a POST, keeps the nonce in a
		 * header, and keeps the filename under the client's control.
		 */
		return $this->ok( $file );
	}

	/**
	 * The uploaded file's text.
	 *
	 * Sent as a base64 data payload rather than multipart, because the admin app talks JSON
	 * everywhere else and a second transport for one screen is a second thing to get wrong.
	 * Decoded strictly: anything that is not valid base64 is treated as plain text, which is
	 * what a hand-pasted file body looks like.
	 *
	 * @param array<string, mixed> $body Request body.
	 */
	private static function content( array $body ): string {
		$raw = (string) ( $body['content'] ?? '' );

		if ( ! empty( $body['base64'] ) ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- TRANSPORT, not obfuscation: an uploaded .csv/.xml is sent as base64 in a JSON body because JSON cannot carry arbitrary bytes. Strict mode, and the result is parsed, never executed.
			$decoded = base64_decode( $raw, true );

			return false === $decoded ? '' : $decoded;
		}

		return $raw;
	}
}
