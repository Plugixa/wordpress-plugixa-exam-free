<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Notifications\Repositories;

use PlugixaExam\Core\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The editable email templates.
 */
final class TemplateRepository extends Repository {

	public function table(): string {
		// The BARE name. `Repository::full_table()` is what applies the prefix — returning a
		// prefixed name here yields `wp_plugixa_exam_wp_plugixa_exam_…`.
		return 'email_templates';
	}

	public function entity_type(): string {
		return 'email_template';
	}

	/**
	 * @return string[]
	 */
	public function fillable(): array {
		return array(
			'event_key', 'title', 'subject', 'body', 'content_type',
			'from_name', 'from_email', 'recipient', 'enabled',
		);
	}

	/**
	 * @return string[]
	 */
	public function sortable(): array {
		return array( 'id', 'event_key', 'title' );
	}

	/**
	 * A template by the event it answers to.
	 *
	 * @param string $event_key Event key.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_event( string $event_key ): ?array {
		$rows = $this->query()->where( 't.event_key = %s', array( $event_key ) )->paginate( 1 )->get();

		return $rows[0] ?? null;
	}

	/**
	 * Write the templates a fresh install starts with.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * SEEDS ONCE PER EVENT, AND NEVER OVERWRITES.
	 *
	 * This runs on every activation and every migration, because a module added later must
	 * be able to seed its own templates. So it inserts only what is missing. An administrator
	 * who rewrote the wording of "your result is ready" keeps that wording across upgrades —
	 * having the plugin quietly restore its own prose over somebody's would be worse than
	 * shipping no default at all.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 */
	public function seed(): void {
		foreach ( self::defaults() as $event_key => $template ) {
			if ( null !== $this->find_by_event( $event_key ) ) {
				continue;
			}

			$this->insert( array( 'event_key' => $event_key ) + $template );
		}
	}

