<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Certificates\Services;

use PlugixaExam\Core\Frontend\Router;
use PlugixaExam\Modules\Certificates\Repositories\CertificateRepository;
use PlugixaExam\Modules\Certificates\Repositories\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Awarding a certificate for a passed attempt.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * ISSUING IS IDEMPOTENT, AND THE DATABASE IS WHAT GUARANTEES IT.
 *
 * `certificates.attempt_id` is UNIQUE. This class still checks first — a clean "already
 * issued" beats a caught exception — but the check is an optimisation, not the guard. Two
 * requests finalising the same attempt at once (a double-clicked submit, a retried webhook,
 * the auto-close sweep racing a manual submit) would both pass a check-then-insert and both
 * award a numbered certificate for one exam. The index is what makes the second one fail.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * WHAT IS FROZEN, AND WHY. The rendered HTML, the page size and the orientation are stored
 * with the certificate. Editing the design afterwards must never rewrite a document somebody
 * has already downloaded and given to an employer — a certificate is a claim about a past
 * event, and re-rendering it from today's design quietly changes the evidence.
 */
final class Issuer {

	/**
	 * How many times to retry a colliding number before giving up.
	 *
	 * Collisions are near-impossible with a sequence, and possible with a date-only format
	 * on a busy day. Retrying a handful of times costs nothing; retrying for ever on a
	 * genuinely exhausted format would hang the submit request that triggered it.
	 */
	private const NUMBER_ATTEMPTS = 8;

	/**
	 * @param CertificateRepository $certificates Issued certificates.
	 * @param TemplateRepository    $templates    Designs.
	 * @param Renderer              $renderer     Design → HTML.
	 * @param Numbers               $numbers      Number formatting.
	 * @param QrCode                $qr           The verification QR code.
	 * @param Router                $router       For the verification URL the QR encodes.
	 */
	public function __construct(
		private readonly CertificateRepository $certificates,
		private readonly TemplateRepository $templates,
		private readonly Renderer $renderer,
		private readonly Numbers $numbers,
		private readonly QrCode $qr,
		private readonly Router $router
	) {}

	/**
	 * Issue a certificate for a passed attempt, or return the one already issued.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 * @param array<string, mixed> $exam    The exam row.
	 *
	 * @return array<string, mixed>|null The certificate, or null if none was due.
	 */
	public function issue( array $attempt, array $exam ): ?array {
		$attempt_id = (int) ( $attempt['id'] ?? 0 );

		if ( $attempt_id < 1 ) {
			return null;
		}

		$existing = $this->certificates->find_by_attempt( $attempt_id );

		if ( null !== $existing ) {
			return $existing;
		}

		if ( ! $this->is_due( $attempt, $exam ) ) {
			return null;
		}

		$template = $this->templates->resolve( (int) ( $exam['certificate_template_id'] ?? 0 ) );

		if ( null === $template ) {
			// No design at all. Awarding an unrenderable certificate would be worse than
			// awarding none: it would appear in the learner's portal as a broken download.
			return null;
		}

		$number = $this->reserve_number( $template );

		if ( '' === $number ) {
			return null;
		}

		$issued_at = current_time( 'mysql', true );
		$valid     = (int) ( $template['valid_days'] ?? 0 );

		$data = array(
			'template_id'    => (int) $template['id'],
			'exam_id'        => (int) ( $attempt['exam_id'] ?? 0 ),
			'attempt_id'     => $attempt_id,
			'user_id'        => ( (int) ( $attempt['user_id'] ?? 0 ) ) ?: null,
			'guest_name'     => $attempt['guest_name'] ?? null,
			'guest_email'    => $attempt['guest_email'] ?? null,
			'number'         => $number,
			'verify_token'   => $this->token(),
			'recipient_name' => $this->recipient_name( $attempt ),
			'score'          => $attempt['score'] ?? null,
			'max_score'      => $attempt['max_score'] ?? null,
			'percent'        => $attempt['percent'] ?? null,
			'issued_at'      => $issued_at,
			'expires_at'     => $valid > 0 ? gmdate( 'Y-m-d H:i:s', strtotime( $issued_at . ' UTC' ) + $valid * DAY_IN_SECONDS ) : null,
			'page_size'      => (string) ( $template['page_size'] ?? 'A4' ),
			'orientation'    => (string) ( $template['orientation'] ?? 'landscape' ),
		);

		$id = $this->certificates->insert( $data );

		if ( $id < 1 ) {
			/*
			 * Almost certainly the UNIQUE index on `attempt_id` firing because another
			 * request won the race. Read back rather than reporting a failure: from the
			 * examinee's point of view a certificate exists, which is the truth.
			 */
			return $this->certificates->find_by_attempt( $attempt_id );
		}

		$row = $this->certificates->find( $id );

		if ( null === $row ) {
			return null;
		}

		// The snapshot is written after the insert because it embeds the number and the
		// verification URL, and neither exists until the row does.
		$this->certificates->update( $id, array( 'snapshot_html' => $this->snapshot( $row, $template, $exam ) ) );

		/**
		 * Fires once a certificate has been issued.
		 *
		 * @param array<string, mixed> $certificate The certificate row.
		 * @param array<string, mixed> $attempt     The attempt it was awarded for.
		 */
		do_action( 'plugixa_exam_certificate_issued', $this->certificates->find( $id ), $attempt );

		return $this->certificates->find( $id );
	}

