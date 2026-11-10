<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Certificates;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\Certificates\Http\CertificatesController;
use PlugixaExam\Modules\Certificates\Repositories\CertificateRepository;
use PlugixaExam\Modules\Certificates\Services\Issuer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificates: proof that somebody passed, which outlives the site that issued it.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THIS MODULE OWNS TABLES, AND IT IS FREE, WHICH IS WHY THE OLD RULE STILL STANDS.
 *
 * It used to live under `pro/`, and the contract written here was about a lapsed licence:
 * THE TABLES ARE NEVER DROPPED ON A TIER CHANGE. That still holds, and it now matters in
 * the other direction too — a site that was on premium before certificates became free
 * keeps every row, every reserved number and every issued token, because the schema names
 * them the same either way. `Core\Uninstaller` is the only thing that drops them, and
 * only when the whole plugin is deleted on purpose.
 *
 * `/verify/<token>/` is routed by Core in every build, as it always was: a printed QR code
 * cannot be asked to care what anybody is paying. What changed is that the page behind it
 * now ships too, so the route no longer falls through to the theme.
 *
 * WHAT IS PREMIUM IS DESIGNING, NOT ISSUING. This module serves one published design;
 * `plugixa_exam_certificate_design_limit` is the seam a premium module raises to allow
 * more of them. The filter is named for what it limits, never for what raises it.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * THE APP MANAGER SWITCH IS `certificates_enabled`, NOT AN `app_*` TWIN. Seven modules reuse
 * a master switch that already exists for its own sake rather than growing a second flag
 * that could disagree with it — see {@see ModuleInterface::app()}.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'certificates';
	}

	public function app(): array {
		return array(
			'category' => 'learner',
			'setting'  => 'certificates_enabled',
			'default'  => true,
			// A certificate is awarded for an attempt, so there must be attempts.
			'deps'     => array( 'attempts' ),
		);
	}

	public function migrate(): void {
		Schema::install();

		/*
		 * A site with no design awards nothing, silently — see {@see Services\DefaultDesign}.
		 * Seeded on every migration rather than on first install only, because a site that
		 * arrives here having never had a design is in exactly that state whatever version
		 * it came from. `seed()` returns immediately when the table has any row in it.
		 */
		\PlugixaExam\Core\Plugin::instance()->container()
			->get( Repositories\TemplateRepository::class )
			->seed();
	}

	public function capabilities(): array {
		return array();
	}

	public function register_services( Container $container ): void {
		// PRIVACY LIVES HERE, not in `register_hooks()`, which does not run when this app is
		// switched off in the App Manager. Data the app stored is still there, and a
		// subject-access or erasure request must still reach it (NFR-ORG-6).
		$container->get( Privacy\CertificatePrivacy::class )->register_hooks();
	}

	public function register_routes( Container $container ): void {
		$container->get( CertificatesController::class )->register_routes();
	}

	public function register_hooks( Container $container ): void {
		// Its paragraph in the suggested privacy policy, while this app is on.
		add_filter(
			'plugixa_exam_privacy_policy_paragraphs',
			static function ( array $paragraphs ): array {
				$paragraphs[] = __( 'If you pass an exam that awards a certificate, we keep the certificate with your name on it. Anyone you give its link or number to can check it on a public page that shows your name, the exam and the date.', 'plugixa-exam' );

				return $paragraphs;
			}
		);

		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );
		add_filter( 'plugixa_exam_settings_defaults', array( $this, 'settings_defaults' ) );

		/*
		 * The learner portal asks what somebody has earned, and only this module knows. A
		 * filter rather than a call, so the portal renders an empty section on a site where
		 * certificates are switched off instead of failing to build.
		 *
		 * Only ISSUED certificates are handed over: a withdrawn one is deliberately absent
		 * rather than shown greyed out, because the portal is where somebody goes to
		 * download it and a revoked credential must not look like one that is merely
		 * unavailable today.
		 */
		add_filter(
			'plugixa_exam_portal_certificates',
			static function ( array $rows, int $user_id ) use ( $container ): array {
				$user = get_userdata( $user_id );

				$certificates = $container->get( CertificateRepository::class )
					->for_person( $user_id, false === $user ? '' : (string) $user->user_email );

				$exams    = $container->get( \PlugixaExam\Modules\Exams\Repositories\ExamRepository::class );
				$released = array();

				foreach ( $certificates as $certificate ) {
					if ( ! empty( $certificate['revoked'] ) ) {
						continue;
					}

					/*
					 * NOT BEFORE THE RESULTS ARE OUT. A certificate is issued the moment an
					 * attempt is marked a pass — its date is the day it was earned — but
					 * listing it here while the exam withholds results told the learner "you
					 * passed" beside a results page saying "Results are not out yet".
					 * Measured on the audit site. `Outcome::released()` is the one release
					 * rule the result page and every badge already read. An exam since
					 * deleted has no rule left to wait for, so its certificate stands.
					 */
					$exam_id = (int) ( $certificate['exam_id'] ?? 0 );

					if ( ! isset( $released[ $exam_id ] ) ) {
						$exam                 = $exams->find( $exam_id );
						$released[ $exam_id ] = null === $exam || \PlugixaExam\Modules\Attempts\Services\Outcome::released( $exam );
					}

					if ( ! $released[ $exam_id ] ) {
						continue;
					}

					$rows[] = array(
						'id'         => (int) $certificate['id'],
						'number'     => (string) $certificate['number'],
						'issued_at'  => $certificate['issued_at'] ?? null,
						'expires_at' => $certificate['expires_at'] ?? null,
						'verify_url' => $container->get( \PlugixaExam\Core\Frontend\Router::class )
							->verify_url( (string) $certificate['verify_token'] ),
					);
				}

				return $rows;
			},
			10,
			2
		);

		$issuer = $container->get( Issuer::class );

		/*
		 * ISSUED ON `plugixa_exam_attempt_graded`, NOT ON SUBMIT.
		 *
		 * A paper containing an essay is submitted with a provisional score and reaches its
		 * final one only when a human finishes marking. Hooking submit would award a numbered
		 * certificate on the provisional pass and then need to revoke it — a far worse
		 * conversation than a certificate arriving a day later.
		 *
		 * Attempts with no manual questions are graded during submit, so the common case
		 * still issues immediately.
		 */
		$attempts = $container->get( \PlugixaExam\Modules\Attempts\Repositories\AttemptRepository::class );

		add_action(
			'plugixa_exam_attempt_graded',
			static function ( int $attempt_id, array $exam ) use ( $issuer, $attempts ): void {
				// The action carries the attempt's ID, not its row — so it is read back here
				// rather than assumed. Taking `array $attempt` would be a TypeError on the
				// first real grading, and only there.
				$attempt = $attempts->find( $attempt_id );

				if ( null !== $attempt ) {
					$issuer->issue( $attempt, $exam );
				}
			},
			10,
			2
		);

		/*
		 * AND ON A RE-MARK, BECAUSE `attempt_graded` FIRES ONLY ONCE.
		 *
		 * It fires on the transition to graded, so the examinee is not e-mailed twice — which
		 * left a marker who turned a fail into a pass handing out a pass with no certificate
		 * (measured on the LMS audit site), and one who turned a pass into a fail leaving a
		 * certificate the public verification page still called valid. `Issuer::regraded()`
		 * awards, withdraws or reinstates.
		 */
		add_action(
			'plugixa_exam_attempt_regraded',
			static function ( int $attempt_id, array $exam ) use ( $issuer, $attempts ): void {
				$attempt = $attempts->find( $attempt_id );

				if ( null !== $attempt ) {
					$issuer->regraded( $attempt, $exam );
				}
			},
			10,
			2
		);

		// The result page offers the download once one exists.
		add_filter(
			'plugixa_exam_result_certificate',
			static function ( $certificate, int $attempt_id ) use ( $container ) {
				unset( $certificate );

				return $container->get( Repositories\CertificateRepository::class )->find_by_attempt( $attempt_id );
			},
			10,
			2
		);
	}

	/**
	 * @param array<string, mixed> $defaults Accumulated defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_defaults( array $defaults ): array {
		return array_merge(
			$defaults,
			array(
				'certificates_enabled' => true,

				// Appended to every design that does not set its own. An organisation
				// usually wants one scheme across all of its certificates.
				'certificate_number_format' => 'CERT-{Y}-{seq:5}',
			)
		);
	}

	/**
	 * @param array<string, string> $strings Accumulated strings.
	 *
	 * @return array<string, string>
	 */
	public function strings( array $strings ): array {
		return array_merge(
			$strings,
			array(
				'certificates'          => __( 'Certificates', 'plugixa-exam' ),
				'certificatesDesc' => __( 'Award a certificate on a pass, with a link anyone can use to check it.', 'plugixa-exam' ),

				/*
				 * The design canvas's field palette. `DESIGN_FIELDS` in
				 * `pro/certificates/api.ts` is the client's copy of `Issuer::values()`, and
				 * each entry carries a one-line description shown as a tooltip — eleven
				 * strings that were the largest block of untranslated English left in the
				 * admin app.
				 */

				// A US paper size, and a word. `A4` beside it is an ISO code and is not.
				'certificatePaperLetter' => __( 'Letter', 'plugixa-exam' ),
				'certificatesIntro'     => __( 'What this site has awarded, and the designs it awards them on.', 'plugixa-exam' ),
				'dashCertificates'      => __( 'Certificates', 'plugixa-exam' ),
				'dashCertificatesNote'  => __( 'issued and verifiable', 'plugixa-exam' ),
				'certificateTemplates'  => __( 'Designs', 'plugixa-exam' ),
				'certificateNumber'     => __( 'Number', 'plugixa-exam' ),
				'certificateRecipient'  => __( 'Awarded to', 'plugixa-exam' ),
				'certificateIssued'     => __( 'Issued', 'plugixa-exam' ),
				'certificateExpires'    => __( 'Valid until', 'plugixa-exam' ),
				'certificateNeverExpires' => __( 'Never expires', 'plugixa-exam' ),
				'certificateStatus'     => __( 'Status', 'plugixa-exam' ),
				'certificateValid'      => __( 'Valid', 'plugixa-exam' ),
				'certificateRevoked'    => __( 'Withdrawn', 'plugixa-exam' ),
				'certificateExpired'    => __( 'Expired', 'plugixa-exam' ),
				'certificateRevoke'     => __( 'Withdraw', 'plugixa-exam' ),
				'certificateRevokeHelp' => __( 'The certificate stays on record and its number stays reserved, but it stops verifying. Say why, so anyone checking later knows.', 'plugixa-exam' ),
				'certificateRestore'    => __( 'Reinstate', 'plugixa-exam' ),
				'certificateReason'     => __( 'Reason', 'plugixa-exam' ),
				'certificateDownload'   => __( 'Download PDF', 'plugixa-exam' ),
				'certificateVerifyLink' => __( 'Verification link', 'plugixa-exam' ),
				'certificateNone'       => __( 'Nothing has been awarded yet.', 'plugixa-exam' ),
				'certificateNoDesigns'  => __( 'There are no certificate designs yet, so nothing can be awarded.', 'plugixa-exam' ),
				'certificatePageSize'   => __( 'Paper', 'plugixa-exam' ),
				'certificateOrientation' => __( 'Orientation', 'plugixa-exam' ),
				'certificateValidDays'  => __( 'Valid for (days)', 'plugixa-exam' ),
				'certificateValidDaysHelp' => __( 'Zero means it never expires.', 'plugixa-exam' ),
				'certificateNumberFormat' => __( 'Number format', 'plugixa-exam' ),
				'certificateNumberFormatHelp' => __( 'Tokens: {Y} {y} {m} {d} {seq} {seq:5} {rand} {exam}.', 'plugixa-exam' ),
				'certificateAddDesign'  => __( 'Add a design', 'plugixa-exam' ),
				'certificateDeleteDesign' => __( 'Delete this design?', 'plugixa-exam' ),
				'certificateDeleteDesignHelp' => __( 'Certificates already awarded on it are unaffected — each one carries its own frozen copy.', 'plugixa-exam' ),
				'certificateNoneHelp'   => __( 'A certificate is issued automatically when somebody passes an exam and its marking is finished.', 'plugixa-exam' ),
				'certificateDesignJson' => __( 'Layout (JSON, millimetres)', 'plugixa-exam' ),
				'certificateShowJson'   => __( 'Import or export the layout as JSON', 'plugixa-exam' ),
				'certificateHideJson'   => __( 'Hide the layout JSON', 'plugixa-exam' ),

				'certificateLayoutIsJson'    => __( 'Fields are positioned in millimetres from the top-left of the page. Open the layout below to move one, or to bring in a layout from somewhere else.', 'plugixa-exam' ),
				'days'                       => __( 'days', 'plugixa-exam' ),
				'landscape'                  => __( 'Landscape', 'plugixa-exam' ),
				'portrait'                   => __( 'Portrait', 'plugixa-exam' ),
				'title'                      => __( 'Name', 'plugixa-exam' ),
			)
		);
	}
}
