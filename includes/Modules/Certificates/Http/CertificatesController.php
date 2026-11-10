<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Certificates\Http;

use PlugixaExam\Core\Http\RestController;
use PlugixaExam\Core\Security\Capabilities;
use PlugixaExam\Modules\Certificates\Repositories\CertificateRepository;
use PlugixaExam\Modules\Certificates\Repositories\TemplateRepository;
use PlugixaExam\Modules\Certificates\Services\Issuer;
use PlugixaExam\Modules\Certificates\Services\Renderer;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate routes.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * ONE ROUTE HERE IS PUBLIC, AND IT IS AUTHORISED BY THE TOKEN ITSELF.
 *
 * `/certificates/verify/<token>/pdf` serves the document to whoever holds the link. That is
 * not a gap — it is what a certificate is. The holder emails it to an employer, who has no
 * account here and never will. Requiring a login would mean the only people who can read a
 * certificate are the people who did not need to.
 *
 * Its `permission_callback` is still a real decision rather than `__return_true`: it looks
 * the token up and refuses unless it names a certificate that exists and has not been
 * withdrawn. A withdrawn certificate stops being downloadable at the same moment it stops
 * verifying, which is the whole point of withdrawing it — and it says so, with a 410, for
 * the reasons `Frontend\VerifyBridge` sets out.
 *
 * Everything else is administration and goes through the role matrix.
 * ════════════════════════════════════════════════════════════════════════════════════════
 */
final class CertificatesController extends RestController {

	/**
	 * @param CertificateRepository $certificates Issued certificates.
	 * @param TemplateRepository    $templates    Designs.
	 * @param Issuer                $issuer       For re-rendering values.
	 * @param Renderer              $renderer     HTML → PDF.
	 */
	public function __construct(
		private readonly CertificateRepository $certificates,
		private readonly TemplateRepository $templates,
		private readonly Issuer $issuer,
		private readonly Renderer $renderer
	) {}