	/**
	 * An already-graded attempt has been marked again: award, withdraw or reinstate.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * A CERTIFICATE FOLLOWS THE MARK, BUT NEVER OVERRULES AN ADMINISTRATOR.
	 *
	 * - Now a pass with none yet: issued, as a first pass would have been.
	 * - Now a FAIL with a valid one: WITHDRAWN. Left standing, the public verification page
	 *   went on vouching for a pass the site no longer records. Withdrawn, not deleted — the
	 *   number stays reserved and the page says "withdrawn", as for any revocation.
	 * - A pass again: the withdrawal is LIFTED, keeping the certificate's number and date,
	 *   but only a withdrawal a re-mark made. One an administrator made stays made.
	 *
	 * The two are told apart by who revoked it. The revoke route always records the
	 * administrator; a re-mark records nobody, because nobody decided it — the marker
	 * changed a mark, and the attempt already says who did that and when.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param array<string, mixed> $attempt The attempt row, as it is now.
	 * @param array<string, mixed> $exam    The exam row.
	 */
	public function regraded( array $attempt, array $exam ): void {
		$attempt_id = (int) ( $attempt['id'] ?? 0 );

		if ( $attempt_id < 1 || empty( $attempt['is_graded'] ) ) {
			return;
		}

		$existing = $this->certificates->find_by_attempt( $attempt_id );

		if ( $this->is_due( $attempt, $exam ) ) {
			if ( null !== $existing && $this->withdrawn_by_remark( $existing ) ) {
				$this->certificates->update(
					(int) $existing['id'],
					array(
						'revoked'       => 0,
						'revoked_at'    => null,
						'revoked_by'    => null,
						'revoke_reason' => '',
					)
				);

				return;
			}

			$this->issue( $attempt, $exam );

			return;
		}

		if ( null === $existing || ! empty( $existing['revoked'] ) || ! empty( $attempt['passed'] ) ) {
			return;
		}

		$this->certificates->update(
			(int) $existing['id'],
			array(
				'revoked'       => 1,
				'revoked_at'    => current_time( 'mysql', true ),
				'revoked_by'    => null,
				'revoke_reason' => __( 'Withdrawn automatically: the attempt was re-marked below the pass mark.', 'plugixa-exam' ),
			)
		);
	}

	/**
	 * Whether a certificate was withdrawn by a re-mark rather than by a person.
	 *
	 * @param array<string, mixed> $certificate The certificate row.
	 */
	private function withdrawn_by_remark( array $certificate ): bool {
		return ! empty( $certificate['revoked'] ) && empty( $certificate['revoked_by'] );
	}

	/**
	 * Whether this attempt has earned a certificate.
	 *
	 * BOTH `passed` AND `is_graded` ARE REQUIRED. A paper with an unmarked essay has a
	 * provisional score, and a provisional pass is not a pass — issuing on it would put a
	 * numbered certificate in somebody's hands before a human has finished marking, and
	 * revoking it afterwards is a far worse conversation than waiting.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 * @param array<string, mixed> $exam    The exam row.
	 */
	public function is_due( array $attempt, array $exam ): bool {
		if ( empty( $attempt['passed'] ) || empty( $attempt['is_graded'] ) ) {
			return false;
		}

		/**
		 * Whether an attempt should produce a certificate.
		 *
		 * @param bool                 $due     Whether it has passed and finished marking.
		 * @param array<string, mixed> $attempt The attempt row.
		 * @param array<string, mixed> $exam    The exam row.
		 */
		return (bool) apply_filters( 'plugixa_exam_certificate_due', true, $attempt, $exam );
	}

