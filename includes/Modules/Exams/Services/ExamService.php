<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Exams\Services;

use PlugixaExam\Core\Settings;
use PlugixaExam\Modules\Exams\Repositories\ExamRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exam authoring: validation, defaults, composition.
 */
final class ExamService {

	/**
	 * Enumerated columns and what they accept.
	 *
	 * A table rather than a chain of `in_array` calls, because there are eleven of them and
	 * the failure mode of a missed one is a column holding a value nothing in the runner
	 * knows how to interpret — which surfaces as a paper that renders wrongly rather than an
	 * error.
	 *
	 * @var array<string, string[]>
	 */
	private const ENUMS = array(
		'access_type'           => array( 'access_level', 'open', 'guest', 'code', 'restricted' ),
		'result_release'        => array( 'immediate', 'after_close', 'manual', 'scheduled' ),
		'lock_policy'           => array( 'auto', 'cap_only', 'manual', 'never' ),
		'attempt_mode'          => array( 'exam', 'practice' ),
		'practice_reveal'       => array( 'on_submit', 'per_question' ),
		'layout'                => array( 'single_page', 'one_per_page' ),
		'fullscreen_mode'       => array( 'off', 'offered', 'required' ),
		'require_answer'        => array( 'off', 'warn', 'block' ),
		'auto_close_action'     => array( 'submit', 'abandon' ),
		'window_close_action'   => array( 'close', 'run_out' ),
		'auto_close_time_spent' => array( 'capped', 'wallclock' ),
		'auto_close_notify'     => array( 'auto', 'normal', 'silent' ),
	);

	/**
	 * @param ExamRepository $exams    The repository.
	 * @param Settings       $settings For the `default_*` seeds.
	 */
	public function __construct(
		private readonly ExamRepository $exams,
		private readonly Settings $settings
	) {}

