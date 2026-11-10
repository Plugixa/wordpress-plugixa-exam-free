<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Certificates\Privacy;

use PlugixaExam\Core\Frontend\Router;
use PlugixaExam\Core\Privacy\Subject;
use PlugixaExam\Modules\Certificates\Repositories\CertificateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificates in WordPress's privacy tools.
 *
 * A CERTIFICATE IS THE ONE PIECE OF EXAM DATA THE PUBLIC CAN READ. Its verification page
 * shows the holder's name to anybody with the link, so a certificate an erasure misses is
 * worse than any table an admin alone can see. It is reached two ways, and needs both:
 *
 * - As the person's own record (the provider below), because an attempt can be gone — a
 *   hard delete, an exam deleted — while its certificate is not.
 * - As something attached to an attempt (`plugixa_exam_attempt_anonymised`), because the
 *   default erasure anonymises the attempt first, after which the certificate no longer
 *   matches the person's email from anywhere else.
 */
final class CertificatePrivacy {

	/**
	 * Certificates handled per call. WordPress calls back until `done`.
	 */
	private const PER_PAGE = 50;

	public function __construct(
		private readonly CertificateRepository $certificates,
		private readonly Router $router,
	) {}

	public function register_hooks(): void {
		add_filter( 'plugixa_exam_personal_data_providers', array( $this, 'provider' ) );
		add_action( 'plugixa_exam_attempt_anonymised', array( $this, 'on_attempt_anonymised' ), 10, 3 );
	}

	/**
	 * @param array<int, array<string, mixed>> $providers Accumulated providers.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function provider( array $providers ): array {
		$providers[] = array(
			'id'     => 'certificates',
			'label'  => __( 'Exam certificates', 'plugixa-exam' ),
			'export' => array( $this, 'export' ),
			'erase'  => array( $this, 'erase' ),
		);

		return $providers;
	}

	/**
	 * @param string $email Email address the request was made for.
	 * @param int    $page  1-based page.
	 *
	 * @return array{data: array<int, array<string, mixed>>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$rows = $this->find( $email, max( 1, $page ) );
		$data = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'plugixa-exam-certificates',
				'group_label' => __( 'Exam certificates', 'plugixa-exam' ),
				'item_id'     => 'plugixa-exam-certificate-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Certificate number', 'plugixa-exam' ),
						'value' => (string) $row['number'],
					),
					array(
						'name'  => __( 'Name on the certificate', 'plugixa-exam' ),
						'value' => (string) $row['recipient_name'],
					),
					array(
						'name'  => __( 'Issued', 'plugixa-exam' ),
						'value' => (string) ( $row['issued_at'] ?? '' ),
					),
					array(
						'name'  => __( 'Expires', 'plugixa-exam' ),
						'value' => (string) ( $row['expires_at'] ?? '' ),
					),
					array(
						'name'  => __( 'Score', 'plugixa-exam' ),
						'value' => null === ( $row['percent'] ?? null ) ? '' : $row['percent'] . '%',
					),
					array(
						'name'  => __( 'Status', 'plugixa-exam' ),
						'value' => empty( $row['revoked'] ) ? __( 'Valid', 'plugixa-exam' ) : __( 'Withdrawn', 'plugixa-exam' ),
					),
					array(
						'name'  => __( 'Public verification page', 'plugixa-exam' ),
						'value' => '' === (string) ( $row['verify_token'] ?? '' ) ? '' : $this->router->verify_url( (string) $row['verify_token'] ),
					),
				),
			);
		}

		return array(
			'data' => $data,
			'done' => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * @param string $email Email address the request was made for.
	 * @param int    $page  Unused: forgetting a certificate removes it from `find()`, so
	 *                      the first page is always the next one. See AttemptPrivacy::erase().
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: string[], done: bool}
	 */
	public function erase( string $email, int $page = 1 ): array {
		unset( $page );

		$rows = $this->find( $email, 1 );

		foreach ( $rows as $row ) {
			$this->forget( $row );
		}

		return array(
			'items_removed'  => array() !== $rows,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => count( $rows ) < self::PER_PAGE,
		);
	}

