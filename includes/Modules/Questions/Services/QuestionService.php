<?php
/**
 * @package PlugixaExam
 */

declare( strict_types = 1 );

namespace PlugixaExam\Modules\Questions\Services;

use PlugixaExam\Modules\Questions\Repositories\QuestionRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Question business logic: validation, saving, versioning.
 */
final class QuestionService {

	/**
	 * @param QuestionRepository $questions The repository.
	 * @param QuestionTypes      $types     The type registry.
	 */
	public function __construct(
		private readonly QuestionRepository $questions,
		private readonly QuestionTypes $types
	) {}

	/**
	 * A question with everything the editor needs.
	 *
	 * @param int $id Question id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		$question = $this->questions->find( $id );

		if ( null === $question ) {
			return null;
		}

		$question['options'] = $this->questions->options( $id );

		/**
		 * Decorate a question on its way out.
		 *
		 * How the Tags module attaches `tag_ids` without the Questions module knowing tags
		 * exist. The dependency runs one way only: nothing in this module mentions tags.
		 *
		 * @param array<string, mixed> $question The question.
		 */
		return (array) apply_filters( 'plugixa_exam_question_output', $question );
	}

	/**
	 * Create a question.
	 *
	 * @param array<string, mixed> $input Raw input.
	 *
	 * @return int|WP_Error The new id.
	 */
	public function create( array $input ): int|WP_Error {
		$data = $this->sanitize( $input );

		if ( $data instanceof WP_Error ) {
			return $data;
		}

		$options = $data['__options'];
		unset( $data['__options'] );

		$data['version'] = 1;

		$id = $this->questions->insert( $data );

		if ( 0 === $id ) {
			return new WP_Error(
				'plugixa_exam_insert_failed',
				__( 'The question could not be saved.', 'plugixa-exam' ),
				array( 'status' => 500 )
			);
		}

		$this->questions->replace_options( $id, $options );
		$this->snapshot( $id, 1 );

		/**
		 * Fires after a question is saved, with the raw input.
		 *
		 * Carries `$input` rather than the sanitised row so a listener can read fields this
		 * module dropped — `tag_ids` is not a question column, and Tags needs it.
		 *
		 * @param int                  $id    Question id.
		 * @param array<string, mixed> $input The raw input.
		 */
		do_action( 'plugixa_exam_question_saved', $id, $input );

		return $id;
	}

	/**
	 * Update a question.
	 *
	 * @param int                  $id    Question id.
	 * @param array<string, mixed> $input Raw input.
	 *
	 * @return true|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		$existing = $this->questions->find( $id );

		if ( null === $existing ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Question not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		/*
		 * ════════════════════════════════════════════════════════════════════════════════
		 * A FIELD THAT WAS NOT SENT IS A FIELD THAT STAYS AS IT IS.
		 *
		 * `sanitize()` fills every field it is not given with a default, which is right for
		 * a new question and was wrong here: an update that left `published` out wrote 0.
		 * The admin form always sends the whole question, so nothing on screen showed it. A
		 * client of the REST API renaming a question did: the question was unpublished, and
		 * an unpublished question is left off every paper built afterwards, so a one-question
		 * exam started answering "This exam has no questions yet" and a longer one became a
		 * shorter paper out of fewer marks, with no error anywhere. Found by the release
		 * E2E of 10 Oct 2026, by a check of its own that did exactly this.
		 *
		 * So what is cleaned is what is stored with what was sent laid over it, options
		 * included, as `ExamService::update()` has always done. The listeners below still
		 * get the RAW input: each of them already reads "not sent" as "leave alone".
		 * ════════════════════════════════════════════════════════════════════════════════
		 */
		$stored            = $existing;
		$stored['options'] = $this->questions->options( $id );

		$data = $this->sanitize( array_merge( $stored, $input ), $id, $existing );

		if ( $data instanceof WP_Error ) {
			return $data;
		}

		$options = $data['__options'];
		unset( $data['__options'] );

		$version         = (int) $existing['version'] + 1;
		$data['version'] = $version;

		$this->questions->update( $id, $data );
		$this->questions->replace_options( $id, $options );

		/*
		 * Snapshot AFTER the write, under the NEW version number.
		 *
		 * THE INVARIANT IS: every version that has ever existed has exactly one snapshot,
		 * holding what that version said. So `create()` snapshots v1 and each `update()`
		 * snapshots the version it just produced.
		 *
		 * Snapshotting the OUTGOING version here instead would double-record v1 — `create()`
		 * has already stored it — and leave the newest version with no snapshot at all,
		 * which is precisely the one a review screen needs while people are still sitting
		 * the exam.
		 *
		 * It has to come after `replace_options()` too: the snapshot reads the options back
		 * from the database, so running it earlier would freeze the previous answer set
		 * against the new stem.
		 */
		$this->snapshot( $id, $version );

		/** This action is documented in includes/Modules/Questions/Services/QuestionService.php */
		do_action( 'plugixa_exam_question_saved', $id, $input );

		return true;
	}

	/**
	 * Trash a question.
	 *
	 * @param int $id Question id.
	 *
	 * @return true|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		if ( null === $this->questions->find( $id ) ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Question not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		/*
		 * Trashed, not purged — and the options and versions stay with it. An exam that
		 * includes this question keeps working, and restoring it restores a working
		 * question rather than a stem with no answers. Purging is a separate, explicit act.
		 */
		$this->questions->delete( $id );

		return true;
	}

	/**
	 * Duplicate a question.
	 *
	 * @param int $id Question id.
	 *
	 * @return int|WP_Error The new id.
	 */
	public function duplicate( int $id ): int|WP_Error {
		$source = $this->find( $id );

		if ( null === $source ) {
			return new WP_Error( 'plugixa_exam_not_found', __( 'Question not found.', 'plugixa-exam' ), array( 'status' => 404 ) );
		}

		$copy = $source;

		$copy['title'] = sprintf(
			/* translators: %s: the original question's title. */
			__( '%s (copy)', 'plugixa-exam' ),
			(string) $source['title']
		);

		// A copy starts unpublished. Duplicating is how an author makes a variant, and a
		// variant that is live in the bank the instant it is created can be drawn into an
		// exam before anybody has edited it.
		$copy['published'] = false;
		unset( $copy['slug'] );

		return $this->create( $copy );
	}

	/**
	 * Validate and coerce input.
	 *
	 * @param array<string, mixed>      $input    Raw input.
	 * @param int                       $exclude  Row being edited.
	 * @param array<string, mixed>|null $existing The row being edited, if any.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private function sanitize( array $input, int $exclude = 0, ?array $existing = null ): array|WP_Error {
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );

		if ( '' === trim( $title ) ) {
			return new WP_Error(
				'plugixa_exam_title_required',
				__( 'A question needs a title for the bank list.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		$type = sanitize_key( (string) ( $input['question_type'] ?? '' ) );

		/*
		 * An unregistered type is refused on CREATE and tolerated on UPDATE.
		 *
		 * That asymmetry is the whole downgrade story: a site that drops from premium to
		 * free still has cloze questions in its bank, and editing one's title must not be
		 * refused — nor silently convert it to a type this build does understand, which
		 * would destroy the answer key.
		 */
		if ( null === $existing && ! $this->types->is_registered( $type ) ) {
			return new WP_Error(
				'plugixa_exam_unknown_type',
				__( 'That question type is not available on this site.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		if ( null !== $existing && ! $this->types->is_registered( $type ) ) {
			$type = (string) $existing['question_type'];
		}

		$slug = sanitize_title( (string) ( $input['slug'] ?? '' ) );
		$slug = '' === $slug ? sanitize_title( $title ) : $slug;
		$slug = $this->unique_slug( '' === $slug ? 'question' : $slug, $exclude );

		$options = $this->sanitize_options( $type, (array) ( $input['options'] ?? array() ) );

		if ( $options instanceof WP_Error ) {
			return $options;
		}

		$answer_key = $this->types->normalize( $type, (array) ( $input['answer_key'] ?? array() ) );

		$validation = $this->validate( $type, $options, $answer_key );

		if ( $validation instanceof WP_Error ) {
			return $validation;
		}

		$difficulty = (int) ( $input['difficulty'] ?? 1 );

		return array(
			'title'           => $title,
			'slug'            => $slug,
			'question_type'   => $type,
			'subject_id'      => absint( $input['subject_id'] ?? 0 ),
			// Authored HTML. `wp_kses` with this plugin's own allow-list, which is narrower
			// than `wp_kses_post`: no `video`, `audio`, `object`, `button` or `textarea`,
			// because embedded media in a question stem is a channel to the open web during
			// a closed-book assessment and a form control collides with the runner's own
			// inputs. `SanitisationTest` fails if this becomes `wp_kses_post`.
			'stem'            => wp_kses( (string) ( $input['stem'] ?? '' ), plugixa_exam_allowed_html( 'stem' ) ),
			'explanation'     => wp_kses( (string) ( $input['explanation'] ?? '' ), plugixa_exam_allowed_html( 'stem' ) ),
			'difficulty'      => max( 1, min( 5, $difficulty ) ),
			'default_points'  => max( 0.0, round( (float) ( $input['default_points'] ?? 1 ), 2 ) ),
			'answer_key'      => (string) wp_json_encode( $answer_key ),
			'shuffle_options' => isset( $input['shuffle_options'] ) && empty( $input['shuffle_options'] ) ? 0 : 1,
			'published'       => empty( $input['published'] ) ? 0 : 1,
			'approval_status' => in_array( $input['approval_status'] ?? '', array( 'draft', 'approved' ), true )
				? (string) $input['approval_status']
				: 'approved',
			'rubric'          => $this->sanitize_rubric( $input['rubric'] ?? null ),
			'render_hint'     => isset( $input['render_hint'] ) && is_array( $input['render_hint'] )
				? (string) wp_json_encode( array_map( 'sanitize_text_field', $input['render_hint'] ) )
				: null,
			'__options'       => $options,
		);
	}

	/**
	 * Clean an options array.
	 *
	 * @param string                          $type    Question type.
	 * @param array<int, array<string, mixed>> $options Raw options.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function sanitize_options( string $type, array $options ): array|WP_Error {
		if ( 'none' === $this->types->options_mode( $type ) ) {
			return array();
		}

		$clean = array();

		foreach ( $options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$text = wp_kses( (string) ( $option['option_text'] ?? '' ), plugixa_exam_allowed_html( 'stem' ) );

			// A blank option is a row the examinee cannot tell apart from a rendering bug.
			// Dropped rather than rejected, so a stray empty row left by the editor does not
			// block a save.
			if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
				continue;
			}

			$clean[] = array(
				// Carried so the repository can update the option in place and keep its id —
				// see QuestionRepository::replace_options(). It decides whether the id is
				// really this question's; here it is only a number.
				'id'          => absint( $option['id'] ?? 0 ),
				'option_text' => $text,
				'is_correct'  => ! empty( $option['is_correct'] ),
				'match_key'   => sanitize_text_field( (string) ( $option['match_key'] ?? '' ) ),
				'feedback'    => sanitize_text_field( (string) ( $option['feedback'] ?? '' ) ),
			);
		}

		if ( 'required' === $this->types->options_mode( $type ) && count( $clean ) < 2 ) {
			return new WP_Error(
				'plugixa_exam_needs_options',
				__( 'This question type needs at least two answer options.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		return $clean;
	}

	/**
	 * Per-type checks that only make sense once options and key are both known.
	 *
	 * These run at authoring time on purpose. Every one of them describes a question that
	 * would grade wrongly rather than error — a single-answer question with no correct
	 * option marks every examinee wrong, and nothing anywhere reports a problem.
	 *
	 * @param string                          $type       Question type.
	 * @param array<int, array<string, mixed>> $options    Clean options.
	 * @param array<string, mixed>            $answer_key Normalised key.
	 *
	 * @return true|WP_Error
	 */
	private function validate( string $type, array $options, array $answer_key ): bool|WP_Error {
		$correct = count( array_filter( $options, static fn( array $o ): bool => ! empty( $o['is_correct'] ) ) );

		if ( 'mcq_single' === $type && 1 !== $correct ) {
			return new WP_Error(
				'plugixa_exam_needs_one_correct',
				__( 'A single-answer question needs exactly one correct option.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		if ( 'mcq_multiple' === $type && $correct < 1 ) {
			return new WP_Error(
				'plugixa_exam_needs_correct',
				__( 'Mark at least one option as correct.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		if ( 'short_answer' === $type && array() === ( $answer_key['accepted'] ?? array() ) ) {
			return new WP_Error(
				'plugixa_exam_needs_accepted',
				__( 'Add at least one accepted answer.', 'plugixa-exam' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Check a type this build does not know natively.
		 *
		 * The premium types need exactly the same treatment as the five above, and for the
		 * same reason: an extended-matching stem pointing at an option that was since
		 * deleted grades every examinee wrong on that stem, silently, for ever. Catching it
		 * here means the author finds out while they still have the question open.
		 *
		 * Return `true` to accept, or a `WP_Error` to refuse the save.
		 *
		 * @param bool|WP_Error                   $valid      Current verdict.
		 * @param string                          $type       Question type.
		 * @param array<int, array<string, mixed>> $options   Clean options.
		 * @param array<string, mixed>            $answer_key Normalised key.
		 */
		$valid = apply_filters( 'plugixa_exam_validate_question', true, $type, $options, $answer_key );

		return $valid instanceof WP_Error ? $valid : true;
	}

	/**
	 * Clean a rubric.
	 *
	 * @param mixed $rubric Raw rubric.
	 */
	private function sanitize_rubric( mixed $rubric ): ?string {
		if ( ! is_array( $rubric ) || array() === $rubric ) {
			return null;
		}

		$clean = array();

		foreach ( $rubric as $criterion ) {
			if ( ! is_array( $criterion ) ) {
				continue;
			}

			$label = sanitize_text_field( (string) ( $criterion['label'] ?? '' ) );

			if ( '' === trim( $label ) ) {
				continue;
			}

			$clean[] = array(
				'key'         => sanitize_key( (string) ( $criterion['key'] ?? $label ) ),
				'label'       => $label,
				'max_points'  => max( 0.0, round( (float) ( $criterion['max_points'] ?? 0 ), 2 ) ),
				'description' => sanitize_text_field( (string) ( $criterion['description'] ?? '' ) ),
			);
		}

		return array() === $clean ? null : (string) wp_json_encode( $clean );
	}

	/**
	 * Freeze a question's current content under a version number.
	 *
	 * @param int $question_id Question id.
	 * @param int $version     Version being frozen.
	 */
	private function snapshot( int $question_id, int $version ): void {
		$question = $this->questions->find( $question_id );

		if ( null === $question ) {
			return;
		}

		$this->questions->add_version(
			$question_id,
			$version,
			array(
				'title'       => $question['title'],
				'stem'        => $question['stem'],
				'explanation' => $question['explanation'],
				'answer_key'  => $question['answer_key'],
				'rubric'      => $question['rubric'],
				'options'     => $this->questions->options( $question_id ),
			)
		);
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

		while ( $this->questions->slug_taken( $candidate, $exclude ) ) {
			$candidate = $slug . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}
}