	/**
	 * An exam with its composition, for the builder.
	 *
	 * @param int $id Exam id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$exam = $this->exams->find( $id );

		if ( null === $exam ) {
			return null;
		}

		$sections = array();

		foreach ( $this->exams->sections( $id ) as $section ) {
			$section['questions'] = 'fixed' === $section['section_type']
				? $this->exams->section_questions( (int) $section['id'] )
				: array();

			$section['rules'] = 'pool' === $section['section_type']
				? $this->exams->section_rules( (int) $section['id'] )
				: array();

			$sections[] = $section;
		}

		$exam['sections'] = $sections;

		// So the builder can show "an access code is set" without ever receiving it.
		$exam['has_access_code'] = '' !== $this->exams->access_code( $id );

		/**
		 * Decorate an exam on its way out.
		 *
		 * The twin of `plugixa_exam_question_output`, and it exists for the same reason: it
		 * is how CustomFields attaches `custom_field_values` without this module knowing
		 * that custom fields exist. The dependency runs one way only.
		 *
		 * @param array<string, mixed> $exam The exam.
		 */
		return (array) apply_filters( 'plugixa_exam_exam_output', $exam );
	}

	/**
	 * Create an exam.
	 *
	 * @param array<string, mixed> $input Raw input.
	 *
	 * @return int|WP_Error
	 */
	public function create( array $input ): int|WP_Error {
		$data = $this->sanitize( $input );

		if ( $data instanceof WP_Error ) {
			return $data;
		}

		$valid = $this->validate( $data, null );

		if ( $valid instanceof WP_Error ) {
			return $valid;
		}

		$sections = $data['__sections'];
		unset( $data['__sections'] );

		$id = $this->exams->insert( $data );

		if ( 0 === $id ) {
			return new WP_Error(
				'plugixa_exam_insert_failed',
				__( 'The exam could not be saved.', 'plugixa-exam' ),
				array( 'status' => 500 )
			);
		}

		$this->exams->replace_composition( $id, $sections );

		/**
		 * Fires after an exam is created or updated.
		 *
		 * Carries `$input` rather than the sanitised row, so a listener can read fields this
		 * module dropped — a custom field's value is not an exam column, and CustomFields
		 * needs it. The twin of `plugixa_exam_question_saved`.
		 *
		 * @param int                  $id    Exam id.
		 * @param array<string, mixed> $input The raw input.
		 */
		do_action( 'plugixa_exam_exam_saved', $id, $input );

		return $id;
	}

	/**
	 * Update an exam.
	 *
	 * ════════════════════════════════════════════════════════════════════════════════════
	 * A PARTIAL PUT MERGES OVER THE STORED ROW, AND OMITTING `sections` LEAVES THE PAPER
	 * ALONE. Both halves were absent, and the second one deleted questions.
	 *
	 * `sanitize()` builds a complete row: every field it does not find in the input gets a
	 * DEFAULT, and that default is then written. So `PUT /exams/1 {"title":"…"}` answered
	 * 200 and — measured on a real exam — reset the pass mark to 50, UNPUBLISHED the exam,
	 * blanked the intro, description and both result messages, changed `access_type`, and
	 * DELETED the section and all three of its questions. Nothing errored.
	 *
	 * It never showed up because the admin form posts every field on every save, so the only
	 * caller that could hit it is a REST client — which is precisely who API tokens exist
	 * for. `QuestionService` was already safe: it hands `$existing` to its sanitiser and
	 * answers 400 to the same request. This is that fix, and `Cohorts::update()`'s, applied
	 * to the third service that needed it.
	 *
	 * TWO SEPARATE HALVES, because a merge cannot fix the composition. A section's READ
	 * shape carries `questions` as objects and its WRITE shape wants ids, so merging the
	 * stored row would hand `replace_composition()` something it cannot read — and `find()`
	 * does not return sections at all. Absence is therefore the discriminator, tested
	 * against the ORIGINAL input.
	 * ════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param int                  $id    Exam id.
	 * @param array<string, mixed> $input Raw input.
	 *
	 * @return true|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		$existing = $this->exams->find( $id );

		if ( null === $existing ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Exam not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		/*
		 * The stored row UNDER the input, so anything sent wins and anything omitted keeps
		 * what is there.
		 *
		 * The two fields whose sanitiser tests `array_key_exists()` rather than reading a
		 * value both survive this, for DIFFERENT reasons, and both were checked:
		 *
		 *   - `settings` is merged, and the merged value is the stored value — so the
		 *     "present" branch re-encodes what was already there.
		 *   - `access_code` is NOT merged at all: `ExamRepository::cast()` unsets it on every
		 *     read, so it never enters `$existing` and the "absent means leave it alone"
		 *     branch keeps working exactly as it did. Reading a code back needs the dedicated
		 *     `access_code()` accessor, which is the point of stripping it.
		 */
		$data = $this->sanitize( array_merge( $existing, $input ), $id );

		if ( $data instanceof WP_Error ) {
			return $data;
		}

		$valid = $this->validate( $data, $existing );

		if ( $valid instanceof WP_Error ) {
			return $valid;
		}

		$sections = $data['__sections'];
		unset( $data['__sections'] );

		$this->exams->update( $id, $data );

		if ( array_key_exists( 'sections', $input ) ) {
			$this->exams->replace_composition( $id, $sections );
		}

		/**
		 * Fires after an exam is created or updated.
		 *
		 * Carries `$input` rather than the sanitised row, so a listener can read fields this
		 * module dropped — a custom field's value is not an exam column, and CustomFields
		 * needs it. The twin of `plugixa_exam_question_saved`.
		 *
		 * @param int                  $id    Exam id.
		 * @param array<string, mixed> $input The raw input.
		 */
		do_action( 'plugixa_exam_exam_saved', $id, $input );

		if ( (string) ( $existing['result_release'] ?? '' ) !== (string) $data['result_release'] ) {
			/**
			 * Fires when an exam's result release rule is changed.
			 *
			 * "Manual" release has no button of its own: results are let go by changing the
			 * rule to "immediately". That is the moment every candidate who was told nothing
			 * when their paper was marked can finally see a result, and until this action
			 * existed nothing told them (release E2E, 10 Oct 2026). Alerts listens.
			 *
			 * @param int    $id     Exam id.
			 * @param string $before The rule it had.
			 * @param string $after  The rule it has now.
			 * @param string $title  The exam's title, as it reads now.
			 */
			do_action( 'plugixa_exam_result_release_changed', $id, (string) ( $existing['result_release'] ?? '' ), (string) $data['result_release'], (string) $data['title'] );
		}

		return true;
	}

	/**
	 * Let modules refuse an exam before it is written.
	 *
	 * The row here is sanitised but NOT validated against anything a module owns: the
	 * `settings` bag keeps any scalar a caller sends, which is how a REST client came to
	 * save a link to a course that did not exist, in an LMS that was not installed. A
	 * module that stores a meaning in that bag says here whether the new value is one.
	 *
	 * @param array<string, mixed>      $data     The sanitised row about to be written.
	 * @param array<string, mixed>|null $existing The stored row, or null for a new exam.
	 *
	 * @return true|WP_Error
	 */
	private function validate( array $data, ?array $existing ): bool|WP_Error {
		/**
		 * Accept or refuse an exam about to be created or updated.
		 *
		 * Return a WP_Error (with a 400 status) to refuse the save; nothing is written.
		 * Compare against `$existing` so that a value the caller did not change — a link
		 * kept to an LMS that has since been deactivated, say — is not refused on every
		 * unrelated edit.
		 *
		 * @param true|WP_Error             $valid    True, or an earlier listener's refusal.
		 * @param array<string, mixed>      $data     The sanitised row.
		 * @param array<string, mixed>|null $existing The stored row, null on create.
		 */
		$valid = apply_filters( 'plugixa_exam_validate_exam', true, $data, $existing );

		return $valid instanceof WP_Error ? $valid : true;
	}

	/**
	 * Validate and coerce input.
	 *
	 * @param array<string, mixed> $input   Raw input.
	 * @param int                  $exclude Row being edited.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	/**
	 * A flat map of scalars, for the `settings` column.
	 *
	 * NESTING IS REFUSED RATHER THAN WALKED. A per-exam flag is a number, a string or a
	 * boolean; anything deeper is either a mistake or somebody using this column as a
	 * document store, and a recursive sanitiser here would be an invitation to do the
	 * second. Keys are `sanitize_key`'d, so a module cannot claim one that collides with a
	 * real column name by accident.
	 *
	 * @param mixed $raw Whatever the payload carried.
	 *
	 * @return array<string, scalar>
	 */
	private function scalar_bag( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}

		$out = array();

		foreach ( (array) $raw as $key => $value ) {
			$key = sanitize_key( (string) $key );

			// Fifty keys is far more than any sensible set of flags and still bounds what a
			// crafted payload can push into a `longtext` on every save.
			if ( '' === $key || ! is_scalar( $value ) || count( $out ) >= 50 ) {
				continue;
			}

			$out[ $key ] = is_string( $value )
				? mb_substr( sanitize_text_field( $value ), 0, 500 )
				: $value;
		}

		return $out;
	}

	private function sanitize( array $input, int $exclude = 0 ): array|WP_Error {
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );

		if ( '' === trim( $title ) ) {
			return new WP_Error(
				'plugixa_exam_title_required',
				__( 'An exam needs a title.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$slug = '' === $slug ? sanitize_title( $title ) : $slug;
		$slug = $this->unique_slug( '' === $slug ? 'exam' : $slug, $exclude );

		$open_from  = $this->datetime( $input['open_from'] ?? null );
		$open_until = $this->datetime( $input['open_until'] ?? null );

		if ( null !== $open_from && null !== $open_until && $open_until <= $open_from ) {
			return new WP_Error(
				'plugixa_exam_bad_window',
				__( 'The exam must close after it opens.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		$access_type = $this->enum( 'access_type', $input['access_type'] ?? null, 'access_level' );

		$data = array(
			'title'       => $title,
			'slug'        => $slug,
			'description' => sanitize_textarea_field( (string) ( $input['description'] ?? '' ) ),
			'intro'       => wp_kses( (string) ( $input['intro'] ?? '' ), plugixa_exam_allowed_html( 'stem' ) ),
			'subject_id'  => absint( $input['subject_id'] ?? 0 ),

			'time_limit'       => max( 0, absint( $input['time_limit'] ?? 0 ) ),
			'max_attempts'     => max( 0, absint( $input['max_attempts'] ?? 0 ) ),
			'cooldown_minutes' => max( 0, absint( $input['cooldown_minutes'] ?? 0 ) ),
			'open_from'        => $open_from,
			'open_until'       => $open_until,

			'pass_mark'         => max( 0.0, min( 100.0, round( (float) ( $input['pass_mark'] ?? 50 ), 2 ) ) ),
			'shuffle_questions' => $this->bool( $input['shuffle_questions'] ?? false ),
			'shuffle_options'   => $this->bool( $input['shuffle_options'] ?? false ),

			'access_type'  => $access_type,
			'access_level' => 'access_level' === $access_type
				? sanitize_key( (string) ( $input['access_level'] ?? '' ) ) ?: null
				: null,

			'result_release' => $this->enum( 'result_release', $input['result_release'] ?? null, $this->settings->str( 'result_release_default', 'immediate' ) ),
			'release_at'     => $this->datetime( $input['release_at'] ?? null ),
			'show_answers'   => $this->bool( $input['show_answers'] ?? true ),
			'pass_message'   => wp_kses( (string) ( $input['pass_message'] ?? '' ), plugixa_exam_allowed_html( 'stem' ) ),
			'fail_message'   => wp_kses( (string) ( $input['fail_message'] ?? '' ), plugixa_exam_allowed_html( 'stem' ) ),

			'lock_policy'     => $this->enum( 'lock_policy', $input['lock_policy'] ?? null, $this->settings->str( 'lock_policy_default', 'auto' ) ),
			'attempt_mode'    => $this->enum( 'attempt_mode', $input['attempt_mode'] ?? null, $this->settings->str( 'default_attempt_mode', 'exam' ) ),
			'practice_reveal' => $this->enum( 'practice_reveal', $input['practice_reveal'] ?? null, $this->settings->str( 'default_practice_reveal', 'on_submit' ) ),

			'layout'               => $this->enum( 'layout', $input['layout'] ?? null, $this->settings->str( 'default_layout', 'single_page' ) ),
			'review_before_submit' => $this->bool( $input['review_before_submit'] ?? $this->settings->bool( 'default_review_before_submit', true ) ),
			'allow_early_finish'   => $this->bool( $input['allow_early_finish'] ?? $this->settings->bool( 'default_allow_early_finish', true ) ),
			'fullscreen_mode'      => $this->enum( 'fullscreen_mode', $input['fullscreen_mode'] ?? null, $this->settings->str( 'default_fullscreen_mode', 'off' ) ),
			'require_answer'       => $this->enum( 'require_answer', $input['require_answer'] ?? null, $this->settings->str( 'default_require_answer', 'off' ) ),

			'allow_pause'       => $this->bool( $input['allow_pause'] ?? $this->settings->bool( 'default_allow_pause', false ) ),
			'max_pauses'        => max( 0, min( 100, absint( $input['max_pauses'] ?? 0 ) ) ),
			'max_pause_minutes' => max( 0, min( 1440, absint( $input['max_pause_minutes'] ?? 0 ) ) ),

			'auto_close_action'     => $this->enum( 'auto_close_action', $input['auto_close_action'] ?? null, $this->settings->str( 'default_auto_close_action', 'submit' ) ),
			'idle_timeout_minutes'  => max( 0, min( 525600, absint( $input['idle_timeout_minutes'] ?? $this->settings->int( 'default_idle_timeout_minutes', 1440 ) ) ) ),
			'window_close_action'   => $this->enum( 'window_close_action', $input['window_close_action'] ?? null, $this->settings->str( 'default_window_close_action', 'close' ) ),
			'auto_close_held'       => $this->bool( $input['auto_close_held'] ?? false ),
			'auto_close_time_spent' => $this->enum( 'auto_close_time_spent', $input['auto_close_time_spent'] ?? null, $this->settings->str( 'default_auto_close_time_spent', 'capped' ) ),
			'auto_close_notify'     => $this->enum( 'auto_close_notify', $input['auto_close_notify'] ?? null, $this->settings->str( 'default_auto_close_notify', 'auto' ) ),

			'track_integrity'         => $this->bool( $input['track_integrity'] ?? $this->settings->bool( 'default_track_integrity', true ) ),
			'certificate_template_id' => absint( $input['certificate_template_id'] ?? 0 ),
			'published'               => $this->bool( $input['published'] ?? false ),
		);

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * `settings` IS A BAG THIS SERVICE PERSISTS WITHOUT UNDERSTANDING.
		 *
		 * The column has existed since the port (§12.2 kept it) and NOTHING EVER WROTE IT.
		 * Two modules later put per-exam flags there — a gradebook weight and a link to an
		 * LMS course — and both appeared to work: the field saved, the form redrew, and the
		 * value was gone on the next load, because the API never carried it to the database
		 * at all. Nothing errored. Only opening the form again showed it.
		 *
		 * Written here rather than by each module through `plugixa_exam_exam_saved`, which
		 * is how `custom_fields` arrives: two modules read-modify-writing the same JSON
		 * column on one request is a lost update waiting for whichever runs second.
		 *
		 * Free code does not need to know what any KEY means — only that the bag holds
		 * scalars. {@see scalar_bag()} is the whole contract.
		 *
		 * ABSENT MEANS LEAVE IT ALONE, exactly as the access code below does. A partial PUT
		 * that omitted it would otherwise erase every module's flags at once.
		 * ════════════════════════════════════════════════════════════════════════════════
		 */
		if ( array_key_exists( 'settings', $input ) ) {
			$data['settings'] = (string) wp_json_encode( $this->scalar_bag( $input['settings'] ) );
		}

		/*
		 * The access code is only written when one was supplied. An empty field on an edit
		 * form means "leave it alone", not "clear it" — otherwise every save of an exam
		 * whose code the builder never receives would wipe it, and nobody could get in.
		 */
		if ( array_key_exists( 'access_code', $input ) ) {
			$code = trim( (string) $input['access_code'] );

			if ( '' !== $code ) {
				$data['access_code'] = sanitize_text_field( $code );
			} elseif ( 'code' !== $access_type ) {
				$data['access_code'] = null;
			}
		}

		$data['__sections'] = (array) ( $input['sections'] ?? array() );

		return $data;
	}

	/**
	 * A value from an enumerated set, or the fallback.
	 *
	 * @param string $column   Column name.
	 * @param mixed  $value    Supplied value.
	 * @param string $fallback Default.
	 */
	private function enum( string $column, mixed $value, string $fallback ): string {
		$allowed = self::ENUMS[ $column ] ?? array();
		$value   = is_string( $value ) ? $value : '';

		if ( in_array( $value, $allowed, true ) ) {
			return $value;
		}

		return in_array( $fallback, $allowed, true ) ? $fallback : (string) ( $allowed[0] ?? '' );
	}

	/**
	 * A boolean as the database stores it.
	 *
	 * @param mixed $value Supplied value.
	 */
	private function bool( mixed $value ): int {
		return filter_var( $value, FILTER_VALIDATE_BOOL ) ? 1 : 0;
	}

	/**
	 * A datetime normalised to UTC, or null.
	 *
	 * Stored in UTC throughout, because an exam window compared against
	 * `current_time('mysql', true)` in one place and a local string in another is how an
	 * exam opens an hour early twice a year.
	 *
	 * @param mixed $value Supplied value.
	 */
	private function datetime( mixed $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}

		$timestamp = strtotime( $value );

		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * A slug nothing else is using.
	 *
	 * @param string $slug    Base slug.
	 * @param int    $exclude Row being edited.
	 */
	private function unique_slug( string $slug, int $exclude ): string {
		$candidate = $slug;
		$suffix    = 2;

		while ( $this->exams->slug_taken( $candidate, $exclude ) ) {
			$candidate = $slug . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}
}
