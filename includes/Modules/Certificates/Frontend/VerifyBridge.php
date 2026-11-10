<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Certificates\Frontend;

use PlugixaExam\Core\Frontend\Router;
use PlugixaExam\Modules\Certificates\Repositories\CertificateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The public verification page, at `/verify/<token>/`.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE MOST EXPOSED PAGE IN THE PLUGIN, AND THE ONLY ONE THAT IS SUPPOSED TO BE.
 *
 * Anyone with the token can load it: that is the feature. An employer scans a QR code on a
 * printed certificate and needs an answer without an account, a login or a cookie.
 *
 * So what it shows is bounded deliberately. It confirms the award — who, which exam, when,
 * whether it is still valid — and nothing else. NOT the score breakdown, not the answers,
 * not the other exams that person has sat, not their email address. The question being
 * asked is "is this document genuine", and everything beyond the answer to that is somebody
 * else's business being disclosed to a stranger holding a piece of paper.
 *
 * A WITHDRAWN CERTIFICATE SAYS SO, rather than pretending never to have existed. Somebody
 * holding a printout whose award was overturned is better served by "this was withdrawn"
 * than by "no such certificate", which reads as a broken link and produces a support ticket
 * instead of an answer.
 *
 * That distinction is safe here only because the token is 32 random alphanumeric characters
 * from WordPress's CSPRNG, and cannot be derived from the certificate number, the attempt or
 * the date. Enumeration is not on the table, so telling a holder the truth costs nothing. If
 * tokens were ever shortened or made predictable, this decision would have to be revisited
 * along with them — an unknown token deliberately still gets the neutral "no certificate
 * matches this link".
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * THE TEMPLATE IS AN ORDINARY FREE TEMPLATE NOW. It used to ship under `pro/` and be
 * pointed at through the `plugixa_exam_template_path` filter, because `Core\Frontend\Router`
 * registers the `/verify/` route in EVERY build — it has to, since the URL is printed on
 * paper and cannot depend on a licence being current — while the page that answered it was
 * premium, so a free build routed the URL and fell through to the theme. Certificates are
 * free now, so `templates/frontend/verify.php` sits exactly where
 * {@see \PlugixaExam\Core\Frontend\TemplateLoader::locate()} already looks and the filter is
 * gone. A theme still overrides it with its own `plugixa-exam/verify.php`.
 */
final class VerifyBridge {

	/**
	 * @param CertificateRepository $certificates Issued certificates.
	 * @param Router                $router       For reading the token off the request.
	 */
	public function __construct(
		private readonly CertificateRepository $certificates,
		private readonly Router $router
	) {}

	/**
	 * What the verification page should show for the current request.
	 *
	 * Returns a deliberately small, already-decided shape rather than the certificate row:
	 * the template is theme-overridable, and a theme author's copy must not be able to
	 * expose a column by printing something the plugin's own copy did not.
	 *
	 * @return array{found: bool, status: string, recipient: string, exam: string, number: string, issued: string, expires: string, html: string}
	 */
	public function verdict(): array {
		$empty = array(
			'found'     => false,
			'status'    => 'unknown',
			'recipient' => '',
			'exam'      => '',
			'number'    => '',
			'issued'    => '',
			'expires'   => '',
			'html'      => '',
		);

		$token = (string) get_query_var( Router::TOKEN );

		if ( '' === $token ) {
			return $empty;
		}

		$certificate = $this->certificates->find_by_token( $token );

		if ( null === $certificate ) {
			return $empty;
		}

		$exam = apply_filters( 'plugixa_exam_frontend_exam_by_id', null, (int) $certificate['exam_id'] );

		$format = get_option( 'date_format' ) ?: 'j F Y';

		return array(
			'found'     => true,
			'status'    => $this->status( $certificate ),
			'recipient' => (string) $certificate['recipient_name'],
			'exam'      => is_array( $exam ) ? (string) ( $exam['title'] ?? '' ) : '',
			'number'    => (string) $certificate['number'],
			'issued'    => $this->date( (string) ( $certificate['issued_at'] ?? '' ), $format ),
			'expires'   => $this->date( (string) ( $certificate['expires_at'] ?? '' ), $format ),

			/*
			 * The FROZEN render, never a fresh one. Verification has to show what was issued;
			 * re-rendering from today's design would show a document that never existed.
			 */
			'html'      => (string) ( $certificate['snapshot_html'] ?? '' ),
		);
	}

	/**
	 * Whether a certificate is currently good.
	 *
	 * @param array<string, mixed> $certificate The certificate row.
	 *
	 * @return string `valid`, `revoked` or `expired`.
	 */
	private function status( array $certificate ): string {
		if ( ! empty( $certificate['revoked'] ) ) {
			return 'revoked';
		}

		$expires = (string) ( $certificate['expires_at'] ?? '' );

		if ( '' !== $expires && strtotime( $expires . ' UTC' ) < time() ) {
			return 'expired';
		}

		return 'valid';
	}

	/**
	 * A stored UTC datetime in the site's timezone, or an empty string.
	 *
	 * @param string $datetime Stored value.
	 * @param string $format   Date format.
	 */
	private function date( string $datetime, string $format ): string {
		if ( '' === $datetime ) {
			return '';
		}

		$timestamp = strtotime( $datetime . ' UTC' );

		return false === $timestamp ? '' : wp_date( $format, $timestamp );
	}
}