	protected function feature(): string {
		return 'certificates';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/certificates',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'certificates', 'view' ) ),
					'args'                => $this->get_collection_params(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/certificates/(?P<id>[\d]+)/revoke',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'revoke' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'certificates', 'revoke' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/certificates/(?P<id>[\d]+)/restore',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'restore' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'certificates', 'revoke' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/certificates/templates',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_templates' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'certificates', 'view' ) ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'save_template' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'certificates', 'manage_templates' ) ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/certificates/templates/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'save_template' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'certificates', 'manage_templates' ) ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_template' ),
					'permission_callback' => fn () => $this->allow( current_user_can( Capabilities::ACCESS, 'certificates', 'manage_templates' ) ),
				),
			)
		);

		/*
		 * The public one. See the class docblock: the token IS the authorisation, and the
		 * callback below is a genuine check rather than a rubber stamp.
		 */
		register_rest_route(
			$this->namespace,
			'/certificates/verify/(?P<token>[A-Za-z0-9]{8,64})/pdf',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'download' ),
					'permission_callback' => array( $this, 'may_download' ),
				),
			)
		);
	}

	/**
	 * Whether a verification token may be exchanged for the document.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function may_download( WP_REST_Request $request ): bool|WP_Error {
		$certificate = $this->certificates->find_by_token( (string) $request->get_param( 'token' ) );

		if ( null === $certificate ) {
			return $this->not_found();
		}

		if ( ! empty( $certificate['revoked'] ) ) {
			/*
			 * A DISTINCT ANSWER, MATCHING THE VERIFICATION PAGE. That page already tells a
			 * holder their certificate was withdrawn, so hiding it here would buy no secrecy
			 * and cost every caller a misleading 404 to debug. 410 Gone is the accurate code:
			 * it existed, it is deliberately no longer available.
			 */
			return $this->fail(
				'plugixa_exam_certificate_revoked',
				__( 'This certificate has been withdrawn and can no longer be downloaded.', 'plugixa-exam' ),
				410
			);
		}

		return true;
	}

	/**
	 * A page of issued certificates.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->paged_list(
			$request,
			$this->certificates,
			array( 't.number', 't.recipient_name' ),
			function ( $query, WP_REST_Request $request ): ?WP_Error {
				$exam = absint( $request->get_param( 'exam_id' ) );

				if ( $exam > 0 ) {
					$query->where( 't.exam_id = %d', array( $exam ) );
				}

				return null;
			},
			fn( array $row ): array => $this->decorate( $row )
		);
	}

	/**
	 * Withdraw a certificate.
	 *
	 * NOT A DELETE. The row stays, the number stays reserved, and the reason is recorded —
	 * because "this certificate was withdrawn on the 3rd because the result was overturned"
	 * is a far more useful answer to somebody holding a printout than "no such certificate",
	 * and because re-issuing the number later would be worse than any of it.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function revoke( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = absint( $request->get_param( 'id' ) );

		if ( null === $this->certificates->find( $id ) ) {
			return $this->not_found();
		}

		$this->certificates->update(
			$id,
			array(
				'revoked'       => 1,
				'revoked_at'    => current_time( 'mysql', true ),
				'revoked_by'    => get_current_user_id(),
				'revoke_reason' => sanitize_text_field( (string) $request->get_param( 'reason' ) ),
			)
		);

		return $this->ok( $this->decorate( (array) $this->certificates->find( $id ) ) );
	}

	/**
	 * Put a withdrawn certificate back.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function restore( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = absint( $request->get_param( 'id' ) );

		if ( null === $this->certificates->find( $id ) ) {
			return $this->not_found();
		}

		$this->certificates->update(
			$id,
			array(
				'revoked'       => 0,
				'revoked_at'    => null,
				'revoked_by'    => null,
				'revoke_reason' => '',
			)
		);

		return $this->ok( $this->decorate( (array) $this->certificates->find( $id ) ) );
	}

	/**
	 * The certificate as a PDF, rendered from its frozen snapshot.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function download( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$certificate = $this->certificates->find_by_token( (string) $request->get_param( 'token' ) );

		if ( null === $certificate ) {
			return $this->not_found();
		}

		$html = (string) ( $certificate['snapshot_html'] ?? '' );

		if ( '' === $html ) {
			return $this->fail(
				'plugixa_exam_no_snapshot',
				__( 'This certificate has no stored design and cannot be rendered.', 'plugixa-exam' ),
				409
			);
		}

		$pdf = $this->renderer->pdf(
			$html,
			(string) ( $certificate['page_size'] ?? 'A4' ),
			(string) ( $certificate['orientation'] ?? 'landscape' )
		);

		/*
		 * Bytes, not JSON. `WP_REST_Response` would encode them, so the headers are sent and
		 * the body is written directly — the same thing core does for its own file responses.
		 */
		$filename = sanitize_file_name( (string) ( $certificate['number'] ?? 'certificate' ) ) . '.pdf';

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		// A certificate never changes once issued, but it can be withdrawn — so it is
		// cacheable by the holder's browser and never by a shared proxy.
		header( 'Cache-Control: private, max-age=300' );

		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF; escaping would corrupt it.

		exit;
	}

	/**
	 * Every design.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function get_templates( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		$rows = $this->templates->query()->order_by( 't.id', 'ASC' )->paginate( 100 )->get();

		return $this->ok( array_map( array( $this->templates, 'cast' ), $rows ) );
	}

	/**
	 * Create or update a design.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function save_template( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id       = absint( $request->get_param( 'id' ) );
		$existing = $id > 0 ? $this->templates->find( $id ) : null;

		if ( $id > 0 && null === $existing ) {
			return $this->not_found();
		}

		$design = $request->get_param( 'design_json' );

		if ( is_array( $design ) ) {
			$design = wp_json_encode( $design );
		}

		$design = (string) $design;

		// NFR-SEC-9: a design is authored by a trusted role, which is a reason to bound it
		// rather than a reason not to. Two megabytes of JSON is a paste accident, not a page.
		if ( strlen( $design ) > 2 * MB_IN_BYTES ) {
			return $this->fail(
				'plugixa_exam_design_too_large',
				__( 'That design is too large. The limit is 2 MB.', 'plugixa-exam' ),
				400
			);
		}

		$orientation = (string) $request->get_param( 'orientation' );
		$page_size   = (string) $request->get_param( 'page_size' );

		$data = array(
			'title'         => sanitize_text_field( (string) $request->get_param( 'title' ) ),
			'description'   => sanitize_textarea_field( (string) $request->get_param( 'description' ) ),
			'design_json'   => $design,
			'page_size'     => in_array( $page_size, array( 'A4', 'Letter' ), true ) ? $page_size : 'A4',
			'orientation'   => in_array( $orientation, array( 'portrait', 'landscape' ), true ) ? $orientation : 'landscape',
			'valid_days'    => max( 0, absint( $request->get_param( 'valid_days' ) ) ),
			'number_format' => sanitize_text_field( (string) $request->get_param( 'number_format' ) ),
			'published'     => $request->get_param( 'published' ) ? 1 : 0,
		);

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * AN UPDATE CHANGES WHAT IT WAS SENT, AND NOTHING ELSE.
		 *
		 * Every field above has a default for a request that leaves it out, which is right
		 * for a NEW design and was wrong for an existing one: a PUT that sent only a title
		 * wrote an empty layout, no description, a validity of zero days, an empty number
		 * format and "unpublished" over whatever the design had. The certificates issued
		 * from it afterwards were blank sheets. The admin screen always sends the whole
		 * design, so it never showed there — a script, an integration or a test did it, and
		 * one test did it to the development site's only design on every run.
		 *
		 * So a field the request does not carry keeps its stored value. One it carries is
		 * taken as sent, an empty one included: that is somebody clearing it on purpose.
		 * ════════════════════════════════════════════════════════════════════════════════
		 */
		if ( null !== $existing ) {
			foreach ( array_keys( $data ) as $field ) {
				if ( ! $request->has_param( $field ) ) {
					$data[ $field ] = $existing[ $field ] ?? $data[ $field ];
				}
			}
		}

		if ( '' === $data['title'] ) {
			return $this->fail(
				'plugixa_exam_design_needs_title',
				__( 'Give the design a name, so you can tell it from the others.', 'plugixa-exam' ),
				400
			);
		}

		if ( $id > 0 ) {
			$this->templates->update( $id, $data );
		} else {
			/**
			 * How many certificate designs this site may keep.
			 *
			 * ONE, unless something raises it. A site gets a working certificate out of the
			 * box — {@see \PlugixaExam\Modules\Certificates\Services\DefaultDesign} seeds it
			 * — and designing more than one, along with the canvas to draw them on and the
			 * per-exam picker to choose between them, is what the designer module adds.
			 *
			 * The name says what it LIMITS, never what raises it: a free build must ship no
			 * string naming a feature it does not have.
			 *
			 * Checked on CREATE only. An existing design is always editable, and a site that
			 * already has five because it once had the designer keeps all five — taking them
			 * away would delete somebody's work to enforce a number.
			 *
			 * @param int $limit Maximum number of designs.
			 */
			$limit = (int) apply_filters( 'plugixa_exam_certificate_design_limit', 1 );

			if ( $this->templates->query()->count() >= $limit ) {
				return $this->fail(
					'plugixa_exam_design_limit_reached',
					sprintf(
						/* translators: %d: how many certificate designs this site may keep. */
						_n(
							'This site keeps %d certificate design. Edit the one you have, or remove it first.',
							'This site keeps up to %d certificate designs. Edit one you have, or remove one first.',
							$limit,
							'plugixa-exam'
						),
						$limit
					),
					409
				);
			}

			$id = $this->templates->insert( $data );
		}

		return $this->ok( $this->templates->cast( (array) $this->templates->find( $id ) ) );
	}

	/**
	 * Remove a design.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function delete_template( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = absint( $request->get_param( 'id' ) );

		if ( null === $this->templates->find( $id ) ) {
			return $this->not_found();
		}

		/*
		 * Trashed, not purged. Certificates already issued from this design keep working —
		 * they carry their own frozen render — but an administrator who deletes a design and
		 * then wonders where the old certificates came from should still be able to look.
		 */
		$this->templates->delete( $id );

		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Add the derived fields a list needs.
	 *
	 * @param array<string, mixed> $row Certificate row.
	 *
	 * @return array<string, mixed>
	 */
	private function decorate( array $row ): array {
		$expires = (string) ( $row['expires_at'] ?? '' );

		if ( ! empty( $row['revoked'] ) ) {
			$row['status'] = 'revoked';
		} elseif ( '' !== $expires && strtotime( $expires . ' UTC' ) < time() ) {
			$row['status'] = 'expired';
		} else {
			$row['status'] = 'valid';
		}

		$row['verify_url'] = $this->issuer->verify_url( $row );

		$row['download_url'] = '' === (string) ( $row['verify_token'] ?? '' )
			? ''
			: rest_url( $this->namespace . '/certificates/verify/' . $row['verify_token'] . '/pdf' );

		// The snapshot is large and the list never shows it. Sending it would put a megabyte
		// of frozen HTML per row on the wire for a table of twenty.
		unset( $row['snapshot_html'] );

		return $row;
	}
}