	/**
	 * The attempt behind a certificate was anonymised: the certificate follows.
	 *
	 * @param int    $attempt_id Attempt id.
	 * @param string $email      The address a request was made for.
	 * @param string $reason     `request` or `retention`.
	 */
	public function on_attempt_anonymised( int $attempt_id, string $email = '', string $reason = 'request' ): void {
		unset( $email );

		/*
		 * NOT FOR RETENTION. A retention period anonymises old results because the site has
		 * no further use for them; a certificate's use — being checked by whoever the holder
		 * hands it to — goes on for as long as the credential is valid. Withdrawing every
		 * certificate older than the retention period would turn each past candidate's
		 * credential into "withdrawn" on the public page. Only the holder asking does that.
		 */
		if ( 'retention' === $reason ) {
			return;
		}

		$certificate = $this->certificates->find_by_attempt( $attempt_id );

		if ( null !== $certificate ) {
			$this->forget( $certificate );
		}
	}

	/**
	 * What erasing a certificate means. THE POLICY. Everything else in this class is plumbing.
	 *
	 * Must leave nothing that names the person: `user_id`, `guest_name`, `guest_email`,
	 * `recipient_name` and `snapshot_html` (the rendered certificate, which has the name
	 * baked into its HTML). It must also leave the row so that `find()` no longer matches
	 * it — which clearing `user_id` and `guest_email` already does — or erase() loops.
	 *
	 * TRAP: Issuer::regraded() lifts any withdrawal whose `revoked_by` is empty, taking it
	 * to mean "a re-mark withdrew this". It checks is_forgotten() first, or the next re-mark
	 * of an anonymised attempt would put a valid, nameless certificate back on the web.
	 *
	 * @param array<string, mixed> $certificate The certificate row.
	 */
	private function forget( array $certificate ): void {
		/*
		 * WITHDRAWN, NOT DELETED. The row stays, so the number stays reserved and the public
		 * link answers "withdrawn" rather than "never existed" — an employer checking a
		 * number they were handed gets the truth without getting a name. Deleting the row
		 * would also let a later re-mark of the attempt issue a brand-new certificate.
		 */
		$data = array(
			'user_id'        => null,
			'guest_name'     => '',
			'guest_email'    => '',
			'recipient_name' => '',
			'snapshot_html'  => '',
			'revoked'        => 1,
		);

		// A certificate already withdrawn keeps its date and the reason an admin gave.
		if ( empty( $certificate['revoked'] ) ) {
			$data['revoked_at']    = current_time( 'mysql', true );
			$data['revoke_reason'] = __( 'Withdrawn: the holder asked for their personal data to be erased.', 'plugixa-exam' );
		}

		$this->certificates->update( (int) $certificate['id'], $data );
	}

	/**
	 * Whether a certificate has been through forget().
	 *
	 * Read from the identity columns rather than a marker, because no schema change ships
	 * with this: an issued certificate always has a user id or a guest email (Issuer copies
	 * one or the other from the attempt), and only an erasure takes both away. Issuer asks
	 * this before lifting a withdrawal, so a re-mark cannot bring an erased certificate back.
	 *
	 * @param array<string, mixed> $certificate The certificate row.
	 */
	public static function is_forgotten( array $certificate ): bool {
		return empty( $certificate['user_id'] ) && '' === (string) ( $certificate['guest_email'] ?? '' );
	}

	/**
	 * @param string $email Email address.
	 * @param int    $page  1-based page.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function find( string $email, int $page ): array {
		$subject = Subject::from_email( $email );

		if ( null === $subject ) {
			return array();
		}

		[ $where, $args ] = $subject->where( 't.user_id', 't.guest_email' );

		return $this->certificates->query()
			->with_trashed()
			->where( $where, $args )
			->order_by( 't.id', 'ASC' )
			->paginate( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE )
			->get();
	}
}
