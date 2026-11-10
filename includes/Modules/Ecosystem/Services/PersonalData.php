<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Ecosystem\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exam data in WordPress's own privacy tools.
 *
 * Tools → Export Personal Data and Tools → Erase Personal Data are the two screens a site
 * owner is told to use when a subject access or erasure request arrives. Anything not
 * plugged into them is data the site owner does not know they hold — which is the actual
 * failure mode GDPR punishes, not the storing.
 *
 * THIS CLASS HOLDS NO KNOWLEDGE OF WHAT AN ATTEMPT IS. It registers with WordPress, splits
 * work into pages, labels the groups, and forwards. Every module that stores personal data
 * answers `plugixa_exam_personal_data_providers` with its own pair of callbacks. So the
 * Attempts module decides what an attempt's erasure means, the Notifications module decides
 * what a queued email's erasure means, and a premium module added in a later release is
 * covered the moment it answers the same filter — with no edit here, and therefore no
 * release in which a new table quietly stopped being exportable.
 *
 * WORDPRESS'S OWN CONTRACT IS THE CONTRACT. A provider's callbacks take and return exactly
 * what `wp_privacy_personal_data_exporters` and `_erasers` take and return. That is one
 * fewer shape for a module author to learn, and it means a provider can be lifted out and
 * registered directly with WordPress if this module ever went away.
 */
final class PersonalData {

	/**
	 * Prefix for every group and eraser id this plugin contributes.
	 *
	 * WordPress keys exporters by this string in the option that tracks an in-flight
	 * request, so it must not change between releases — a rename would orphan any export
	 * that was mid-flight during the upgrade.
	 */
	private const PREFIX = 'plugixa-exam';

	public function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
	}

	/**
	 * Register one WordPress exporter per provider.
	 *
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function exporters( array $exporters ): array {
		foreach ( $this->providers() as $provider ) {
			if ( ! is_callable( $provider['export'] ) ) {
				continue;
			}

			$exporters[ self::PREFIX . '-' . $provider['id'] ] = array(
				'exporter_friendly_name' => $provider['label'],
				'callback'               => $provider['export'],
			);
		}

		return $exporters;
	}

	/**
	 * Register one WordPress eraser per provider.
	 *
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function erasers( array $erasers ): array {
		foreach ( $this->providers() as $provider ) {
			if ( ! is_callable( $provider['erase'] ) ) {
				continue;
			}

			$erasers[ self::PREFIX . '-' . $provider['id'] ] = array(
				'eraser_friendly_name' => $provider['label'],
				'callback'             => $provider['erase'],
			);
		}

		return $erasers;
	}

	/**
	 * Every module that holds personal data.
	 *
	 * Malformed entries are dropped rather than fatalling: a provider is registered by
	 * another module, possibly a premium one, and a privacy export that dies halfway
	 * leaves the site owner with a partial file they have no reason to distrust.
	 *
	 * @return array<int, array{id: string, label: string, export: callable|null, erase: callable|null}>
	 */
	private function providers(): array {
		/**
		 * Declare a source of personal data.
		 *
		 * Each entry is:
		 *
		 *     array(
		 *         'id'     => 'attempts',                       // unique, stable, [a-z-]
		 *         'label'  => __( 'Exam attempts', 'plugixa-exam' ),
		 *         'export' => fn( string $email, int $page ) => array(
		 *             'data' => array( … ),                     // WordPress export items
		 *             'done' => true,
		 *         ),
		 *         'erase'  => fn( string $email, int $page ) => array(
		 *             'items_removed'  => true,
		 *             'items_retained' => false,
		 *             'messages'       => array(),
		 *             'done'           => true,
		 *         ),
		 *     )
		 *
		 * Either callback may be null if the module has nothing to do for that half —
		 * a module holding data that must be retained for a legal reason registers an
		 * export and no eraser, and says so in `messages`.
		 *
		 * @param array<int, array<string, mixed>> $providers Accumulated providers.
		 */
		$providers = (array) apply_filters( 'plugixa_exam_personal_data_providers', array() );

		$valid = array();

		foreach ( $providers as $provider ) {
			if ( ! is_array( $provider ) || empty( $provider['id'] ) || empty( $provider['label'] ) ) {
				continue;
			}

			$valid[] = array(
				'id'     => sanitize_key( (string) $provider['id'] ),
				'label'  => (string) $provider['label'],
				'export' => $provider['export'] ?? null,
				'erase'  => $provider['erase'] ?? null,
			);
		}

		return $valid;
	}
}