	/**
	 * The values a design's fields are filled with.
	 *
	 * Every key is a field name an author can place in a design. Adding one here makes it
	 * available to every template at once, which is why they are named for what they mean
	 * rather than for where they came from.
	 *
	 * @param array<string, mixed> $certificate The certificate row.
	 * @param array<string, mixed> $exam        The exam row.
	 *
	 * @return array<string, string>
	 */
	public function values( array $certificate, array $exam ): array {
		$issued = (string) ( $certificate['issued_at'] ?? '' );
		$format = get_option( 'date_format' ) ?: 'j F Y';

		$values = array(
			'recipient_name' => (string) ( $certificate['recipient_name'] ?? '' ),
			'exam_title'     => (string) ( $exam['title'] ?? '' ),
			'number'         => (string) ( $certificate['number'] ?? '' ),
			'score'          => (string) ( $certificate['score'] ?? '' ),
			'max_score'      => (string) ( $certificate['max_score'] ?? '' ),
			/*
			 * THE SITE'S NUMBER FORMAT, NOT THE LOCALE'S, AND NOT A PRECISION CHOSEN HERE.
			 *
			 * This used to be `number_format_i18n( …, 2 )`, reasoning that a French reader
			 * expects 72,50 — right about the comma, wrong about who decides. WordPress's
			 * locale and this site's `decimal_separator` are different questions, and an
			 * owner who has set one means it. The hard-coded 2 was also the reason a
			 * certificate said 72.50 where the result page said 72.5 for the same mark.
			 *
			 * `Renderer.php` deliberately does NOT use this, and that is not an
			 * inconsistency: the numbers there are CSS lengths, where a decimal comma is
			 * invalid.
			 */
			'percent'        => null === ( $certificate['percent'] ?? null )
				? ''
				: plugixa_exam_format_number( (float) $certificate['percent'] ) . '%',
			'issued_date'    => '' === $issued ? '' : wp_date( $format, (int) strtotime( $issued . ' UTC' ) ),
			'expiry_date'    => empty( $certificate['expires_at'] )
				? ''
				: wp_date( $format, (int) strtotime( (string) $certificate['expires_at'] . ' UTC' ) ),
			'site_name'      => (string) get_bloginfo( 'name' ),
			'verify_url'     => $this->verify_url( $certificate ),

			/*
			 * The QR as inline PNG bytes, ready to drop into an `image` field. Generated at
			 * ISSUE time and frozen into the snapshot, so a certificate printed today still
			 * scans in five years even if the site has moved — the URL it encodes is the one
			 * that was live when the award was made.
			 */
			'verify_qr'      => $this->qr->data_uri( $this->verify_url( $certificate ) ),
		);

		/**
		 * The values a certificate design is filled with.
		 *
		 * @param array<string, string> $values      Field name => value.
		 * @param array<string, mixed>  $certificate The certificate row.
		 * @param array<string, mixed>  $exam        The exam row.
		 */
		return (array) apply_filters( 'plugixa_exam_certificate_values', $values, $certificate, $exam );
	}

	/**
	 * The public verification URL for a certificate.
	 *
	 * @param array<string, mixed> $certificate The certificate row.
	 */
	public function verify_url( array $certificate ): string {
		$token = (string) ( $certificate['verify_token'] ?? '' );

		return '' === $token ? '' : $this->router->verify_url( $token );
	}

	/**
	 * Render and freeze the certificate as it looks now.
	 *
	 * @param array<string, mixed> $certificate The certificate row.
	 * @param array<string, mixed> $template    The design.
	 * @param array<string, mixed> $exam        The exam row.
	 */
	public function snapshot( array $certificate, array $template, array $exam ): string {
		$design = json_decode( (string) ( $template['design_json'] ?? '' ), true );

		return $this->renderer->html(
			is_array( $design ) ? $design : array(),
			$this->values( $certificate, $exam ),
			(string) ( $certificate['page_size'] ?? 'A4' ),
			(string) ( $certificate['orientation'] ?? 'landscape' )
		);
	}

	/**
	 * A name to put on the certificate.
	 *
	 * Falls back through what is actually known: the account's display name, then the name a
	 * guest typed, then a placeholder. Never blank — a certificate with an empty name line
	 * looks like a rendering failure rather than a missing detail.
	 *
	 * @param array<string, mixed> $attempt The attempt row.
	 */
	private function recipient_name( array $attempt ): string {
		$user_id = (int) ( $attempt['user_id'] ?? 0 );

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );

			if ( $user && '' !== trim( $user->display_name ) ) {
				return $user->display_name;
			}
		}

		$guest = trim( (string) ( $attempt['guest_name'] ?? '' ) );

		return '' !== $guest ? $guest : __( 'Examinee', 'plugixa-exam' );
	}

	/**
	 * Find a free certificate number.
	 *
	 * @param array<string, mixed> $template The design.
	 */
	private function reserve_number( array $template ): string {
		$format = (string) ( $template['number_format'] ?? '' );

		for ( $try = 0; $try < self::NUMBER_ATTEMPTS; $try++ ) {
			$candidate = $this->numbers->generate( $format, $try );

			if ( '' !== $candidate && ! $this->certificates->number_exists( $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}

	/**
	 * A verification token.
	 *
	 * `wp_generate_password` with special characters off gives 32 alphanumeric characters
	 * from WordPress's CSPRNG. Unguessable on purpose: the token is the only thing standing
	 * between a public URL and somebody else's exam result, so it must not be derivable from
	 * the certificate number, the attempt id or the date.
	 */
	private function token(): string {
		return wp_generate_password( 32, false, false );
	}
}