	/**
	 * The templates this build ships.
	 *
	 * Only events that exist in the free build are here. A premium module contributes its own
	 * through `plugixa_exam_email_templates` — which is why Certificates and Monitoring
	 * templates are absent rather than present-and-unused. Seeding a template for an event
	 * nothing fires leaves an administrator editing an email that will never be sent.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function defaults(): array {
		$templates = array(
			'attempt_submitted'       => array(
				'title'     => 'Submission received',
				'recipient' => 'examinee',
				'subject'   => 'Your {exam_title} submission has been received',
				/*
				 * No score token here, deliberately. This goes out the moment a paper is
				 * submitted — before anything is released and before a marker has seen an
				 * essay. `Tokens` would blank a `{percent}` anyway, and an email reading
				 * "You scored" followed by nothing is worse than one that does not ask.
				 */
				'body'      => "Hello {name},\n\nWe have received your submission for {exam_title}.\n\nYou can check for your result here:\n{result_url}\n\n{site_name}",
			),
			'attempt_graded'          => array(
				'title'     => 'Result ready',
				'recipient' => 'examinee',
				'subject'   => 'Your result for {exam_title}',
				'body'      => "Hello {name},\n\nYour paper for {exam_title} has been marked.\n\nResult: {outcome}\nScore: {score} of {max_score} ({percent}%)\n\nSee the full paper here:\n{result_url}\n\n{site_name}",
			),
			'attempt_auto_closed'     => array(
				'title'     => 'Attempt closed automatically',
				'recipient' => 'examinee',
				'subject'   => 'Your {exam_title} attempt was closed',
				'body'      => "Hello {name},\n\nYour attempt at {exam_title} was closed automatically, either because the time ran out or because the exam window closed.\n\nWhatever you had answered has been kept and marked.\n\n{result_url}\n\n{site_name}",
			),
			'attempt_submitted_admin' => array(
				'title'     => 'Submission received (staff)',
				'recipient' => 'admin',
				'subject'   => '{name} submitted {exam_title}',
				'body'      => "{name} has submitted {exam_title}.\n\nSubmitted: {submitted}\nScore: {score} of {max_score} ({percent}%)\n\n{result_url}",
				// Off by default. On a site running a hundred exams a day this is a hundred
				// emails a day to the administrator, and nobody asked for them.
				'enabled'   => false,
			),
			'needs_grading'           => array(
				'title'     => 'A paper needs marking',
				'recipient' => 'admin',
				'subject'   => 'A {exam_title} paper is waiting to be marked',
				'body'      => "{name} submitted {exam_title} and it contains answers that need marking by hand.\n\nSubmitted: {submitted}\n\n{result_url}",
			),
		);

		/**
		 * Filter the email templates a site seeds.
		 *
		 * How a module contributes its own. Keyed by `event_key`; an entry whose key already
		 * exists in the database is ignored, so this cannot overwrite an edited template.
		 *
		 * @param array<string, array<string, mixed>> $templates Templates by event key.
		 */
		return (array) apply_filters( 'plugixa_exam_email_templates', $templates );
	}

	/**
	 * English => translated, for the ADMIN-FACING NAME of a seeded template.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * WHY THE TITLE AND NOT THE SUBJECT OR THE BODY.
	 *
	 * A title labels a row on the Notifications screen: one reader, whoever has the screen
	 * open, so translating it on read gives them their own language and is plainly right.
	 *
	 * A subject and a body are different in kind. They are SENT, and the person they are
	 * sent to is not the person reading the screen — a French administrator's site can have
	 * German candidates. One database row cannot be right for both, so translating it on
	 * read would only move the wrongness around: the reader would see their language and
	 * the recipient would still receive whatever single language the row holds.
	 *
	 * Per-locale email content is a translation OF A ROW, which is the Multilingual
	 * module's job (`email_template` is one of the entities it overlays) — not something a
	 * gettext catalogue can express. Until then a seeded email goes out in English, which
	 * is what happened before this change too: `__()` at `plugins_loaded` returned English
	 * because WordPress will not load a textdomain that early. The difference is that it no
	 * longer raises `_load_textdomain_just_in_time was called incorrectly` on the way.
	 *
	 * The subjects and bodies therefore leave the `.pot`, deliberately. A msgid nobody can
	 * apply is not a translation opportunity, it is work handed to a volunteer and thrown
	 * away.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		$labels = array(
			'Submission received'         => __( 'Submission received', 'plugixa-exam' ),
			'Result ready'                => __( 'Result ready', 'plugixa-exam' ),
			'Attempt closed automatically' => __( 'Attempt closed automatically', 'plugixa-exam' ),
			'Submission received (staff)' => __( 'Submission received (staff)', 'plugixa-exam' ),
			'A paper needs marking'       => __( 'A paper needs marking', 'plugixa-exam' ),
		);

		/**
		 * Translations for the names of templates a MODULE seeded.
		 *
		 * ────────────────────────────────────────────────────────────────────────────────
		 * THE TWIN OF `plugixa_exam_email_templates`, AND IT HAS TO EXIST FOR THE SAME
		 * REASON THAT ONE DOES.
		 *
		 * A module contributes its template through that filter, so its title never appears
		 * in the array above — and a free build must not carry the strings of screens it
		 * does not have. Listing a premium template's name here would put it in the free
		 * `.pot`, which is precisely the Guideline 5 signal the two-catalogue split exists
		 * to avoid. So the contributor supplies its own label, in its own catalogue.
		 *
		 * The map is `English => __( same English )`; see `plugixa_exam_translate_seeded()`
		 * for why the stored value is its own key, and why a title the site owner has
		 * renamed passes through untouched.
		 * ────────────────────────────────────────────────────────────────────────────────
		 *
		 * @param array<string, string> $labels English => translated.
		 */
		return (array) apply_filters( 'plugixa_exam_seeded_template_labels', $labels );
	}

	/**
	 * @param array<string, mixed> $row Raw row.
	 *
	 * @return array<string, mixed>
	 */
	public function cast( array $row ): array {
		$row = parent::cast( $row );

		if ( isset( $row['title'] ) && is_string( $row['title'] ) ) {
			$row['title'] = plugixa_exam_translate_seeded( $row['title'], self::labels() );
		}

		return $row;
	}
}
