<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Ecosystem;

use PlugixaExam\Core\Container;
use PlugixaExam\Core\Settings;
use PlugixaExam\Core\Modules\ModuleInterface;
use PlugixaExam\Modules\Ecosystem\Search\ExamSearchHandler;
use PlugixaExam\Modules\Ecosystem\Services\PersonalData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where the plugin meets the rest of WordPress.
 *
 * Three things live here, and they have one thing in common: none of them is a screen. They
 * are the places an exam has to show up in machinery that already exists — the privacy
 * tools, the search index, the block editor's link picker — so that a site owner does not
 * have to learn that exams are a special case.
 *
 * WHY THIS MODULE HAS NO APP MANAGER FLAG, WHICH EVERY OTHER NON-CORE MODULE HAS.
 *
 * It carries the GDPR exporters and erasers. NFR-ORG-6 says those are free and always on,
 * and an App Manager toggle would make "always on" false: an administrator switching off
 * something called "Ecosystem" would not expect to be switching off their ability to answer
 * a subject access request, and WordPress would silently stop offering exam data in the
 * export it builds. A privacy obligation is not a feature, so it does not get a feature
 * switch. `core => true` is how a module says that — {@see \PlugixaExam\Core\Modules\AppRegistry}
 * short-circuits on it and no setting row is ever written.
 *
 * The search integration is a genuine preference, so it keeps one — but as an ordinary
 * setting (`search_integration`), not as an App Manager gate, so it can be off without
 * taking the privacy hooks with it.
 *
 * WHY THE PRIVACY DATA ITSELF IS NOT DESCRIBED HERE. Attempts know what an attempt holds;
 * the notification queue knows what a queued email holds. This module knows neither, and
 * importing them would make the free/premium seam and the module boundary both leak. It
 * asks instead, through `plugixa_exam_personal_data_providers`, and every module that holds
 * personal data answers. A premium module added later is covered by the same filter without
 * this file changing — which is the only way a privacy feature stays correct as a plugin
 * grows.
 */
final class Module implements ModuleInterface {

	/**
	 * Resolved once in {@see register_hooks()}, because `wp_rest_search_handlers` fires
	 * deep inside the REST bootstrap where the container is not in scope.
	 */
	private ?Settings $settings = null;

	public function id(): string {
		return 'ecosystem';
	}

	public function app(): array {
		return array(
			'category' => 'integrations',
			'core'     => true,
		);
	}

	public function migrate(): void {
		// Nothing to migrate: this module owns no tables. It reads other modules' data
		// through filters and writes none of its own.
	}

	public function capabilities(): array {
		return array();
	}

	public function register_services( Container $container ): void {
		unset( $container );
	}

	public function register_routes( Container $container ): void {
		unset( $container );
	}

	public function register_hooks( Container $container ): void {
		$container->get( PersonalData::class )->register_hooks();

		$this->settings = $container->get( Settings::class );

		add_filter( 'plugixa_exam_settings_defaults', array( $this, 'settings_defaults' ) );
		add_filter( 'plugixa_exam_sanitize_settings', array( $this, 'sanitize_settings' ) );
		add_filter( 'plugixa_exam_admin_i18n_strings', array( $this, 'strings' ) );

		/*
		 * The search handler is registered on the filter rather than instantiated now:
		 * `WP_REST_Search_Handler` is loaded with the REST API, and naming it at
		 * `plugins_loaded` would fatal on a request that never reaches REST.
		 */
		add_filter( 'wp_rest_search_handlers', array( $this, 'search_handlers' ) );
	}

	/**
	 * Add the exam search handler to the ones WordPress already knows.
	 *
	 * This is what puts exams in `/wp/v2/search?type=plugixa-exam`, and therefore in the
	 * block editor's link picker and the Navigation block's search box. It is the honest
	 * WordPress equivalent of Joomla's Smart Search adapter: not a second index, but a
	 * source the one search endpoint already consults.
	 *
	 * @param array<int, \WP_REST_Search_Handler> $handlers Registered handlers.
	 *
	 * @return array<int, \WP_REST_Search_Handler>
	 */
	public function search_handlers( array $handlers ): array {
		if ( null === $this->settings || ! $this->settings->bool( 'search_integration', true ) ) {
			return $handlers;
		}

		$handlers[] = new ExamSearchHandler( null, $this->settings );

		return $handlers;
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
				// Exams appear in site search and the editor's link picker.
				'search_integration'     => true,

				// Only published, currently-open exams are findable. Off by default is the
				// safe reading: an exam that opens next month is not a secret, but a site
				// that lists it in search before it opens has made a decision nobody asked
				// it to make.
				'search_include_upcoming' => false,
			)
		);
	}

	/**
	 * @param array<string, mixed> $clean The whitelisted input.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $clean ): array {
		foreach ( array( 'search_integration', 'search_include_upcoming' ) as $key ) {
			if ( array_key_exists( $key, $clean ) ) {
				$clean[ $key ] = (bool) $clean[ $key ] && '0' !== $clean[ $key ];
			}
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
				'ecosystem'              => __( 'Site integration', 'plugixa-exam' ),
				'ecosystemDesc' => __( 'Where exams meet WordPress: the privacy tools, site search and the block editor.', 'plugixa-exam' ),
				'searchIntegration'      => __( 'Let exams be found by site search', 'plugixa-exam' ),
				'searchIntegrationHelp'  => __( 'Exams appear in search results and in the link picker when you are writing a post.', 'plugixa-exam' ),
				'searchIncludeUpcoming'  => __( 'Include exams that have not opened yet', 'plugixa-exam' ),
				'privacyTools'           => __( 'Privacy tools', 'plugixa-exam' ),
				'privacyToolsHelp'       => __( 'Exam data is included in the personal-data export and erasure that WordPress runs from Tools → Export Personal Data. This is always on.', 'plugixa-exam' ),
			)
		);
	}
}
