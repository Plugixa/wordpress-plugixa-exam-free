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
	 * The daily retention sweep. Owned here; each module that keeps data with a retention
	 * period listens and cleans its own (Attempts, Notifications, Webhooks).
	 */
	public const RETENTION_HOOK = 'plugixa_exam_privacy_retention';

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

		// Scheduled only while a retention period is set — see sync_retention_schedule().
		add_action( 'init', array( $this, 'sync_retention_schedule' ) );

		// Suggested text for the site's privacy policy, in Settings → Privacy → Policy Guide.
		add_action( 'admin_init', array( $this, 'suggest_policy_text' ) );

		/*
		 * The search handler is registered on the filter rather than instantiated now:
		 * `WP_REST_Search_Handler` is loaded with the REST API, and naming it at
		 * `plugins_loaded` would fatal on a request that never reaches REST.
		 */
		add_filter( 'wp_rest_search_handlers', array( $this, 'search_handlers' ) );
	}

	/**
	 * Keep the retention sweep scheduled exactly while a retention period is set.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * NO NEW JOB FOR A SITE THAT NEVER TURNS RETENTION ON.
	 *
	 * Both periods default to 0 (off). A site that leaves them there gets no cron event at
	 * all — its scheduled jobs are the same after the update as before — and turning both
	 * off again removes the event rather than leaving a daily no-op behind. Checked on
	 * `init` (see Core\Cron for why not earlier), where it costs one read of the cron
	 * option WordPress has already loaded.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 */
	public function sync_retention_schedule(): void {
		if ( null === $this->settings ) {
			return;
		}

		$wanted    = $this->settings->int( 'privacy_retention_months', 0 ) > 0 || $this->settings->int( 'privacy_failed_days', 0 ) > 0;
		$scheduled = false !== wp_next_scheduled( self::RETENTION_HOOK );

		if ( $wanted && ! $scheduled ) {
			// Through Core\Cron, the one place allowed to schedule (ArchTest). Already on `init`,
			// so it schedules now rather than waiting for an action that has gone by.
			\PlugixaExam\Core\Cron::ensure( self::RETENTION_HOOK, 'daily', HOUR_IN_SECONDS );
		} elseif ( ! $wanted && $scheduled ) {
			wp_clear_scheduled_hook( self::RETENTION_HOOK );
		}
	}

	/**
	 * Offer WordPress a section for the site's privacy policy.
	 *
	 * WordPress collects these into Settings → Privacy → Policy Guide, where a site owner copies
	 * what applies into their own policy. It is a SUGGESTION — nothing here changes a page.
	 *
	 * Describes the site AS IT IS CONFIGURED. Each module that holds personal data adds its own
	 * paragraph through `plugixa_exam_privacy_policy_paragraphs`, from `register_hooks()` — so an
	 * app switched off in the App Manager is not described, and a free site is not told about
	 * LTI or webhooks it does not have. The retention sentence reads the actual period.
	 */
	public function suggest_policy_text(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) || null === $this->settings ) {
			return;
		}

		$paragraphs = array(
			__( 'We use Plugixa Exam to run online exams. For each exam you take, we keep when you started and handed it in, your answers, your score and result, and any comments a marker writes about your answers.', 'plugixa-exam' ),
			__( 'If you take an exam without an account, we also keep the name and email address you give us. We set one cookie, plugixa_exam_guest, so that your paper survives a page reload; it holds a random code and nothing about you, and lasts two days. It is set only when you press Start, never for looking at an exam.', 'plugixa-exam' ),
		);

		/**
		 * Paragraphs describing what an active module keeps, for the suggested privacy policy.
		 *
		 * @param string[] $paragraphs Plain-text paragraphs; each is escaped and wrapped in <p>.
		 */
		$paragraphs = (array) apply_filters( 'plugixa_exam_privacy_policy_paragraphs', $paragraphs );

		$months = $this->settings->int( 'privacy_retention_months', 0 );

		$paragraphs[] = $months > 0
			? sprintf(
				/* translators: %d: number of months. */
				_n(
					'Your name, email address, answers and comments are removed from a finished exam %d month after you hand it in. Your score is kept without anything that identifies you.',
					'Your name, email address, answers and comments are removed from a finished exam %d months after you hand it in. Your score is kept without anything that identifies you.',
					$months,
					'plugixa-exam'
				),
				$months
			)
			: __( 'We keep your exam records until you ask us to remove them.', 'plugixa-exam' );

		$paragraphs[] = __( 'You can ask us for a copy of the exam data we hold about you, or ask us to erase it. When we erase it, your answers, name and email address are removed; your score may be kept without anything that identifies you, so that the results of other candidates stay correct, and any certificate you were issued is withdrawn.', 'plugixa-exam' );

		$html = '';

		foreach ( $paragraphs as $paragraph ) {
			$html .= '<p>' . esc_html( (string) $paragraph ) . '</p>';
		}

		wp_add_privacy_policy_content( 'Plugixa Exam', wp_kses_post( $html ) );
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

				/*
				 * PRIVACY CHOICES, every one OFF until a site turns it on — an update must not
				 * start removing anybody's data, or change what candidates see, by itself.
				 * The exporters and erasers have no switch at all; these are only about what
				 * else happens automatically.
				 */

				// Deleting a WordPress account also erases its exam data. See PersonalData.
				'privacy_erase_on_user_delete' => false,

				// Anonymise finished attempts this many months after they were handed in. 0 = never.
				'privacy_retention_months'     => 0,

				// Delete emails and webhook deliveries that FAILED this many days ago. 0 = never.
				'privacy_failed_days'          => 0,

				// Link the site's privacy policy under the guest name-and-email form.
				'privacy_guest_policy_link'    => false,

				// Require a guest to tick "I agree" before an exam starts.
				'privacy_guest_consent'        => false,
			)
		);
	}

	/**
	 * @param array<string, mixed> $clean The whitelisted input.
	 *
	 * @return array<string, mixed>
	 */
	public function sanitize_settings( array $clean ): array {
		foreach ( array( 'search_integration', 'search_include_upcoming', 'privacy_erase_on_user_delete', 'privacy_guest_policy_link', 'privacy_guest_consent' ) as $key ) {
			if ( array_key_exists( $key, $clean ) ) {
				$clean[ $key ] = (bool) $clean[ $key ] && '0' !== $clean[ $key ];
			}
		}

		/*
		 * Whole numbers within a range a person would mean. A negative or absurd value from a
		 * hand-made request becomes "off" or the ceiling, never something that anonymises
		 * this morning's results — a retention period under a month is not one.
		 */
		$ranges = array(
			'privacy_retention_months' => 1200,
			'privacy_failed_days'      => 3650,
		);

		foreach ( $ranges as $key => $max ) {
			if ( array_key_exists( $key, $clean ) ) {
				$clean[ $key ] = min( $max, max( 0, (int) $clean[ $key ] ) );
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
				'settingsPrivacy'          => __( 'Privacy', 'plugixa-exam' ),
				'privacyAlwaysOn'          => __( 'always on — there is nothing to switch on for a personal-data request to include exam data.', 'plugixa-exam' ),
				'privacyEraseOnDelete'     => __( 'When a user account is deleted, erase its exam data too', 'plugixa-exam' ),
				'privacyGuestsTitle'       => __( 'Guests', 'plugixa-exam' ),
				'privacyGuestLink'         => __( 'Link to the privacy policy under the guest form', 'plugixa-exam' ),
				'privacyGuestLinkHelp'     => __( 'Guests are asked for a name and an email address before an exam. This links the page set in Settings → Privacy beside those fields.', 'plugixa-exam' ),
				'privacyGuestConsent'      => __( 'Ask guests to agree before they start', 'plugixa-exam' ),
				'privacyGuestConsentHelp'  => __( 'Adds a box a guest must tick. Only for sites whose legal basis is consent; most exam providers rely on running the exam the candidate asked to sit, and need no box.', 'plugixa-exam' ),
				'privacyRetentionTitle'    => __( 'Retention', 'plugixa-exam' ),
				'privacyRetentionMonths'   => __( 'Anonymise finished attempts after (months)', 'plugixa-exam' ),
				'privacyRetentionMonthsHelp' => __( 'Names, email addresses, answers and comments are removed; scores stay, so your statistics are unchanged. Certificates are kept, so they still verify. 0 means never.', 'plugixa-exam' ),
				'privacyFailedDays'        => __( 'Delete failed emails and webhook deliveries after (days)', 'plugixa-exam' ),
				'privacyFailedDaysHelp'    => __( 'Sent ones are already removed after 30 days. Failed ones are kept until this many days have passed, so you can still look into them. 0 means never.', 'plugixa-exam' ),
				'privacyRetentionWarning'  => __( 'The first run happens within an hour of saving and cannot be undone.', 'plugixa-exam' ),
				'privacyEraseOnDeleteHelp' => __( 'Runs the same erasure as Tools → Erase Personal Data: attempts are anonymised and certificates withdrawn. Skipped when you attribute the content of the account to another user. Off by default, because deleting an account used to leave its results in your reports.', 'plugixa-exam' ),
			)
		);
	}
}
