<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Portal;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\Portal\Frontend\Shortcodes;
use PlugixaExam\Modules\Portal\Http\PortalController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The learner-facing half of the site: a dashboard, and three things to embed on a page.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * FREE, BECAUSE A PRODUCT WHOSE LEARNERS CANNOT SEE THEIR OWN RESULTS IS NOT A PRODUCT.
 *
 * This module used to be premium. That put the entire learner experience behind a licence:
 * a free install could publish a catalogue and run an exam, and then had nowhere to send
 * the person who sat it. Somebody comparing this plugin against another one's free build
 * saw a plugin with no learner side at all.
 *
 * The line it was split along is a real one rather than a commercial one. Reading YOUR OWN
 * results is the plugin doing the job it exists to do, and every figure on this page is
 * derived from attempts this same build recorded. Publishing a ranking of everybody's
 * results to strangers is a different feature — it needs consent, a privacy mode and a
 * decision about who may be named — so it is a different module, and not this one.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * NO TABLES, AND NOTHING STORED. Every figure is derived on read from attempts, and the two
 * sections this module does not own — assigned exams and certificates — arrive through
 * filters that other modules answer. Absent module, empty section, no branch.
 *
 * THE PAGE IS SERVER-RENDERED FIRST. `templates/frontend/portal.php` renders all three
 * lists through the same template parts the shortcodes use, so `/my-exams/` is complete
 * before any JavaScript runs and works with scripting off entirely. React mounts over it
 * only when a module has registered something interactive to add.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'portal';
	}

	public function app(): array {
		return array(
			'category' => 'learner',
			'setting'  => 'app_portal',
			'default'  => true,
			// Results are attempts. Assignments and certificates arrive through filters
			// that other modules answer, so neither is a hard dependency.
			'deps'     => array( 'attempts' ),
		);
	}

	public function migrate(): void {
		// Nothing. Everything this module shows is derived on read.
	}

	public function capabilities(): array {
		/*
		 * None. The one route serves the caller their own data, which is an identity rather
		 * than a capability — see `PortalController`.
		 */
		return array();
	}

	public function register_services( Container $container ): void {
		unset( $container );
	}

	public function register_routes( Container $container ): void {
		$container->get( PortalController::class )->register_routes();
	}

	public function register_hooks( Container $container ): void {
		$container->get( Shortcodes::class )->register_hooks();

		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );
		add_filter( 'plugixa_exam_settings_defaults', array( $this, 'settings_defaults' ) );
		add_filter( 'plugixa_exam_sanitize_settings', array( $this, 'sanitize_settings' ) );
	}

	/**
	 * @param array<string, mixed> $defaults Accumulated defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function settings_defaults( array $defaults ): array {
		return array_merge( $defaults, array( 'app_portal' => true ) );
	}

	/**
	 * @param array<string, mixed> $clean The whitelisted input.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $clean ): array {
		if ( array_key_exists( 'app_portal', $clean ) ) {
			$clean['app_portal'] = (bool) $clean['app_portal'] && '0' !== $clean['app_portal'];
		}

		return $clean;
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
				'portal'               => __( 'Learner portal', 'plugixa-exam' ),
				'portalDesc' => __( 'The learner side of the site: their exams, their results, and pages to embed them on.', 'plugixa-exam' ),
				'portalMyExams'        => __( 'My exams', 'plugixa-exam' ),
				'portalMyResults'      => __( 'My results', 'plugixa-exam' ),
				'portalMyCertificates' => __( 'My certificates', 'plugixa-exam' ),
			)
		);
	}
}
