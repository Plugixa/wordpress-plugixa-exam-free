<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Alerts;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Frontend\Router;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\Alerts\Frontend\Shortcode;
use PlugixaExam\Modules\Alerts\Http\AlertsController;
use PlugixaExam\Modules\Alerts\Repositories\AlertRepository;
use PlugixaExam\Modules\Alerts\Services\Notifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alerts — one inbox per person, and the two places it is read.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS IS NOT PART OF THE EMAIL MODULE.
 *
 * It nearly was, and the argument for folding it in is good: the email module already knows who
 * is told what, and two systems answering that question will eventually disagree. What decided
 * it the other way is that they are not the same KIND of thing to an administrator. The email
 * module's every route is `configuration / manage_notifications` — a screen for the person who
 * runs the site. This one is read by an Examinee, the lowest role the plugin seeds, and a
 * learner-readable route inside a controller whose whole purpose is site configuration is the
 * sort of arrangement that ends up with the wrong `permission_callback` on it.
 *
 * They also want separate switches. "Stop emailing people" and "stop showing people a feed" are
 * different decisions, and a site that wants one without the other is not an odd site.
 *
 * The shared vocabulary is kept where it matters: this listens to the SAME two actions
 * `AttemptNotifier` listens to, and applies the SAME release gate. See `Services\Notifier`.
 * ════════════════════════════════════════════════════════════════════════════════════════════
 *
 * A GUEST HAS NO INBOX. Guest attempts are identified by a signed cookie rather than a user id,
 * so there is nobody to address a row to. That is a real limit of the feature and not a bug to
 * be fixed later: inventing an identity for somebody who deliberately did not create one is
 * worse than the missing feed.
 */
final class Module implements ModuleInterface {

	/** Cron event that clears out alerts nobody needs any more. */
	public const PRUNE_HOOK = 'plugixa_exam_prune_alerts';

	public function id(): string {
		return 'alerts';
	}

	public function app(): array {
		return array(
			'category' => 'learner',
			'setting'  => 'app_alerts',
			'default'  => true,
			'deps'     => array(),
		);
	}

	public function migrate(): void {
		Schema::install();
	}

	/**
	 * @return array<string, string[]>
	 */
	public function capabilities(): array {
		// A person reads their own inbox and nobody else's, and the routes take no id — so
		// there is no question here for a capability to answer. See `AlertsController`.
		return array();
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function register_services( Container $container ): void {
		// PRIVACY LIVES HERE, not in `register_hooks()`, which does not run when this app is
		// switched off in the App Manager. Data the app stored is still there, and a
		// subject-access or erasure request must still reach it (NFR-ORG-6).
		/*
		 * And when a privacy erasure anonymises an attempt. "Your result is ready" in the
		 * person's own feed and "needs marking" in a grader's both point at somebody who
		 * asked to be forgotten, and the answers there were to mark are already gone.
		 */
		add_action(
			'plugixa_exam_attempt_anonymised',
			static function ( int $attempt_id ) use ( $container ): void {
				$container->get( AlertRepository::class )->forget( 'attempt', $attempt_id );
			}
		);
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function register_routes( Container $container ): void {
		$container->get( AlertsController::class )->register_routes();
	}

	/**
	 * @param Container $container The plugin container.
	 */
	public function register_hooks( Container $container ): void {
		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );
		add_filter( 'plugixa_exam_settings_defaults', array( $this, 'settings_defaults' ) );

		$notifier = $container->get( Notifier::class );

		/**
		 * Tell somebody about something.
		 *
		 * THE ONLY WAY IN, including for another plugin. Producers pass a snapshot of the label
		 * rather than a reference, because an alert records what was true when it fired — see
		 * `Schema`.
		 *
		 * @param int    $user_id      Who is being told.
		 * @param string $type         What kind of thing happened.
		 * @param string $subject      What it was about, as it reads now.
		 * @param string $context_type Entity type.
		 * @param int    $context_id   Entity id.
		 */
		add_action(
			'plugixa_exam_alert',
			static function ( int $user_id, string $type, string $subject, string $context_type = '', int $context_id = 0 ) use ( $notifier ): void {
				$notifier->notify( $user_id, $type, $subject, $context_type, $context_id );
			},
			10,
			5
		);

		// The same two actions the email module listens to. One vocabulary, two channels.
		add_action( 'plugixa_exam_attempt_submitted', array( $notifier, 'on_submitted' ), 10, 3 );
		add_action( 'plugixa_exam_attempt_graded', array( $notifier, 'on_graded' ), 10, 2 );
		add_action( 'plugixa_exam_result_release_changed', array( $notifier, 'on_release_changed' ), 10, 4 );

		add_filter( 'plugixa_exam_alert_link', array( $this, 'link' ), 10, 4 );

		$alerts = $container->get( AlertRepository::class );

		/*
		 * An alert must not outlive the thing it is about. This is the explicit version of what
		 * the CRM's equivalent table got for free from an INNER JOIN it no longer has — and
		 * doing it explicitly is why a deleted exam leaves a clean feed rather than a row that
		 * links to a 404.
		 */
		add_action(
			'plugixa_exam_before_purge',
			static function ( string $entity_type, int $id ) use ( $alerts ): void {
				$alerts->forget( $entity_type, $id );
			},
			10,
			2
		);


		add_action(
			self::PRUNE_HOOK,
			static function () use ( $alerts ): void {
				$alerts->prune( 90 );
			}
		);

		\PlugixaExam\Core\Cron::ensure( self::PRUNE_HOOK, 'daily', HOUR_IN_SECONDS );

		$container->get( Shortcode::class )->register();
	}

	/**
	 * Where an alert points.
	 *
	 * A marker goes to the grading screen; a candidate goes to their own result page. Both are
	 * about an attempt, which is why the filter is passed the alert type as well — see the note
	 * on `AlertsController::present()`.
	 *
	 * @param string $link         Resolved so far.
	 * @param string $context_type Entity type.
	 * @param int    $context_id   Entity id.
	 * @param string $alert_type   What kind of alert this is.
	 */
	public function link( string $link, string $context_type, int $context_id, string $alert_type ): string {
		if ( '' !== $link || 'attempt' !== $context_type || 0 === $context_id ) {
			return $link;
		}

		if ( 'needs_marking' === $alert_type ) {
			return admin_url( 'admin.php?page=plugixa-exam#/attempts/' . $context_id );
		}

		if ( 'result_ready' === $alert_type ) {
			return \PlugixaExam\Core\Plugin::instance()
				->container()
				->get( Router::class )
				->result_url( $context_id );
		}

		return $link;
	}

	/**
	 * @param array<string, mixed> $defaults Accumulated defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_defaults( array $defaults ): array {
		return array_merge( $defaults, array( 'app_alerts' => true ) );
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
				'alerts'          => __( 'Notifications', 'plugixa-exam' ),
				'alertsDesc' => __( 'A notice inbox for each person: results ready, papers to mark.', 'plugixa-exam' ),
				'alertsEmpty'     => __( 'Nothing to catch up on.', 'plugixa-exam' ),
				'alertsMarkRead'  => __( 'Mark all read', 'plugixa-exam' ),
				'alertNeedsMark'  => __( 'A paper needs marking — {name}', 'plugixa-exam' ),
				'alertResult'     => __( 'Your result is ready — {name}', 'plugixa-exam' ),
			)
		);
	}
}
